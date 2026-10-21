# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [1.0.1] - 2026-10-21

A PATCH release with documentation only: a new page explains why the package runs on Revolt and Amp, how it sits on the core, and what a companion for another event loop implements. No change in `src/`.

### Documentation

- **`docs/<language>/internals/architecture.md`** (new, both languages; "Architecture" / "Arquitetura"), prose only, no PHP block: why Revolt and Amp and not ReactPHP (native Fibers instead of promises, with cancellation and timeouts that the window uses as they are; Revolt as the loop both Amp 3 and ReactPHP, through `revolt/event-loop-adapter-react`, run on; the `Amp\Cancellation` that reaches the closure being the one the window cancels; two small run-time packages); the core's hook, `ALinqLazyCollection::from(Closure, ?bool $isList)` and `isList()` (ALinq 2.3.0), which knows no event loop; what a companion for another event loop implements (the wrapper, the operators that declare their shape, the ordered, bounded, lazy window, cancellation on early stop, the error at the item's position); ReactPHP today, through the adapter, and a dedicated companion that is not planned.
- **Navigation**: a new section, "Internals" / "Internos", with the page, between "API reference" and "Quality" in both `docs-nav.json`; the page is listed in `docs/<language>/index.md` and in both READMEs.
- **Roadmap**: `docs/<language>/project/roadmap.md` and `ROADMAP.md` (milestone 4) carry an unchecked item: a companion for another event loop (ReactPHP, ...) is not planned; it would be a separate package on the same hook of the core, built on demand, and its contract is the Architecture page.
- The Release badge of both READMEs is v1.0.1; the Tests badge, the Quality lines, `quality/tests.md` (both languages) and `tests/README.md` carry the counts of 1.0.1.

### Notes

- **Suite**: 99 tests, 4370 → 4436 assertions, none skipped (PHP 8.4.13, against ALinq 2.3.0, the group `http` included). +66 assertions, all in `DocumentationTreeTest` (388 → 454), from the new page and the links to it, the tests unchanged: the navigations +18 (9 per language: 8 checks of the new group and its item, 1 for the file of its path), the relative links +16 (4 on the new page, 1 on each index, 1 on each roadmap page and 2 in each README, per language), the internal-material guard +24 (4 checks per new file, for 2 files, and 1 per new relative link, for 16), the tested blocks +4 (2 per new page), the code of both languages +2 (the fences and the block count of the new page), the declared PHP blocks +2 (0 on the new page, per language). The other classes keep their counts.
- **No code change**: `src/`, the tests and the workflows are unchanged.

## [1.0.0] - 2026-10-20

The first release: the asynchronous companion of `antevemus/alinq-collection` (ALinq), milestone 12 of the ALinq roadmap ("Async collection for async SDKs"). It requires ALinq **2.3.0** (`^2.3`): its stages are built with `ALinqLazyCollection::from($factory, ?bool $isList)`, the parameter that release added to declare the shape of a generator stage, and read the shape of a lazy source with `IALinqLazyCollection::isList()`, also added there.

### Added

- **`ALinqAsyncCollection`** (final). `ALinqAsyncCollection::from(iterable|IALinqCollection|IALinqLazyCollection $source, ?RevoltRunner $runner = null)` wraps an array, an eager or lazy ALinq collection or any `Traversable` (a `Generator` is read once, as in the core); the core classes are final, so it wraps them and never extends them. `lazy()` and `collection()` give the source back as a core lazy or eager collection.
- **`selectAsync()`, `whereAsync()`, `selectManyAsync()`** (`callable $callback, int $concurrency = 8, ?float $timeout = null`), each returning a core `ALinqLazyCollection` built over a generator, so every core operator chains after them; **`eachAsync()`**, terminal (`void`).
- **The ordered, bounded, lazy window.** Each item's closure runs in its own Fiber (`Amp\async()`); at most `concurrency` items are started and not yet delivered; results come out in source order; the source is pulled one item per free slot, and the window is filled again before the head is handed over, so taking k results starts at most k + `concurrency` items. Stopping early (`take()`, `first()`, `any()`, a `break`, an exception downstream) ends the generator and its `finally` cancels the items in flight; an item that entered the window but whose Fiber had not begun never runs its closure. Every traversal runs the window again.
- **The closure** receives `($item, $key)` by the core's arity rule (`ALinqCallable::withKey()`); a user closure that declares a third parameter also receives the item's `Amp\Cancellation` (time limit or early stop), to pass to an Amp client. It may return a value, an `Amp\Future`, or suspend.
- **Keys as the core's `select()`/`where()`/`selectMany()`**: `selectAsync()` and `whereAsync()` renumber a source known to be a list and keep the keys otherwise; `selectManyAsync()` yields a list. The shape of the source is the one the core knows (`array_is_list()` of an array, `isList()` of an eager or a lazy collection), and each stage declares it to the core with `ALinqLazyCollection::from($factory, $isList)`, so `toArray()` and the core operators after it answer as the core over the same source: a list, or a dictionary whose keys are kept, an integer-keyed one (`[$id => $row]`) included. A `Generator` or another `Traversable` has no known shape, in the core as here.
- **`ALinqAsyncException`** (final, extends `RuntimeException`): the failure of one item, delivered at its position after the items before it, the items in flight cancelled. `getKey()`, `getPosition()`, `getOperator()`, `isTimeout()`, the original as `getPrevious()`. The message names the operator, the position, the key and the class of the original, never the item's value nor the original message. `selectManyAsync()` refuses a non-iterable result the same way, with the core's `UnexpectedValueException` message as the previous. An exception of the source itself comes unwrapped, after the items read before it.
- **Per-item time limits**: `timeout` in seconds from the item's start, with an `Amp\TimeoutCancellation`; an item past its limit fails at its position (`isTimeout()`), even if it finishes before the window reaches it.
- **`RevoltRunner`** (final), free of ALinq types: `start(Closure $task, ?float $timeout = null, ?Cancellation $cancellation = null): Future` runs `$task(Cancellation)` once one of its `concurrency` slots is free (suspending the calling Fiber, or running the loop outside one), `cancel()`, `running()`, `concurrency()`. It uses the application's Revolt loop and never creates or replaces a driver. One per traversal by default; a runner given to `from()` caps every operator of that collection together.
- **Argument checks** when the operator is called: `concurrency < 1` and a `timeout` that is not a positive finite number throw `InvalidArgumentException`.
- **Documentation** in `docs/en` and `docs/pt-BR` (Docs Kit format): Getting started, How it works, Asynchronous operators, Tests, Roadmap; the README of each language is an index whose only code is the Quick Start. Every one of the 8 PHP blocks per language is run by `DocumentationBlocksTest` with its written answers, and `DocumentationTreeTest` (adapted from ALinq's) keeps the two trees aligned.
- **CI**: `tests.yml` (PHP 8.4, `composer validate --strict`, PHPUnit) and `quality.yml` (Infection over the whole `src/`, with a minimum MSI).

### Notes

- **Suite**: 99 tests and 4370 assertions, none skipped (PHP 8.4.13, against ALinq 2.3.0): `ALinqAsyncCollectionTest` 49 tests / 173 assertions, `RevoltRunnerTest` 16 / 50, `AsyncPropertyTest` 3 / 3601 (200 seeded inputs per property), `ProofOfGainTest` 4 / 17 (group `http`, a local Amp server, about 7 s), `DocumentationBlocksTest` 19 / 141 (the 8 blocks of each language and the Quick Start of each README), `DocumentationTreeTest` 8 / 388.
- **Mutation testing**: Infection 0.35.8 over the whole `src/` (PHP 8.4.13, pcov, the group `http` left out): 177 mutants, 168 killed, 1 error and 4 time-outs (detected), 4 escaped, all equivalent (two `cancel()` calls whose `DeferredCancellation` destructor cancels anyway, an `unsubscribe()` of a cancellation released with the task, a `finally` whose `catch (Throwable)` already receives every exception); MSI 97.74%. `minMsi`/`minCoveredMsi` 96.
- **Measured gain** (`XDEBUG_MODE=off php tests/Support/timing-table.php 3`, median of 3 runs, PHP 8.4.13, a local Amp HTTP server answering in 200 ms): 16 items take 3 222 ms through the core `select()` with a blocking client, 3 243 ms through `selectAsync(concurrency: 1)` with the Amp client, 415 ms with `concurrency: 8`, 214 ms with `concurrency: 16`, and 3 225 ms through `selectAsync(concurrency: 16)` with the blocking client. The full table: `docs/en/quality/tests.md`.
