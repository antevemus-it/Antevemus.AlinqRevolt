# How It Works

## The ordered, bounded, lazy window

Each asynchronous operator traverses its source through a window of at most `concurrency` items:

1. **Fill.** The operator pulls items from the source one at a time and starts the closure of each one in its own Fiber (`RevoltRunner::start()`, over `Amp\async()`), until the window holds `concurrency` items or the source ends.
2. **Wait for the head.** It waits for the oldest item of the window. The other items keep running in the meantime; an item that finishes before the head waits in the window.
3. **Refill, then hand over.** When the head completes, the window is filled again (one more item is pulled and started) and only then is the head's result handed to the next stage. Back to step 2.

Three properties follow:

- **Ordered.** Results come out in source order, whatever order the closures finish in. A slow head holds the window: items behind it may have finished, but no new item starts until the head is handed over.
- **Bounded.** At most `concurrency` items are started and not yet delivered. Memory holds at most that many results.
- **Lazy.** Nothing runs until the stage is traversed, and the source is pulled only when the window has room. Consuming k results starts at most k + `concurrency` items.

Waiting means `Amp\Future::await()`: outside a Fiber it runs the Revolt event loop until the head is ready; inside a Fiber (in an Amp server, or inside another closure of this package) it suspends that Fiber and lets the loop run the rest of the application.

## Stopping early cancels what is in flight

`take()`, `first()`, `any()`, a `break` out of `foreach`, or an exception downstream end the stage's generator. Its `finally` cancels the items in flight through their `Amp\Cancellation`; an item that entered the window but whose Fiber had not begun yet never runs its closure. This is the model of the core's `fromHttpPages()`, which cancels the request of the next page when the consumer stops.

<!-- tested: window -->
```php
<?php

declare(strict_types=1);

use Amp\Cancellation;
use Amp\CancelledException;
use Antevemus\ALinqRevolt\ALinqAsyncCollection;

use function Amp\delay;

$read = 0;
$started = 0;
$cancelled = 0;
$source = (function () use (&$read) {
    foreach (range(1, 1000) as $n) {
        $read++;
        yield $n;
    }
})();

$squares = ALinqAsyncCollection::from($source)->selectAsync(
    function (int $n, int $key, Cancellation $cancellation) use (&$started, &$cancelled): int {
        $started++;
        try {
            delay($n / 100, cancellation: $cancellation); // item n takes n × 10 ms
        } catch (CancelledException $e) {
            $cancelled++;
            throw $e;
        }
        return $n * $n;
    },
    concurrency: 3,
);

$squares->take(2)->toArray(); // [1, 4]
$read; // 5
$started; // 4
delay(0.001); // the cancellations arrive on the next turn of the event loop
$cancelled; // 2
```

Items 1, 2 and 3 start together. When item 1 is ready, item 4 is pulled and started and 1 is handed over; when item 2 is ready, item 5 is pulled and 2 is handed over, and `take(2)` stops. Items 3 and 4 were running and are cancelled; item 5 was cancelled before its Fiber began, so its closure never ran; items 6 to 1000 were never read.

## Fibers gain time only on non-blocking I/O

A Fiber is a cooperative coroutine on the one PHP thread: it gives the thread back only when it waits on something the event loop watches (a socket of an Amp client, a timer of `Amp\delay()`). Overlapping those waits is the whole gain. A blocking call (`file_get_contents()`, `curl_exec()`, `sleep()`, PDO) holds the thread until it returns, and CPU work never gives it back, so under any `concurrency` they run one after the other:

<!-- tested: blocking -->
```php
<?php

declare(strict_types=1);

use Antevemus\ALinqRevolt\ALinqAsyncCollection;

use function Amp\delay;

$started = microtime(true);
ALinqAsyncCollection::from([1, 2, 3, 4])->eachAsync(fn() => delay(0.05), concurrency: 4);
$nonBlocking = microtime(true) - $started; // about 0.05 s: the four waits overlapped

$started = microtime(true);
ALinqAsyncCollection::from([1, 2, 3, 4])->eachAsync(fn() => usleep(50_000), concurrency: 4);
$blocking = microtime(true) - $started; // about 0.2 s: usleep() holds the thread, one call after the other

$nonBlocking < 0.15; // true
$blocking > 0.19; // true
```

The same holds over the network: against a local HTTP server that answers in 200 ms, 16 requests take about 3 200 ms through the core `select()` with a blocking client, about 3 200 ms through `selectAsync()` with the same blocking client, and about 200 ms through `selectAsync(concurrency: 16)` with the Amp HTTP client. The measured table and the test that asserts it: [Tests](../quality/tests.md).

For blocking clients, the core already has the zero-dependency answer where it applies: `ALinqLazyCollection::fromHttpPages()` downloads the next page with `curl_multi` while the current one is consumed. For CPU-bound work, Fibers are the wrong tool: it needs worker processes.

## Errors and time limits

An exception of a closure is delivered at the position of its item, as an `ALinqAsyncException` that carries the key, the position and the original exception (`getPrevious()`): the items before it were already handed over, the items in flight are cancelled. A `timeout` limits each item from its start (an `Amp\TimeoutCancellation`) and fails it the same way, with `isTimeout()`. Details and examples: [Asynchronous operators](../api/async-operators.md#errors).

## The two classes and the runner

| Class | Role |
| :--- | :--- |
| `ALinqAsyncCollection` (final) | Wraps a source (array, eager or lazy ALinq collection, `Traversable`) and offers `selectAsync()`, `whereAsync()`, `selectManyAsync()`, `eachAsync()`; `lazy()` and `collection()` give the core back. The core classes are final: it wraps them, it never extends them. |
| `RevoltRunner` (final) | Runs closures under the Revolt loop with a concurrency limit and a per-item time limit; free of ALinq types. One per traversal by default; one shared runner caps several operators together. |
| `ALinqAsyncException` (final) | The failure of one item at its position: `getKey()`, `getPosition()`, `getOperator()`, `isTimeout()`. |
