<?php

declare(strict_types=1);

namespace Antevemus\ALinqRevolt;

use Amp\Cancellation;
use Amp\CancelledException;
use Amp\DeferredCancellation;
use Amp\TimeoutException;
use Antevemus\ALinq\ALinqCollection;
use Antevemus\ALinq\ALinqLazyCollection;
use Antevemus\ALinq\Helpers\ALinqCallable;
use Antevemus\ALinq\Interfaces\IALinqCollection;
use Antevemus\ALinq\Interfaces\IALinqLazyCollection;
use Closure;
use Generator;
use InvalidArgumentException;
use ReflectionFunction;
use Throwable;
use Traversable;
use UnexpectedValueException;

/**
 * ALinqAsyncCollection - asynchronous operators over an ALinq source, on the Revolt event loop.
 *
 * selectAsync(), whereAsync() and selectManyAsync() return a core ALinqLazyCollection (built with
 * ALinqLazyCollection::from($factory, $isList) over a generator factory, the shape declared since
 * antevemus/alinq-collection 2.3.0), so every operator of the core chains after them;
 * eachAsync() is terminal. The core classes are final: this class wraps a source, it never extends them.
 *
 * Semantics of every operator: an ordered, bounded, lazy window. The closure of each item runs in its own
 * Fiber (RevoltRunner); up to `$concurrency` items are started and not yet delivered; the results come out
 * in source order; the source is pulled only when the window has room, and the window is filled again
 * before the head is handed over, so taking k items starts at most k + concurrency. Stopping early (take(),
 * first(), any(), a break out of foreach, an exception) ends the generator, and its `finally` cancels the
 * items in flight. The closure receives `($item, $key)` by the core's arity rule (ALinqCallable::withKey());
 * a user closure that declares a third parameter also receives the item's Amp\Cancellation (its time limit,
 * an early stop), to pass to an Amp client. It may return a value, an Amp\Future, or suspend (Amp\delay(),
 * an Amp client). A Fiber only gains time when the closure waits on non-blocking I/O: blocking calls and
 * CPU work run one after the other, whatever the concurrency.
 *
 * Errors are delivered at the item's position as ALinqAsyncException (getKey(), getPosition(),
 * isTimeout(), the original as getPrevious()). An error of the source itself is rethrown as it is, at the
 * position where the source failed, after the items before it.
 *
 * Keys follow the core's select()/where()/selectMany(): selectAsync() and whereAsync() renumber a source
 * known to be a list and stream the source key otherwise; selectManyAsync() yields a list. Each stage declares
 * the shape of its source to the core (from($factory, $isList)): a list, a dictionary (an integer-keyed
 * one keeps its keys in toArray(), as the core select() over the same array), or unknown when the source's
 * shape is unknown to the core too (a Traversable, a Generator, a lazy collection whose isList() is null):
 * toArray() is then a list when every key is an integer, as in the core's own stages over such a source.
 * The shape of an array is array_is_list(), of an eager collection its isList(), of a lazy collection its
 * isList() (ALinq 2.3.0).
 *
 * @version    1.0.0
 * @package    Antevemus\ALinqRevolt
 * @author     Heliton Junior
 * @copyright  Copyright (c) 2026 Antevemus Soluções Inovadoras em TI Ltda. (https://antevemus.com.br)
 * @license    MIT License
 */
final class ALinqAsyncCollection
{
    /**
     * @param iterable<mixed, mixed> $source
     * @param bool|null $isList Whether the source is known to be a list (true), a dictionary (false) or unknown (null).
     * @param RevoltRunner|null $runner A runner shared by the traversals, or null for a new one per traversal.
     */
    private function __construct(
        private readonly iterable $source,
        private readonly ?bool $isList,
        private readonly ?RevoltRunner $runner,
    ) {
    }

    /**
     * Wraps a source: an array, an eager or lazy ALinq collection, or any Traversable (a Generator is read once).
     *
     * @param iterable<mixed, mixed>|IALinqCollection|IALinqLazyCollection $source
     * @param RevoltRunner|null $runner Shared by every traversal of every operator of this collection, so its
     *                                  concurrency caps them together; null starts a new RevoltRunner per
     *                                  traversal, limited by the operator's own concurrency.
     */
    public static function from(iterable|IALinqCollection|IALinqLazyCollection $source, ?RevoltRunner $runner = null): self
    {
        $isList = match (true) {
            is_array($source) => array_is_list($source),
            $source instanceof IALinqCollection => $source->isList(),
            $source instanceof IALinqLazyCollection => $source->isList(),
            default => null,
        };

        return new self($source, $isList, $runner);
    }

    /**
     * Projects every item through `$selector`, up to `$concurrency` items at a time (Select).
     *
     * @param callable $selector `($item, $key)`, or `($item, $key, Cancellation $cancellation)`.
     * @param int $concurrency The most items started and not yet delivered (at least 1).
     * @param float|null $timeout Seconds each item may take; null for no limit.
     * @throws InvalidArgumentException When the concurrency is below 1 or the timeout is not positive
     */
    public function selectAsync(callable $selector, int $concurrency = 8, ?float $timeout = null): IALinqLazyCollection
    {
        $results = $this->results('selectAsync', $selector, $concurrency, $timeout);
        $isList = $this->isList === true;

        return ALinqLazyCollection::from(static function () use ($results, $isList): Generator {
            foreach ($results() as [, $key, , $value]) {
                if ($isList) {
                    yield $value; // a list renumbers, as the core select() does
                } else {
                    yield $key => $value;
                }
            }
        }, $this->isList);
    }

    /**
     * Keeps the items whose predicate answers a truthy value, up to `$concurrency` predicates at a time (Where).
     *
     * @param callable $predicate `($item, $key)`, or `($item, $key, Cancellation $cancellation)`.
     * @param int $concurrency The most items started and not yet delivered (at least 1).
     * @param float|null $timeout Seconds each item may take; null for no limit.
     * @throws InvalidArgumentException When the concurrency is below 1 or the timeout is not positive
     */
    public function whereAsync(callable $predicate, int $concurrency = 8, ?float $timeout = null): IALinqLazyCollection
    {
        $results = $this->results('whereAsync', $predicate, $concurrency, $timeout);
        $isList = $this->isList === true;

        return ALinqLazyCollection::from(static function () use ($results, $isList): Generator {
            foreach ($results() as [, $key, $item, $keep]) {
                if (!$keep) {
                    continue;
                }
                if ($isList) {
                    yield $item;
                } else {
                    yield $key => $item;
                }
            }
        }, $this->isList);
    }

    /**
     * Projects every item to an iterable and flattens them in order, up to `$concurrency` items at a time
     * (SelectMany). The result is a list.
     *
     * @param callable $selector `($item, $key)`, or `($item, $key, Cancellation $cancellation)`; returns an iterable.
     * @param int $concurrency The most items started and not yet delivered (at least 1).
     * @param float|null $timeout Seconds each item may take; null for no limit.
     * @throws InvalidArgumentException When the concurrency is below 1 or the timeout is not positive
     */
    public function selectManyAsync(callable $selector, int $concurrency = 8, ?float $timeout = null): IALinqLazyCollection
    {
        $results = $this->results('selectManyAsync', $selector, $concurrency, $timeout, static function (mixed $value, mixed $key): ?Throwable {
            return is_iterable($value) ? null : new UnexpectedValueException(sprintf(
                'selectMany() expects the selector to return an iterable, %s returned for key %s',
                get_debug_type($value),
                is_int($key) || is_string($key) ? var_export($key, true) : get_debug_type($key)
            ));
        });

        return ALinqLazyCollection::from(static function () use ($results): Generator {
            foreach ($results() as [, , , $inner]) {
                foreach ($inner as $item) {
                    yield $item;
                }
            }
        }, true);
    }

    /**
     * Runs `$action` for every item, up to `$concurrency` at a time, and returns when all have finished (terminal).
     *
     * @param callable $action `($item, $key)`, or `($item, $key, Cancellation $cancellation)`.
     * @param int $concurrency The most items started and not yet finished in order (at least 1).
     * @param float|null $timeout Seconds each item may take; null for no limit.
     * @throws InvalidArgumentException When the concurrency is below 1 or the timeout is not positive
     * @throws ALinqAsyncException When an action fails or runs out of time (the first one in source order)
     */
    public function eachAsync(callable $action, int $concurrency = 8, ?float $timeout = null): void
    {
        foreach ($this->results('eachAsync', $action, $concurrency, $timeout)() as $ignored) {
            // the window runs the actions; nothing to hand over
        }
    }

    /**
     * The source as a core lazy collection.
     */
    public function lazy(): IALinqLazyCollection
    {
        return match (true) {
            $this->source instanceof IALinqLazyCollection => $this->source,
            $this->source instanceof IALinqCollection => $this->source->lazy(),
            default => ALinqLazyCollection::from($this->source),
        };
    }

    /**
     * The source as a core eager collection (a lazy source or a Traversable is read).
     */
    public function collection(): IALinqCollection
    {
        return $this->source instanceof IALinqCollection
            ? $this->source
            : ALinqCollection::from($this->lazy()->toArray());
    }

    /**
     * Validates the arguments now and returns the factory of the window over the source.
     *
     * @param (Closure(mixed, mixed): ?Throwable)|null $check An error for a value, delivered at its position.
     * @return Closure(): Generator<int, array{int, mixed, mixed, mixed}>
     */
    private function results(string $operator, callable $callback, int $concurrency, ?float $timeout, ?Closure $check = null): Closure
    {
        if ($concurrency < 1) {
            throw new InvalidArgumentException(sprintf('%s() expects concurrency to be at least 1, %d given', $operator, $concurrency));
        }
        if ($timeout !== null && !($timeout > 0 && is_finite($timeout))) {
            throw new InvalidArgumentException(sprintf('%s() expects timeout to be a positive number of seconds, %s given', $operator, var_export($timeout, true)));
        }
        $closure = self::normalize($callback);
        $source = $this->source;
        $runner = $this->runner;

        return static fn(): Generator => self::window($operator, $closure, $source, $runner ?? new RevoltRunner($concurrency), $concurrency, $timeout, $check);
    }

    /**
     * The ordered, bounded, lazy window: yields [position, key, item, value] in source order.
     *
     * @param Closure(mixed, mixed, Cancellation): mixed $closure
     * @param iterable<mixed, mixed> $source
     * @param (Closure(mixed, mixed): ?Throwable)|null $check
     * @return Generator<int, array{int, mixed, mixed, mixed}>
     */
    private static function window(string $operator, Closure $closure, iterable $source, RevoltRunner $runner, int $concurrency, ?float $timeout, ?Closure $check): Generator
    {
        $items = (static function () use ($source): Generator {
            foreach ($source as $key => $item) {
                yield $key => $item;
            }
        })();
        $scope = new DeferredCancellation();
        /** @var list<array{int, mixed, mixed, \Amp\Future<mixed>}> $window */
        $window = [];
        $position = 0;
        $advance = false;
        $sourceError = null;
        // pulls one source item per slot; a finished (or failed) source generator stays finished, so later
        // calls only find it invalid
        $fill = static function () use ($items, $closure, $runner, $concurrency, $timeout, $scope, &$window, &$position, &$advance, &$sourceError): void {
            while (count($window) < $concurrency) {
                try {
                    if ($advance) {
                        $items->next();
                    }
                    $advance = true;
                    if (!$items->valid()) {
                        return;
                    }
                    $key = $items->key();
                    $item = $items->current();
                } catch (Throwable $error) {
                    $sourceError = $error;
                    return;
                }
                $window[] = [$position++, $key, $item, $runner->start(static fn(Cancellation $cancellation): mixed => $closure($item, $key, $cancellation), $timeout, $scope->getCancellation())];
            }
        };

        try {
            $fill();
            while ($window !== []) {
                [$at, $key, $item, $future] = array_shift($window);
                try {
                    $value = $future->await();
                } catch (Throwable $error) {
                    $timedOut = $error instanceof CancelledException && $error->getPrevious() instanceof TimeoutException;
                    throw new ALinqAsyncException($operator, $at, $key, $timedOut ? $timeout : null, $error);
                }
                $invalid = $check === null ? null : $check($value, $key);
                if ($invalid !== null) {
                    throw new ALinqAsyncException($operator, $at, $key, null, $invalid);
                }
                $fill(); // the window is filled again before the head is handed over

                yield [$at, $key, $item, $value];
            }
            if ($sourceError !== null) {
                throw $sourceError;
            }
        } finally {
            foreach ($window as [, , , $pending]) {
                $pending->ignore();
            }
            $scope->cancel();
        }
    }

    /**
     * The callback in the `($item, $key, Cancellation)` shape: a user closure that declares three parameters
     * (or is variadic) receives the three; any other callable follows ALinqCallable::withKey().
     *
     * @return Closure(mixed, mixed, Cancellation): mixed
     */
    private static function normalize(callable $callback): Closure
    {
        $closure = $callback instanceof Closure ? $callback : Closure::fromCallable($callback);
        $reflection = new ReflectionFunction($closure);
        if (!$reflection->isInternal() && ($reflection->isVariadic() || $reflection->getNumberOfParameters() >= 3)) {
            return $closure;
        }
        $withKey = ALinqCallable::withKey($closure);

        return static fn(mixed $item, mixed $key, Cancellation $cancellation): mixed => $withKey($item, $key);
    }
}
