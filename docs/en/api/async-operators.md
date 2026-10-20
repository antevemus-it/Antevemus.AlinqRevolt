# Asynchronous Operators

## Signatures

| Method | Returns | Core counterpart |
| :--- | :--- | :--- |
| `ALinqAsyncCollection::from(iterable\|IALinqCollection\|IALinqLazyCollection $source, ?RevoltRunner $runner = null)` | `ALinqAsyncCollection` | `ALinqLazyCollection::from()` |
| `selectAsync(callable $selector, int $concurrency = 8, ?float $timeout = null)` | `IALinqLazyCollection` | `select()` |
| `whereAsync(callable $predicate, int $concurrency = 8, ?float $timeout = null)` | `IALinqLazyCollection` | `where()` |
| `selectManyAsync(callable $selector, int $concurrency = 8, ?float $timeout = null)` | `IALinqLazyCollection` | `selectMany()` |
| `eachAsync(callable $action, int $concurrency = 8, ?float $timeout = null)` | `void` (terminal) | `each()` |
| `lazy()` / `collection()` | the source as `IALinqLazyCollection` / `IALinqCollection` | |

The three projections return a core `ALinqLazyCollection` built over a generator, so every core operator chains after them, and every traversal runs the window again (as every traversal of a core lazy stage reads its source again; `remember()` keeps the results). A `Generator` source is read once, as in the core.

- `$concurrency`: the most items started and not yet delivered, at least 1 (`InvalidArgumentException` otherwise, when the operator is called).
- `$timeout`: seconds each item may take, counted from its start; `null` for no limit; a positive finite number otherwise (`InvalidArgumentException`).

## The closure

The closure receives `($item, $key)` by the core's arity rule: a callable that accepts two parameters receives the key, a one-parameter callable (or an internal function such as `strval`) receives the item alone. A user closure that declares a **third** parameter also receives the item's `Amp\Cancellation`, requested when its time limit runs out or the consumer stops early: pass it to the Amp client so the request itself is aborted.

The closure runs in its own Fiber under the event loop. It may return a value, return an `Amp\Future` (awaited for it), or suspend inside (an Amp client, `Amp\delay()`, another `selectAsync()`).

<!-- tested: operators -->
```php
<?php

declare(strict_types=1);

use Antevemus\ALinqRevolt\ALinqAsyncCollection;

use function Amp\delay;

$orders = ['A-1' => 120, 'A-2' => 40, 'A-3' => 75];

// selectAsync(): one result per item, in source order, keys kept
$quotes = ALinqAsyncCollection::from($orders)
    ->selectAsync(function (int $amount, string $code): int {
        delay(0.01); // e.g. a shipping service
        return $amount + 15;
    })
    ->toArray();
// Result: ['A-1' => 135, 'A-2' => 55, 'A-3' => 90]

// whereAsync(): keeps the items whose predicate answers a truthy value
$approved = ALinqAsyncCollection::from($orders)
    ->whereAsync(function (int $amount): bool {
        delay(0.01); // e.g. a fraud check
        return $amount >= 50;
    })
    ->toArray();
// Result: ['A-1' => 120, 'A-3' => 75]

// selectManyAsync(): each item gives an iterable, flattened in order into a list
$lines = ALinqAsyncCollection::from(['A-1', 'A-3'])
    ->selectManyAsync(function (string $code): array {
        delay(0.01); // e.g. the lines of the order
        return ["$code/1", "$code/2"];
    })
    ->toArray();
// Result: ['A-1/1', 'A-1/2', 'A-3/1', 'A-3/2']

// eachAsync(): terminal; runs every action, returns when all have finished
$notified = [];
ALinqAsyncCollection::from($orders)->eachAsync(function (int $amount, string $code) use (&$notified): void {
    delay(0.01); // e.g. a notification
    $notified[] = $code;
}, concurrency: 2);
count($notified); // 3
```

`eachAsync()` runs the same ordered window as the projections and throws the first failure in source order.

## Keys

The keys follow the core's `select()`, `where()` and `selectMany()`:

- `selectAsync()` and `whereAsync()` renumber a source known to be a list, as the core `select()` and `where()` do over a list, and keep the keys otherwise.
- `selectManyAsync()` yields a list.

Each stage declares the shape of its source to the core with `ALinqLazyCollection::from($factory, $isList)` (the parameter added in ALinq 2.3.0, the reason this package requires `^2.3`), so `toArray()` and the core operators after it answer as over the core's own stage: a list stays a list, and a dictionary keeps its keys, an integer-keyed one (`[$id => $row]`) included. The shape comes from the source as the core knows it: `array_is_list()` for an array, `isList()` for an eager or a lazy ALinq collection (the lazy accessor is also new in 2.3.0). Over a `Generator` or another `Traversable` the core knows no shape, and neither does the stage: as in the core's own stages over them, `toArray()` is a list when every key is an integer.

<!-- tested: keys -->
```php
<?php

declare(strict_types=1);

use Antevemus\ALinq\ALinqLazyCollection;
use Antevemus\ALinqRevolt\ALinqAsyncCollection;

$double = fn(int $n): int => $n * 2;

ALinqAsyncCollection::from([1, 2, 3])->whereAsync(fn(int $n) => $n !== 2)->toArray(); // [1, 3]
ALinqAsyncCollection::from(['x' => 1, 'y' => 2])->selectAsync($double)->toArray(); // ['x' => 2, 'y' => 4]

// an integer-keyed dictionary keeps its keys, as in the core select() over the same array
ALinqAsyncCollection::from([7 => 1, 9 => 2])->selectAsync($double)->toArray(); // [7 => 2, 9 => 4]
ALinqLazyCollection::from([7 => 1, 9 => 2])->select($double)->toArray(); // [7 => 2, 9 => 4]
ALinqAsyncCollection::from([7 => 1, 9 => 2, 12 => 3])->whereAsync(fn(int $n) => $n !== 2)->toArray(); // [7 => 1, 12 => 3]

// a generator has no known shape, in the core as here: a list when every key is an integer
$stream = (function () {
    yield 7 => 1;
    yield 9 => 2;
})();
ALinqAsyncCollection::from($stream)->selectAsync($double)->toArray(); // [2, 4]
```

## Errors

An exception of the closure is delivered **at the position of its item**, wrapped in `ALinqAsyncException` (final, extends `RuntimeException`): the items before it were already handed over, the items in flight are cancelled, and nothing after it starts. There is no "ignore errors" policy in 1.0.0: catch inside the closure to turn a failure into a value.

| Method | Answer |
| :--- | :--- |
| `getKey()` | The key of the item in the source. |
| `getPosition()` | The position of the item in the source, from 0. |
| `getOperator()` | `selectAsync`, `whereAsync`, `selectManyAsync` or `eachAsync`. |
| `isTimeout()` | Whether the item ran out of time. |
| `getPrevious()` | The original exception; on a timeout, the `Amp\CancelledException` whose previous is the `Amp\TimeoutException`. |

The message names the operator, the position, the key and the class of the original exception; never the value of the item nor the message of the original, which may carry it. `selectManyAsync()` refuses a result that is not iterable the same way, with the `UnexpectedValueException` of the core's `selectMany()` as the previous. An exception of the source itself (a generator that throws, a failing cursor) is not wrapped: it comes as it is, after the items read before it.

<!-- tested: errors -->
```php
<?php

declare(strict_types=1);

use Antevemus\ALinqRevolt\ALinqAsyncCollection;
use Antevemus\ALinqRevolt\ALinqAsyncException;

$ratios = ALinqAsyncCollection::from(['a' => 5, 'b' => 0, 'c' => 2])->selectAsync(fn(int $n): int => intdiv(10, $n));

$ratios->toArray(); // throws ALinqAsyncException: selectAsync(): the item at position 1 (key 'b') failed with DivisionByZeroError

$delivered = [];
try {
    foreach ($ratios as $key => $ratio) {
        $delivered[$key] = $ratio;
    }
} catch (ALinqAsyncException $error) {
    // the items before it were delivered
}
$delivered; // ['a' => 2]
$error->getKey(); // 'b'
$error->getPosition(); // 1
$error->isTimeout(); // false
$error->getPrevious()::class; // 'DivisionByZeroError'
```

## Time limits

`timeout` is a number of seconds per item, counted from the moment its closure starts, enforced with an `Amp\TimeoutCancellation`. When it runs out, the item fails at its position with `isTimeout()` true, even if it finishes later, and its `Cancellation` is requested. A closure that passes the cancellation to its client stops at once; one that ignores it keeps running in the background until it returns (its result is discarded), still counted against the runner's limit.

<!-- tested: timeout -->
```php
<?php

declare(strict_types=1);

use Amp\Cancellation;
use Antevemus\ALinqRevolt\ALinqAsyncCollection;

use function Amp\delay;

$calls = ALinqAsyncCollection::from([0.01, 5.0, 0.01])->selectAsync(
    function (float $seconds, int $key, Cancellation $cancellation): string {
        delay($seconds, cancellation: $cancellation); // pass the cancellation to the Amp client
        return "took $seconds s";
    },
    timeout: 0.2,
);

$calls->toArray(); // throws ALinqAsyncException: selectAsync(): the item at position 1 (key 1) exceeded its time limit of 0.2 s
$calls->first(); // 'took 0.01 s'
```

`first()` stops after the first item, so the 5-second call is cancelled long before its time limit.

## One runner for several operators

By default each traversal of an operator gets its own `RevoltRunner`, limited by the operator's `concurrency`. A `RevoltRunner` given to `from()` is shared by every traversal of every operator of that collection: its own `concurrency` caps them together, which is how a pipeline of several stages respects one rate limit of an API. Each operator still keeps its window of `concurrency` items.

<!-- tested: runner -->
```php
<?php

declare(strict_types=1);

use Antevemus\ALinqRevolt\ALinqAsyncCollection;
use Antevemus\ALinqRevolt\RevoltRunner;

use function Amp\delay;

$api = new RevoltRunner(concurrency: 3); // at most 3 calls to this API at a time, across the stages
$running = 0;
$peak = 0;
$call = function (int $n) use (&$running, &$peak): int {
    $peak = max($peak, ++$running);
    delay(0.01);
    $running--;
    return $n;
};

$users = ALinqAsyncCollection::from(range(1, 10), $api)->selectAsync($call, concurrency: 8);
$scores = ALinqAsyncCollection::from($users, $api)->selectAsync($call, concurrency: 8)->toArray();

$scores; // [1, 2, 3, 4, 5, 6, 7, 8, 9, 10]
$peak; // 3
```

`RevoltRunner` can be used on its own: `start(Closure $task, ?float $timeout = null, ?Cancellation $cancellation = null): Future` runs `$task(Cancellation)` as soon as a slot is free (suspending the calling Fiber, or running the loop outside one), `cancel()` cancels every task it started, `running()` counts the tasks running. A closure that iterates an asynchronous collection with the same shared runner inside its own task can wait for a slot its own caller holds: give nested work its own runner.

## Back to the core

`lazy()` gives the source as a core lazy collection (the lazy collection itself, the eager collection's `lazy()`, or `ALinqLazyCollection::from()` of the array or `Traversable`), and `collection()` gives it as an eager `ALinqCollection` (reading a lazy source). Both are synchronous: the asynchronous work happens only in the four operators.
