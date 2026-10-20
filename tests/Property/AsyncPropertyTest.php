<?php

declare(strict_types=1);

namespace Antevemus\ALinqRevolt\Tests\Property;

use Antevemus\ALinq\ALinqCollection;
use Antevemus\ALinq\ALinqLazyCollection;
use Antevemus\ALinq\Interfaces\IALinqLazyCollection;
use Antevemus\ALinqRevolt\ALinqAsyncCollection;
use ArrayIterator;
use Closure;
use Generator;
use PHPUnit\Framework\TestCase;

use function Amp\delay;

/**
 * Properties with the core as the oracle, over seeded random inputs (a fixed seed per property, so a
 * failure names an input that reproduces): lists, string-keyed, integer-keyed and mixed dictionaries of
 * 0-12 items, any concurrency from 1 to 10, and a random non-blocking delay per item (none three times in
 * five, otherwise 0.1 or 0.3 ms), so the closures finish in an order unrelated to the source (the first
 * property counts the inputs where they did, and requires at least one in five).
 *
 * - selectAsync(f, c), whereAsync(p, c) and selectManyAsync(f, c) stream the same keys, values and order as
 *   the core select(f), where(p) and selectMany(f), and their toArray() is the core's toArray() over the
 *   same array, integer-keyed dictionaries included (the stages declare the shape: ALinq 2.3.0), and over a
 *   lazy ALinq collection of it (its isList()); over an ArrayIterator of the same items (a shape neither side
 *   knows) toArray() is the core's too; eachAsync(a, c) runs a once per item.
 * - take(k) after selectAsync(f, c) answers as the core take(k) and starts at most k + c items.
 * - The core operators downstream (where, orderBy, concat, groupBy) answer as over the synchronous select.
 *
 * @version    1.0.0
 * @package    Antevemus\ALinqRevolt\Tests
 * @subpackage Property
 */
final class AsyncPropertyTest extends TestCase
{
    private const ITERATIONS = 200;

    public function testSelectWhereSelectManyAndEachMatchTheCore(): void
    {
        mt_srand(20261020);
        $reordered = 0;
        for ($i = 0; $i < self::ITERATIONS; $i++) {
            [$source, $c, $delays] = self::input();
            $label = self::label($i, $source, $c);
            $f = self::sleepy(static fn(int $v, int|string $k): int => $v * 3 + strlen((string) $k), $delays);
            $p = self::sleepy(static fn(int $v, int|string $k): bool => ($v + strlen((string) $k)) % 3 !== 0, $delays);
            $many = self::sleepy(static fn(int $v, int|string $k): array => array_fill(0, abs($v) % 3, "$k/$v"), $delays);
            $pure = static fn(Closure $g): Closure => static fn(int $v, int|string $k): mixed => $g($v, $k, false);

            unset($finished);
            $finished = [];
            $tracked = self::sleepy(static function (int $v, int|string $k) use (&$finished): int {
                $finished[] = $k;
                return $v * 3 + strlen((string) $k);
            }, $delays);
            $this->assertSame(self::pairs(ALinqLazyCollection::from($source)->select($pure($f))), self::pairs(ALinqAsyncCollection::from($source)->selectAsync($tracked, $c)), "$label: selectAsync stream");
            $reordered += $finished !== array_keys($source) ? 1 : 0;
            $this->assertSame(ALinqLazyCollection::from($source)->select($pure($f))->toArray(), ALinqAsyncCollection::from($source)->selectAsync($f, $c)->toArray(), "$label: selectAsync toArray");
            $this->assertSame(self::pairs(ALinqLazyCollection::from($source)->where($pure($p))), self::pairs(ALinqAsyncCollection::from($source)->whereAsync($p, $c)), "$label: whereAsync stream");
            $this->assertSame(ALinqLazyCollection::from($source)->where($pure($p))->toArray(), ALinqAsyncCollection::from($source)->whereAsync($p, $c)->toArray(), "$label: whereAsync toArray");
            $this->assertSame(ALinqLazyCollection::from($source)->selectMany($pure($many))->toArray(), ALinqAsyncCollection::from($source)->selectManyAsync($many, $c)->toArray(), "$label: selectManyAsync toArray");
            // a lazy ALinq source has the shape the core knows (isList(), ALinq 2.3.0): the core's answer
            $lazy = ALinqLazyCollection::from($source);
            $this->assertSame($lazy->select($pure($f))->toArray(), ALinqAsyncCollection::from($lazy)->selectAsync($f, $c)->toArray(), "$label: selectAsync toArray over a lazy collection");
            $this->assertSame(self::pairs($lazy->where($pure($p))), self::pairs(ALinqAsyncCollection::from($lazy)->whereAsync($p, $c)), "$label: whereAsync stream over a lazy collection");
            // a Traversable: a shape the core does not know either; the same answer as the core over it
            $this->assertSame(ALinqLazyCollection::from(new ArrayIterator($source))->select($pure($f))->toArray(), ALinqAsyncCollection::from(new ArrayIterator($source))->selectAsync($f, $c)->toArray(), "$label: selectAsync toArray over a Traversable");

            unset($seen);
            $seen = [];
            ALinqAsyncCollection::from($source)->eachAsync(self::sleepy(static function (int $v, int|string $k) use (&$seen): void {
                $seen[] = [$k, $v];
            }, $delays), $c);
            $this->assertEqualsCanonicalizing(self::pairs(self::stream($source)), $seen, "$label: eachAsync");
        }
        $this->assertGreaterThan(self::ITERATIONS / 5, $reordered, "the closures finished out of source order in $reordered inputs");
    }

    public function testTakeAfterSelectAsyncStartsAtMostTakePlusConcurrency(): void
    {
        mt_srand(424242);
        for ($i = 0; $i < self::ITERATIONS; $i++) {
            [$source, $c, $delays] = self::input();
            $k = mt_rand(0, 14);
            unset($started); // a fresh variable: the closure of the previous iteration keeps its own
            $started = 0;
            $f = self::sleepy(static function (int $v, int|string $key) use (&$started): int {
                $started++;
                return -$v;
            }, $delays);
            $result = ALinqAsyncCollection::from($source)->selectAsync($f, $c)->take($k)->toArray();
            delay(0.001); // let the cancelled items settle

            $label = self::label($i, $source, $c) . " k=$k";
            $this->assertSame(ALinqLazyCollection::from($source)->select(static fn(int $v): int => -$v)->take($k)->toArray(), $result, $label);
            $this->assertLessThanOrEqual(min(count($source), $k + $c), $started, "$label: started");
            $this->assertGreaterThanOrEqual(min(count($source), $k), $started, "$label: started");
        }
    }

    public function testDownstreamCoreOperatorsAnswerAsOverTheSynchronousSelect(): void
    {
        mt_srand(777);
        for ($i = 0; $i < self::ITERATIONS; $i++) {
            [$source, $c, $delays] = self::input();
            $label = self::label($i, $source, $c);
            $f = self::sleepy(static fn(int $v, int|string $k): int => ($v * 7 + strlen((string) $k)) % 11, $delays);
            $sync = static fn(): IALinqLazyCollection => ALinqLazyCollection::from($source)->select(static fn(int $v, int|string $k): int => ($v * 7 + strlen((string) $k)) % 11);
            $async = static fn(): IALinqLazyCollection => ALinqAsyncCollection::from($source)->selectAsync($f, $c);
            $even = static fn(int $v): bool => $v % 2 === 0;
            $groups = static fn(IALinqLazyCollection $s): array => $s->groupBy(static fn(int $v): int => $v % 3)->select(static fn(ALinqCollection $g): array => $g->toArray())->toArray();

            $this->assertSame($sync()->where($even)->toArray(), $async()->where($even)->toArray(), "$label: where");
            $this->assertSame($sync()->orderBy(static fn(int $v): int => $v)->toArray(), $async()->orderBy(static fn(int $v): int => $v)->toArray(), "$label: orderBy");
            $this->assertSame($sync()->concat([99])->toArray(), $async()->concat([99])->toArray(), "$label: concat");
            $this->assertSame($groups($sync()), $groups($async()), "$label: groupBy");
        }
    }

    /**
     * A random source, a concurrency and a delay per position.
     *
     * @return array{array<int|string, int>, int, list<float>}
     */
    private static function input(): array
    {
        $n = mt_rand(0, 12);
        $source = [];
        $shape = mt_rand(0, 3);
        for ($j = 0; $j < $n; $j++) {
            $value = mt_rand(-50, 50);
            match ($shape) {
                0 => $source[] = $value,
                1 => $source['k' . mt_rand(0, 30)] = $value,
                2 => $source[mt_rand(-5, 40)] = $value,
                default => $source[mt_rand(0, 1) === 0 ? mt_rand(0, 9) : 's' . mt_rand(0, 9)] = $value,
            };
        }
        $delays = [];
        for ($j = 0; $j < 13; $j++) {
            $delays[] = [0.0, 0.0, 0.0, 0.0001, 0.0003][mt_rand(0, 4)];
        }

        return [$source, mt_rand(1, 10), $delays];
    }

    /**
     * The closure with a delay chosen by the item's position (the third argument false skips it).
     *
     * @param list<float> $delays
     */
    private static function sleepy(Closure $g, array $delays): Closure
    {
        $position = 0;

        return static function (int $v, int|string $k, mixed $sleep = true) use ($g, $delays, &$position): mixed {
            if ($sleep !== false) {
                $ms = $delays[$position++ % count($delays)];
                if ($ms > 0) {
                    delay($ms);
                }
            }

            return $g($v, $k);
        };
    }

    /**
     * The same pairs as a generator, a source whose shape the core does not know.
     *
     * @param array<int|string, int> $source
     */
    private static function stream(array $source): Closure
    {
        return static function () use ($source): Generator {
            foreach ($source as $key => $value) {
                yield $key => $value;
            }
        };
    }

    /**
     * The [key, value] pairs of a traversal, in order.
     *
     * @param iterable<mixed, mixed>|Closure(): iterable<mixed, mixed> $items
     * @return list<array{mixed, mixed}>
     */
    private static function pairs(iterable|Closure $items): array
    {
        $out = [];
        foreach ($items instanceof Closure ? $items() : $items as $key => $value) {
            $out[] = [$key, $value];
        }

        return $out;
    }

    /** @param array<int|string, int> $source */
    private static function label(int $i, array $source, int $c): string
    {
        return sprintf('iteration %d, c=%d, source %s', $i, $c, json_encode($source));
    }
}
