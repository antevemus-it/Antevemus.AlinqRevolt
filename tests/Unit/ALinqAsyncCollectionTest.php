<?php

declare(strict_types=1);

namespace Antevemus\ALinqRevolt\Tests\Unit;

use Amp\Cancellation;
use Amp\CancelledException;
use Amp\Future;
use Amp\TimeoutException;
use Antevemus\ALinq\ALinqCollection;
use Antevemus\ALinq\ALinqLazyCollection;
use Antevemus\ALinq\Interfaces\IALinqLazyCollection;
use Antevemus\ALinqRevolt\ALinqAsyncCollection;
use Antevemus\ALinqRevolt\ALinqAsyncException;
use Antevemus\ALinqRevolt\RevoltRunner;
use ArrayIterator;
use Generator;
use InvalidArgumentException;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use stdClass;
use UnexpectedValueException;

use function Amp\async;
use function Amp\delay;

/**
 * ALinqAsyncCollection: values, keys and order of the four operators; the lazy window (the source pulled on
 * demand, never more than `concurrency` items started and not delivered, early stop cancels what is in
 * flight); errors and time limits at the item's position; the argument checks; the sources accepted.
 *
 * @version    1.0.0
 * @package    Antevemus\ALinqRevolt\Tests
 * @subpackage Unit
 */
final class ALinqAsyncCollectionTest extends TestCase
{
    // Values, keys and order ------------------------------------------------------------------------

    public function testSelectAsyncKeepsTheSourceOrderWhateverFinishesFirst(): void
    {
        $finished = [];
        $result = ALinqAsyncCollection::from([30, 10, 20, 0])
            ->selectAsync(static function (int $ms) use (&$finished): int {
                delay($ms / 1000);
                $finished[] = $ms;
                return $ms * 2;
            }, 4)
            ->toArray();

        $this->assertSame([60, 20, 40, 0], $result);
        $this->assertSame([0, 10, 20, 30], $finished, 'they finished in another order');
    }

    public function testSelectAsyncReturnsACoreLazyCollectionThatChains(): void
    {
        $stage = ALinqAsyncCollection::from([3, 1, 2])->selectAsync(static fn(int $x): int => $x * 10);

        $this->assertInstanceOf(ALinqLazyCollection::class, $stage);
        $this->assertSame([30, 20], $stage->where(static fn(int $x): bool => $x > 10)->orderByDescending(static fn(int $x): int => $x)->toArray());
        $this->assertSame(60, $stage->sum());
    }

    public function testSelectAsyncPassesTheKeyByTheCoreArityRule(): void
    {
        $source = ['a' => 1, 'b' => 2];
        $this->assertSame(['a' => 'a1', 'b' => 'b2'], ALinqAsyncCollection::from($source)->selectAsync(static fn(int $v, string $k): string => $k . $v)->toArray());
        $this->assertSame(['a' => '1', 'b' => '2'], ALinqAsyncCollection::from($source)->selectAsync('strval')->toArray(), 'an internal function receives the item alone');
        $this->assertSame(['a' => 2, 'b' => 3], ALinqAsyncCollection::from($source)->selectAsync(static fn(int $v): int => $v + 1)->toArray());
    }

    /** @return array<string, array{\Closure(): iterable<mixed, mixed>}> */
    public static function keyedSources(): array
    {
        return [
            'list' => [static fn(): array => [10, 20, 30]],
            'string keys' => [static fn(): array => ['x' => 10, 'y' => 20, 'z' => 30]],
            'mixed keys' => [static fn(): array => [7 => 10, 'y' => 20, 0 => 30]],
            'generator with repeated keys' => [static function (): Generator {
                yield 'k' => 10;
                yield 'k' => 20;
                yield 3 => 30;
            }],
        ];
    }

    /** @param \Closure(): iterable<mixed, mixed> $source */
    #[DataProvider('keyedSources')]
    public function testSelectAsyncStreamsTheKeysOfTheCoreSelect(\Closure $source): void
    {
        $pairs = static function (iterable $stage): array {
            $out = [];
            foreach ($stage as $key => $value) {
                $out[] = [$key, $value];
            }
            return $out;
        };
        $f = static fn(int $v, int|string $k): string => "$k:$v";

        $this->assertSame(
            $pairs(ALinqLazyCollection::from($source())->select($f)),
            $pairs(ALinqAsyncCollection::from($source())->selectAsync($f, 2))
        );
    }

    public function testToArrayKeepsTheShapeOfTheSourceAsTheCoreDoes(): void
    {
        $f = static fn(string $v): string => strtoupper($v);
        $this->assertSame(['A', 'B'], ALinqAsyncCollection::from(['a', 'b'])->selectAsync($f)->toArray(), 'a list stays a list');
        $this->assertSame(['x' => 'A', 'y' => 'B'], ALinqAsyncCollection::from(['x' => 'a', 'y' => 'b'])->selectAsync($f)->toArray(), 'string keys stay');

        // an integer-keyed dictionary keeps its keys (the stage declares the shape: ALinq 2.3.0 from($factory, false))
        $byId = [5 => 'a', 9 => 'b'];
        $this->assertSame([5 => 'A', 9 => 'B'], ALinqAsyncCollection::from($byId)->selectAsync($f)->toArray());
        $this->assertSame(ALinqLazyCollection::from($byId)->select($f)->toArray(), ALinqAsyncCollection::from($byId)->selectAsync($f)->toArray(), 'as the core select() over the array');
        $this->assertSame([5 => 'A', 9 => 'B'], ALinqAsyncCollection::from(ALinqCollection::from($byId))->selectAsync($f)->toArray(), 'an eager dictionary too');
        $this->assertSame([5 => 'a'], ALinqAsyncCollection::from($byId)->whereAsync(static fn(string $v): bool => $v === 'a')->toArray(), 'whereAsync keeps them');
        $this->assertSame([5 => 'A', 9 => 'B'], ALinqAsyncCollection::from($byId)->selectAsync($f)->where(static fn(): bool => true)->toArray(), 'and the core operators downstream read the declared shape');
        $this->assertSame(['a', 'b'], ALinqAsyncCollection::from($byId)->selectManyAsync(static fn(string $v): array => [$v])->toArray(), 'selectManyAsync yields a list');
        $this->assertSame(
            ALinqLazyCollection::from($byId)->selectMany(static fn(string $v): array => [$v, $v])->where(static fn(string $v, int $k): bool => $k % 2 === 1)->toArray(),
            ALinqAsyncCollection::from($byId)->selectManyAsync(static fn(string $v): array => [$v, $v])->where(static fn(string $v, int $k): bool => $k % 2 === 1)->toArray(),
            'a list for the core operators downstream too: where() renumbers'
        );
        $this->assertSame(['a', 'b'], ALinqAsyncCollection::from($byId)->selectManyAsync(static fn(string $v): array => [$v, $v])->where(static fn(string $v, int $k): bool => $k % 2 === 1)->toArray());

        // a source whose shape the core does not expose has the unknown shape here as in the core's own stages
        $generator = static function (): Generator {
            yield 5 => 'a';
            yield 9 => 'b';
        };
        $this->assertSame(ALinqLazyCollection::from($generator())->select($f)->toArray(), ALinqAsyncCollection::from($generator())->selectAsync($f)->toArray(), 'a Generator: the core answer, a list');
        $this->assertSame(['A', 'B'], ALinqAsyncCollection::from($generator())->selectAsync($f)->toArray());
        // a lazy ALinq source has the shape the core knows (its isList(), ALinq 2.3.0)
        $lazyDictionary = ALinqLazyCollection::from($byId);
        $this->assertSame([5 => 'A', 9 => 'B'], ALinqAsyncCollection::from($lazyDictionary)->selectAsync($f)->toArray(), 'a lazy dictionary keeps its keys');
        $this->assertSame($lazyDictionary->select($f)->toArray(), ALinqAsyncCollection::from($lazyDictionary)->selectAsync($f)->toArray());
        $this->assertSame([5 => 'a'], ALinqAsyncCollection::from($lazyDictionary->where(static fn(): bool => true))->whereAsync(static fn(string $v): bool => $v === 'a')->toArray(), 'a lazy stage of a dictionary too');
        $this->assertSame(['A', 'B'], ALinqAsyncCollection::from(ALinqLazyCollection::from($generator))->selectAsync($f)->toArray(), 'an undeclared lazy factory: unknown, as in the core');
        $declaredList = ALinqLazyCollection::from($generator, true); // keys 5 and 9, declared a list
        $this->assertSame(iterator_to_array($declaredList->select($f)), iterator_to_array(ALinqAsyncCollection::from($declaredList)->selectAsync($f)), 'a declared list: renumbered while streaming, as the core select()');
        $this->assertSame([0 => 'b'], iterator_to_array(ALinqAsyncCollection::from($declaredList)->whereAsync(static fn(string $v): bool => $v === 'b')), 'and whereAsync renumbers it');
        $this->assertSame([9 => 'B'], ALinqAsyncCollection::from(ALinqLazyCollection::from($generator, false))->selectAsync($f)->where(static fn(string $v): bool => $v === 'B')->toArray(), 'a declared dictionary keeps its keys');
    }

    public function testWhereAsyncRenumbersAKnownListAndKeepsOtherKeys(): void
    {
        $odd = static function (int $v): bool {
            delay(0.001);
            return $v % 2 === 1;
        };
        $this->assertSame([0 => 1, 1 => 3], iterator_to_array(ALinqAsyncCollection::from([1, 2, 3, 4])->whereAsync($odd)));
        $this->assertSame([0 => 1, 1 => 3], iterator_to_array(ALinqAsyncCollection::from(ALinqCollection::from([1, 2, 3, 4]))->whereAsync($odd)), 'an eager list collection is a known list');
        $this->assertSame(['a' => 1, 'c' => 3], ALinqAsyncCollection::from(['a' => 1, 'b' => 2, 'c' => 3])->whereAsync($odd)->toArray());
        $this->assertSame([0 => 1, 2 => 3], iterator_to_array(ALinqAsyncCollection::from(new ArrayIterator([1, 2, 3, 4]))->whereAsync($odd)), 'a Traversable has an unknown shape: the source keys, as the core where() streams them');
        $this->assertSame([1, 3], ALinqAsyncCollection::from(new ArrayIterator([1, 2, 3, 4]))->whereAsync($odd)->toArray());
        $this->assertSame(iterator_to_array(ALinqLazyCollection::from([1, 2, 3, 4])->where($odd)), iterator_to_array(ALinqAsyncCollection::from([1, 2, 3, 4])->whereAsync($odd)));
    }

    public function testWhereAsyncKeepsTruthyAnswers(): void
    {
        $answers = [1, 0, 'yes', '', [0], [], null, 0.5];
        $this->assertSame([1, 'yes', [0], 0.5], ALinqAsyncCollection::from($answers)->whereAsync(static fn(mixed $v): mixed => $v)->toArray());
    }

    public function testSelectManyAsyncFlattensInOrderIntoAList(): void
    {
        $result = ALinqAsyncCollection::from(['a' => 3, 'b' => 0, 'c' => 2])
            ->selectManyAsync(static function (int $n, string $k): Generator {
                delay((3 - $n) / 1000);
                for ($i = 1; $i <= $n; $i++) {
                    yield "$k$i";
                }
            }, 3);
        $this->assertSame(['a1', 'a2', 'a3', 'c1', 'c2'], iterator_to_array($result));
        $this->assertSame(
            ALinqLazyCollection::from(['a' => [1, 2], 'b' => new ArrayIterator(['x' => 3])])->selectMany(static fn(iterable $v): iterable => $v)->toArray(),
            ALinqAsyncCollection::from(['a' => [1, 2], 'b' => new ArrayIterator(['x' => 3])])->selectManyAsync(static fn(iterable $v): iterable => $v)->toArray()
        );
    }

    public function testSelectManyAsyncRefusesANonIterableAtItsPosition(): void
    {
        $delivered = [];
        try {
            foreach (ALinqAsyncCollection::from(['a' => [1], 'b' => 'oops', 'c' => [3]])->selectManyAsync(static fn(mixed $v): mixed => $v) as $item) {
                $delivered[] = $item;
            }
            $this->fail('no exception');
        } catch (ALinqAsyncException $e) {
            $this->assertSame([1], $delivered);
            $this->assertSame('b', $e->getKey());
            $this->assertSame(1, $e->getPosition());
            $this->assertFalse($e->isTimeout());
            $this->assertInstanceOf(UnexpectedValueException::class, $e->getPrevious());
            $this->assertSame("selectMany() expects the selector to return an iterable, string returned for key 'b'", $e->getPrevious()->getMessage());
            $this->assertStringNotContainsString('oops', $e->getMessage());
        }
    }

    public function testSelectManyAsyncNamesAKeyThatIsNotIntOrStringByItsType(): void
    {
        $source = (static function (): Generator {
            yield 7 => [1];
            yield 3.5 => 'not iterable';
        })();
        try {
            ALinqAsyncCollection::from($source)->selectManyAsync(static fn(mixed $v): mixed => $v)->toArray();
            $this->fail('no exception');
        } catch (ALinqAsyncException $e) {
            $this->assertSame('selectMany() expects the selector to return an iterable, string returned for key float', $e->getPrevious()?->getMessage());
        }
        try {
            ALinqAsyncCollection::from([5 => 'x'])->selectManyAsync(static fn(mixed $v): mixed => $v)->toArray();
            $this->fail('no exception');
        } catch (ALinqAsyncException $e) {
            $this->assertSame('selectMany() expects the selector to return an iterable, string returned for key 5', $e->getPrevious()?->getMessage());
        }
    }

    public function testEachAsyncRunsEveryActionAndReturnsWhenAllFinished(): void
    {
        $done = [];
        $result = ALinqAsyncCollection::from(['a' => 3, 'b' => 1, 'c' => 2])->eachAsync(static function (int $ms, string $k) use (&$done): void {
            delay($ms / 1000);
            $done[$k] = $ms;
        }, 2);

        $this->assertNull($result);
        ksort($done);
        $this->assertSame(['a' => 3, 'b' => 1, 'c' => 2], $done);
    }

    public function testEachAsyncThrowsTheFirstFailureInSourceOrder(): void
    {
        try {
            ALinqAsyncCollection::from([1, 2, 3])->eachAsync(static function (int $v): void {
                delay((4 - $v) / 1000);
                if ($v >= 2) {
                    throw new RuntimeException("failed $v");
                }
            }, 3);
            $this->fail('no exception');
        } catch (ALinqAsyncException $e) {
            $this->assertSame('eachAsync', $e->getOperator());
            $this->assertSame(1, $e->getPosition(), 'item 3 failed first in time, item 2 first in order');
            $this->assertSame('failed 2', $e->getPrevious()?->getMessage());
        }
    }

    public function testAnEmptySourceGivesAnEmptyStageAndRunsNothing(): void
    {
        $calls = 0;
        $count = static function () use (&$calls): int {
            return ++$calls;
        };
        $this->assertSame([], ALinqAsyncCollection::from([])->selectAsync($count)->toArray());
        $this->assertSame([], ALinqAsyncCollection::from(ALinqLazyCollection::empty())->whereAsync($count)->toArray());
        $this->assertSame([], ALinqAsyncCollection::from(new ArrayIterator([]))->selectManyAsync($count)->toArray());
        ALinqAsyncCollection::from([])->eachAsync($count);
        $this->assertSame(0, $calls);
    }

    public function testAnyCallableIsAccepted(): void
    {
        $invokable = new class {
            public function __invoke(int $v): int
            {
                return $v + 100;
            }

            public function triple(int $v, int $k): int
            {
                return $v * 3 + $k;
            }
        };
        $this->assertSame([101, 102], ALinqAsyncCollection::from([1, 2])->selectAsync($invokable)->toArray());
        $this->assertSame([3, 7], ALinqAsyncCollection::from([1, 2])->selectAsync([$invokable, 'triple'])->toArray());
        $this->assertSame(['1', '2'], ALinqAsyncCollection::from([1, 2])->selectAsync('strval')->toArray());
        $this->assertSame([2, 3], ALinqAsyncCollection::from([1, 2])->selectAsync(self::addOne(...))->toArray());
    }

    private static function addOne(int $v): int
    {
        return $v + 1;
    }

    public function testAOneParameterClosureReceivesTheItemAloneAndATwoParameterOneItemAndKey(): void
    {
        $this->assertSame([1, 1], ALinqAsyncCollection::from(['a', 'b'])->selectAsync(static fn(string $v): int => func_num_args())->toArray(), 'the core arity rule: one parameter, the item alone');
        $this->assertSame([2, 2], ALinqAsyncCollection::from(['a', 'b'])->selectAsync(static fn(string $v, int $k): int => func_num_args())->toArray());
        $this->assertSame([3, 3], ALinqAsyncCollection::from(['a', 'b'])->selectAsync(static fn(string $v, int $k, Cancellation $c): int => func_num_args())->toArray());
    }

    public function testTheClosureMayReturnAFuture(): void
    {
        $this->assertSame([2, 4], ALinqAsyncCollection::from([1, 2])->selectAsync(static fn(int $v): Future => async(static function () use ($v): int {
            delay(0.001);
            return $v * 2;
        }))->toArray());
        $this->assertSame([2], ALinqAsyncCollection::from([1, 2])->whereAsync(static fn(int $v): Future => Future::complete($v === 2))->toArray());
    }

    public function testAThirdParameterReceivesTheCancellationOfTheItem(): void
    {
        $received = [];
        ALinqAsyncCollection::from(['a', 'b'])->selectAsync(static function (string $v, int $k, Cancellation $cancellation) use (&$received): string {
            $received[] = $cancellation;
            return $v;
        })->toArray();
        $this->assertCount(2, $received);
        $this->assertContainsOnlyInstancesOf(Cancellation::class, $received);

        $variadic = ALinqAsyncCollection::from(['a'])->selectAsync(static fn(mixed ...$args): int => count($args))->toArray();
        $this->assertSame([3], $variadic, 'a variadic user closure receives item, key and cancellation');
        $this->assertSame([1], ALinqAsyncCollection::from(['abc'])->selectAsync('str_word_count')->toArray(), 'an internal function never receives the cancellation');
    }

    public function testTheSourceKindsAreAcceptedAndGiveTheCoreBack(): void
    {
        $eager = ALinqCollection::from(['a' => 1]);
        $lazy = ALinqLazyCollection::from([1, 2]);

        $this->assertSame($eager, ALinqAsyncCollection::from($eager)->collection());
        $this->assertSame($lazy, ALinqAsyncCollection::from($lazy)->lazy());
        $this->assertSame(['a' => 1], ALinqAsyncCollection::from($eager)->lazy()->toArray());
        $this->assertSame([1, 2], ALinqAsyncCollection::from($lazy)->collection()->toArray());
        $this->assertSame([1, 2], ALinqAsyncCollection::from([1, 2])->lazy()->toArray());
        $this->assertSame(['k' => 1], ALinqAsyncCollection::from(new ArrayIterator(['k' => 1]))->collection()->toArray());
        $this->assertSame([2, 4], ALinqAsyncCollection::from($lazy)->selectAsync(static fn(int $v): int => $v * 2)->toArray());
        $this->assertSame(['a' => 2], ALinqAsyncCollection::from($eager)->selectAsync(static fn(int $v): int => $v * 2)->toArray());
        $this->assertSame([5 => 'x', 9 => 'y'], ALinqAsyncCollection::from(ALinqCollection::from([5 => 'x', 9 => 'y']))->lazy()->toArray(), "lazy() of an eager collection is its own lazy(), which knows the shape");
    }

    public function testEveryTraversalRunsTheClosuresAgainAndAGeneratorIsReadOnce(): void
    {
        $calls = 0;
        $stage = ALinqAsyncCollection::from([1, 2])->selectAsync(static function (int $v) use (&$calls): int {
            $calls++;
            return $v;
        });
        $stage->toArray();
        $stage->toArray();
        $this->assertSame(4, $calls);
        $this->assertSame(2, $stage->remember()->count());

        $once = ALinqAsyncCollection::from((static function (): Generator {
            yield 1;
        })())->selectAsync(static fn(int $v): int => $v);
        $this->assertSame([1], $once->toArray());
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Cannot traverse an already closed generator');
        $once->toArray();
    }

    public function testItRunsInsideAFiberToo(): void
    {
        $result = async(static fn(): array => ALinqAsyncCollection::from([1, 2, 3])->selectAsync(static function (int $v): int {
            delay(0.002);
            return -$v;
        }, 2)->toArray())->await();
        $this->assertSame([-1, -2, -3], $result);
    }

    // Laziness and the window -----------------------------------------------------------------------

    public function testNothingRunsUntilTheStageIsTraversed(): void
    {
        $pulled = 0;
        $calls = 0;
        $source = static function () use (&$pulled): Generator {
            foreach ([1, 2, 3] as $v) {
                $pulled++;
                yield $v;
            }
        };
        $stage = ALinqAsyncCollection::from(ALinqLazyCollection::from($source))->selectAsync(static function (int $v) use (&$calls): int {
            $calls++;
            return $v;
        });
        $this->assertSame([0, 0], [$pulled, $calls]);
        $this->assertSame([1, 2, 3], $stage->toArray());
        $this->assertSame([3, 3], [$pulled, $calls]);
    }

    public function testTheSourceIsPulledOnlyAsTheWindowHasRoom(): void
    {
        $pulled = 0;
        $source = static function () use (&$pulled): Generator {
            for ($i = 0; $i < 100; $i++) {
                $pulled++;
                yield $i;
            }
        };
        $stage = ALinqAsyncCollection::from(ALinqLazyCollection::from($source))->selectAsync(static fn(int $v): int => $v, 3);

        $seen = [];
        foreach ($stage as $value) {
            $seen[] = [$value, $pulled];
            if ($value === 4) {
                break;
            }
        }
        // the window is filled again before each head is handed over: head k is delivered with k + 3 + 1 pulled
        $this->assertSame([[0, 4], [1, 5], [2, 6], [3, 7], [4, 8]], $seen);
    }

    public function testTheWindowNeverHasMoreThanConcurrencyItemsInFlight(): void
    {
        foreach ([1, 2, 5] as $concurrency) {
            $inFlight = 0;
            $peak = 0;
            $result = ALinqAsyncCollection::from(range(1, 12))->selectAsync(static function (int $v) use (&$inFlight, &$peak): int {
                $inFlight++;
                $peak = max($peak, $inFlight);
                delay(($v % 3) / 1000);
                $inFlight--;
                return $v;
            }, $concurrency)->toArray();
            $this->assertSame(range(1, 12), $result);
            $this->assertSame($concurrency, $peak, "concurrency $concurrency");
        }
    }

    public function testTheHeadHoldsTheWindowItemsBehindItWait(): void
    {
        $starts = [];
        $t0 = microtime(true);
        ALinqAsyncCollection::from([40, 1, 1, 1])->eachAsync(static function (int $ms, int $k) use (&$starts, $t0): void {
            $starts[$k] = (int) round((microtime(true) - $t0) * 1000);
            delay($ms / 1000);
        }, 2);
        $this->assertLessThan(20, $starts[1]);
        $this->assertGreaterThanOrEqual(35, $starts[2], 'the third item starts only after the head (40 ms) is delivered');
    }

    public function testStoppingEarlyCancelsTheItemsInFlight(): void
    {
        $started = [];
        $cancelled = [];
        $finished = [];
        $stage = ALinqAsyncCollection::from(range(0, 49))->selectAsync(static function (int $v, int $k, Cancellation $cancellation) use (&$started, &$cancelled, &$finished): int {
            $started[] = $v;
            try {
                delay($v === 0 ? 0.001 : 0.05, cancellation: $cancellation);
            } catch (CancelledException $e) {
                $cancelled[] = $v;
                throw $e;
            }
            $finished[] = $v;
            return $v;
        }, 4);

        $this->assertSame(0, $stage->first());
        delay(0.01);

        // first(): item 4 entered the window before the head was handed over, and was cancelled before its
        // Fiber began, so its closure never ran; items 1-3 were running and received the cancellation
        $this->assertSame([0, 1, 2, 3], $started);
        $this->assertSame([0], $finished);
        $this->assertSame([1, 2, 3], $cancelled);
    }

    /** @return array<string, array{\Closure(IALinqLazyCollection): mixed, int}> */
    public static function earlyStops(): array
    {
        return [
            'take(3)' => [static fn(IALinqLazyCollection $s): mixed => $s->take(3)->toArray(), 3],
            'first()' => [static fn(IALinqLazyCollection $s): mixed => $s->first(), 1],
            'any(> 4)' => [static fn(IALinqLazyCollection $s): mixed => $s->any(static fn(int $v): bool => $v > 4), 6],
            'break' => [static function (IALinqLazyCollection $s): mixed {
                foreach ($s as $v) {
                    if ($v === 1) {
                        break;
                    }
                }
                return null;
            }, 2],
        ];
    }

    /** @param \Closure(IALinqLazyCollection): mixed $consume */
    #[DataProvider('earlyStops')]
    public function testAnEarlyStopStartsAtMostTheConsumedItemsPlusConcurrency(\Closure $consume, int $consumed): void
    {
        $started = 0;
        $live = 0;
        $stage = ALinqAsyncCollection::from(range(0, 99))->selectAsync(static function (int $v, int $k, Cancellation $c) use (&$started, &$live): int {
            $started++;
            $live++;
            try {
                delay(0.002, cancellation: $c);
            } finally {
                $live--;
            }
            return $v;
        }, 3);

        $consume($stage);
        delay(0.01);
        $this->assertLessThanOrEqual($consumed + 3, $started);
        $this->assertGreaterThanOrEqual($consumed, $started);
        $this->assertSame(0, $live, 'nothing left running');
    }

    // Errors and time limits ------------------------------------------------------------------------

    public function testAnExceptionIsDeliveredAtThePositionOfTheItem(): void
    {
        $original = new RuntimeException('secret-value-42 is wrong');
        $delivered = [];
        $cancelled = 0;
        try {
            foreach (ALinqAsyncCollection::from(['a' => 1, 'b' => 2, 'c' => 3, 'd' => 4])->selectAsync(static function (int $v, string $k, Cancellation $c) use ($original, &$cancelled): int {
                if ($v === 3) {
                    throw $original;
                }
                try {
                    delay($v === 4 ? 0.5 : 0.01, cancellation: $c);
                } catch (CancelledException $e) {
                    $cancelled++;
                    throw $e;
                }
                return $v * 10;
            }, 4) as $key => $value) {
                $delivered[$key] = $value;
            }
            $this->fail('no exception');
        } catch (ALinqAsyncException $e) {
            $this->assertSame(['a' => 10, 'b' => 20], $delivered, 'the items before it were delivered');
            $this->assertSame('c', $e->getKey());
            $this->assertSame(2, $e->getPosition());
            $this->assertSame('selectAsync', $e->getOperator());
            $this->assertFalse($e->isTimeout());
            $this->assertSame($original, $e->getPrevious());
            $this->assertSame(0, $e->getCode());
            $this->assertSame("selectAsync(): the item at position 2 (key 'c') failed with RuntimeException", $e->getMessage());
            $this->assertStringNotContainsString('secret', $e->getMessage(), 'neither the value nor the original message');
        }
        delay(0.01);
        $this->assertSame(1, $cancelled, 'the item in flight (d) was cancelled');
    }

    public function testAnExceptionOfWhereAsyncIsAtItsPositionToo(): void
    {
        $this->expectException(ALinqAsyncException::class);
        $this->expectExceptionMessage('whereAsync(): the item at position 0 (key 0) failed with LogicException');
        ALinqAsyncCollection::from([new stdClass()])->whereAsync(static fn(): bool => throw new LogicException('x'))->toArray();
    }

    public function testAKeyThatIsNotIntOrStringIsNamedByItsType(): void
    {
        $source = (static function (): Generator {
            yield new stdClass() => 1;
        })();
        try {
            ALinqAsyncCollection::from($source)->selectAsync(static fn(): int => throw new RuntimeException())->toArray();
            $this->fail('no exception');
        } catch (ALinqAsyncException $e) {
            $this->assertInstanceOf(stdClass::class, $e->getKey());
            $this->assertSame('selectAsync(): the item at position 0 (key stdClass) failed with RuntimeException', $e->getMessage());
        }
    }

    public function testATimeLimitIsDeliveredAtThePositionOfTheItem(): void
    {
        $delivered = [];
        $started = microtime(true);
        try {
            foreach (ALinqAsyncCollection::from([0.001, 0.002, 1.0, 0.001])->selectAsync(static function (float $s, int $k, Cancellation $c): float {
                delay($s, cancellation: $c);
                return $s;
            }, 4, 0.05) as $value) {
                $delivered[] = $value;
            }
            $this->fail('no exception');
        } catch (ALinqAsyncException $e) {
            $this->assertSame([0.001, 0.002], $delivered);
            $this->assertTrue($e->isTimeout());
            $this->assertSame(2, $e->getKey());
            $this->assertSame('selectAsync(): the item at position 2 (key 2) exceeded its time limit of 0.05 s', $e->getMessage());
            $this->assertInstanceOf(CancelledException::class, $e->getPrevious());
            $this->assertInstanceOf(TimeoutException::class, $e->getPrevious()->getPrevious());
        }
        $this->assertLessThan(0.5, microtime(true) - $started);
    }

    public function testATimeLimitIsCountedFromTheStartOfTheItemNotFromWhenItIsAwaited(): void
    {
        // item 1 finishes after its 30 ms limit while the head (item 0) is still running: it must not be delivered
        try {
            ALinqAsyncCollection::from([0.06, 0.04])->selectAsync(static function (float $s): float {
                delay($s);
                return $s;
            }, 2, 0.05)->toArray();
            $this->fail('no exception');
        } catch (ALinqAsyncException $e) {
            $this->assertTrue($e->isTimeout());
            $this->assertSame(0, $e->getPosition(), 'the head ran out of time first in source order');
        }
        try {
            ALinqAsyncCollection::from([0.01, 0.08])->selectAsync(static function (float $s): float {
                delay($s);
                return $s;
            }, 2, 0.05)->toArray();
            $this->fail('no exception');
        } catch (ALinqAsyncException $e) {
            $this->assertSame(1, $e->getPosition());
        }
    }

    public function testAClosureThatThrowsACancelledExceptionOfItsOwnIsNotATimeout(): void
    {
        try {
            ALinqAsyncCollection::from([1])->selectAsync(static fn(): never => throw new CancelledException(), 1, 1.0)->toArray();
            $this->fail('no exception');
        } catch (ALinqAsyncException $e) {
            $this->assertFalse($e->isTimeout());
        }
    }

    public function testAnErrorOfTheSourceComesAfterTheItemsBeforeItAsItIs(): void
    {
        $original = new RuntimeException('the source broke');
        $source = static function () use ($original): Generator {
            yield 1;
            yield 2;
            throw $original;
        };
        $delivered = [];
        try {
            foreach (ALinqAsyncCollection::from(ALinqLazyCollection::from($source))->selectAsync(static function (int $v): int {
                delay(0.002);
                return $v;
            }, 8) as $value) {
                $delivered[] = $value;
            }
            $this->fail('no exception');
        } catch (RuntimeException $e) {
            $this->assertSame($original, $e);
            $this->assertSame([1, 2], $delivered);
        }
    }

    // Arguments -------------------------------------------------------------------------------------

    /** @return array<string, array{string, int, float|null, string}> */
    public static function invalidArguments(): array
    {
        return [
            'selectAsync concurrency 0' => ['selectAsync', 0, null, 'selectAsync() expects concurrency to be at least 1, 0 given'],
            'whereAsync concurrency -1' => ['whereAsync', -1, null, 'whereAsync() expects concurrency to be at least 1, -1 given'],
            'selectManyAsync timeout 0' => ['selectManyAsync', 1, 0.0, 'selectManyAsync() expects timeout to be a positive number of seconds, 0.0 given'],
            'eachAsync timeout -0.5' => ['eachAsync', 1, -0.5, 'eachAsync() expects timeout to be a positive number of seconds, -0.5 given'],
            'selectAsync timeout INF' => ['selectAsync', 1, INF, 'selectAsync() expects timeout to be a positive number of seconds, INF given'],
            'whereAsync timeout NAN' => ['whereAsync', 1, NAN, 'whereAsync() expects timeout to be a positive number of seconds, NAN given'],
        ];
    }

    #[DataProvider('invalidArguments')]
    public function testInvalidArgumentsAreRefusedWhenTheOperatorIsCalled(string $operator, int $concurrency, ?float $timeout, string $message): void
    {
        $calls = 0;
        $collection = ALinqAsyncCollection::from([1]);
        try {
            $collection->{$operator}(static function () use (&$calls): array {
                $calls++;
                return [];
            }, $concurrency, $timeout);
            $this->fail('accepted');
        } catch (InvalidArgumentException $e) {
            $this->assertSame($message, $e->getMessage());
        }
        $this->assertSame(0, $calls);
    }

    public function testConcurrencyOneAndTheDefaultsAreAccepted(): void
    {
        $this->assertSame([1, 2], ALinqAsyncCollection::from([1, 2])->selectAsync(static fn(int $v): int => $v, 1, 0.5)->toArray());
        $this->assertSame([1, 2], ALinqAsyncCollection::from([1, 2])->selectAsync(static fn(int $v): int => $v)->toArray());
    }

    public function testTheDefaultConcurrencyIsEightForEveryOperator(): void
    {
        foreach (['selectAsync', 'whereAsync', 'selectManyAsync', 'eachAsync'] as $operator) {
            unset($inFlight, $peak);
            $inFlight = 0;
            $peak = 0;
            $stage = ALinqAsyncCollection::from(range(1, 20))->{$operator}(static function () use (&$inFlight, &$peak): array {
                $inFlight++;
                $peak = max($peak, $inFlight);
                delay(0.002);
                $inFlight--;
                return [];
            });
            $stage?->toArray();
            $this->assertSame(8, $peak, $operator);
        }
    }

    // A shared runner -------------------------------------------------------------------------------

    public function testASharedRunnerCapsTheOperatorsTogether(): void
    {
        $runner = new RevoltRunner(3);
        $inFlight = 0;
        $peak = 0;
        $work = static function (int $v) use (&$inFlight, &$peak): int {
            $inFlight++;
            $peak = max($peak, $inFlight);
            delay(0.003);
            $inFlight--;
            return $v;
        };
        $first = ALinqAsyncCollection::from(range(1, 10), $runner)->selectAsync($work, 8);
        $second = ALinqAsyncCollection::from($first, $runner)->selectAsync($work, 8);

        $this->assertSame(range(1, 10), $second->toArray());
        $this->assertSame(3, $peak, 'two windows of 8, one runner of 3');
        $this->assertSame(0, $runner->running());
    }

    public function testWithoutASharedRunnerEachTraversalHasItsOwnWindow(): void
    {
        $inFlight = 0;
        $peak = 0;
        $work = static function (int $v) use (&$inFlight, &$peak): int {
            $inFlight++;
            $peak = max($peak, $inFlight);
            delay(0.003);
            $inFlight--;
            return $v;
        };
        $first = ALinqAsyncCollection::from(range(1, 10))->selectAsync($work, 2);
        $this->assertSame(range(1, 10), ALinqAsyncCollection::from($first)->selectAsync($work, 2)->toArray());
        $this->assertSame(4, $peak, 'two windows of 2');
    }
}
