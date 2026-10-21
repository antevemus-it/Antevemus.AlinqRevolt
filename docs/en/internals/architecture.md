# Architecture

Why the package runs on Revolt and Amp, how it sits on the core without the core knowing any event loop, and what a companion for another event loop implements. This page is prose only: the behavior it describes is shown with tested code in [How it works](../concepts/how-it-works.md) and [Asynchronous operators](../api/async-operators.md).

## Why Revolt and Amp, not ReactPHP

Four reasons, in order of weight:

1. **Native Fibers, no promises.** An operator suspends the Fiber while the window waits for its head and comes back with the value, so its result is a plain lazy collection of the core, traversed by `foreach`, `toArray()` or any core operator. Amp 3 was rewritten on Fibers: `async()` starts one, `Future::await()` suspends until the value is there, a `Cancellation` ends the wait. ReactPHP's main model is promises and callbacks; `react/async` 4 adds `await()` on Fibers, but cancellation and timeouts remain in the promise API, and the window would need an adaptation layer between them and its own cancellation.
2. **Revolt is the common denominator.** Amp 3 runs on the Revolt event loop, and ReactPHP runs on Revolt through the official adapter `revolt/event-loop-adapter-react`. The reverse does not exist: nothing runs Revolt on ReactPHP's loop. A ReactPHP application runs this package on the same loop as its own code; a package built on ReactPHP's loop would have left Amp applications out.
3. **Cancellation matched to the window.** The `Amp\Cancellation` that reaches the user's closure as its third parameter is the one the window cancels when the consumer stops early or the item's time limit runs out, so a closure that passes it to an Amp client aborts the request itself. It is the model of the core's `fromHttpPages()`, which cancels its `curl_multi` request in flight when the consumer stops.
4. **Weight.** Two small packages at run time: `revolt/event-loop` and `amphp/amp`. On ReactPHP it would be `react/event-loop`, `react/promise` and `react/async`, plus the adapter to reach the applications that run on Revolt.

## The core's hook knows no event loop

This package is a wrapper. `ALinqAsyncCollection` holds a source, and each of its operators returns a core `ALinqLazyCollection` built with `ALinqLazyCollection::from(Closure $source, ?bool $isList)`, the parameter added in ALinq 2.3.0: the Closure is a generator factory that runs the window on every traversal, and `$isList` declares the shape of what it yields. The shape it declares is the shape of its source: `array_is_list()` of an array, `isList()` of an eager collection, and `isList()` of a lazy collection, also added in 2.3.0, which answers `true`, `false` or `null` when unknown, so a wrapper can pass it on.

That is the whole hook: a generator factory and a declared shape. No class of the core uses Revolt, Amp or Fibers, and the core does not require them; the event loop, the Fibers and the cancellation live in this package. The core's side is in [Streaming & Big Data Pipelines](https://github.com/antevemus-it/Antevemus.AlinqCollection/blob/main/docs/en/api/lazy-streaming.md) of the core's documentation; the window this package runs behind the factory, in [How it works](../concepts/how-it-works.md).

## What a companion for another event loop implements

A package that brings these operators to another event loop needs nothing from the core beyond the hook above, and nothing from this package. What it implements, as this package does:

- **A wrapper over the source**: any iterable, an `IALinqCollection` or an `IALinqLazyCollection`. The core classes are final, so it wraps them and never extends them. It reads the shape of the source when it wraps it: `array_is_list()` of an array, `isList()` of an eager or a lazy collection; any other `Traversable`, a `Generator` included, has an unknown shape, in the core as here.
- **Operators that return `ALinqLazyCollection::from(Closure, $isList)`**, declaring the shape of their result: `true`, a list (renumbered by the core's operators, `toArray()` a list); `false`, a dictionary (its keys kept by `toArray()`, an integer-keyed one included); `null`, unknown (the keys as yielded, `toArray()` a list when every key is an integer). Here, `selectAsync()` and `whereAsync()` declare the shape of their source and renumber a list, as the core's `select()` and `where()` do; `selectManyAsync()` declares a list, as the core's `selectMany()`; `eachAsync()` is terminal. The core accepts the declaration only with a Closure: with an array or another iterable it is refused, since their shape comes from the source itself.
- **The ordered, bounded, lazy window**, the behavior the consumers of these operators expect: results in source order whatever order the work finishes in, at most `concurrency` items started and not yet delivered, the source pulled only when the window has room, and the window run again on every traversal.
- **Cancellation on early stop**: when the consumer stops (`take()`, `first()`, `any()`, a `break`, an exception downstream), the generator's `finally` cancels the items in flight, and an item that has not begun never runs.
- **The error at the item's position**: a failure is delivered after the items before it were handed over, with the items in flight cancelled; here, an `ALinqAsyncException` that carries the key, the position and the original exception.

Neither the core nor this package changes for such a companion: it brings its own runner, on its own loop.

## ReactPHP today

A ReactPHP application runs this package through the adapter: with `revolt/event-loop-adapter-react` installed, ReactPHP's loop runs on Revolt, the loop the operators use, so the application and the window share one event loop.

A dedicated companion for ReactPHP, an `alinq-react` package on promises and ReactPHP's cancellation, would be a new package of about the size of this one, built on the same hook, touching neither the core nor this package. It is not planned; it would be built on demand ([Roadmap](../project/roadmap.md)).
