# 🧪 Tests

The suite runs on PHPUnit 11 under PHP 8.4, with no PHP extension required:

```bash
XDEBUG_MODE=off vendor/bin/phpunit --no-coverage
```

```text
PHPUnit 11.5.57 by Sebastian Bergmann and contributors.

Runtime:       PHP 8.4.13
Configuration: phpunit.xml.dist

................................................................. 65 / 99 ( 65%)
..................................                                99 / 99 (100%)

Time: 00:11.438, Memory: 8.00 MB

OK (99 tests, 4436 assertions)
```

- **99 tests and 4436 assertions**, none skipped, in three suites: `tests/Unit` (the runner, the four operators, the documentation), `tests/Property` (properties against the core as the oracle) and `tests/Http` (the proof of gain, group `http`). The file-by-file account is in `tests/README.md`.
- **The runner** (`RevoltRunnerTest`): the value of a task, a returned `Future` awaited, its exception, no more than `concurrency` running with the waiting callers served in arrival order (inside a Fiber, the caller is suspended, not blocked), the time limit as a `CancelledException` carrying a `TimeoutException`, a task that ignores its cancellation keeping its slot until it returns, the cancellation passed in, `cancel()`.
- **The operators** (`ALinqAsyncCollectionTest`): values, keys and order (against the core's `select()`/`where()`/`selectMany()`, including the integer-keyed dictionary, whose keys `toArray()` keeps as the core does), laziness (nothing runs before the traversal; the source pulled one item per free slot, measured item by item), the window never above `concurrency` (counters at 1, 2, 5 and the default 8 for each operator), early stop (`take()`, `first()`, `any()`, `break`) starting at most the consumed items plus `concurrency` and cancelling what is in flight, errors and time limits at the item's position with the items before delivered, an error of the source unwrapped, the argument checks, every kind of source and callable, a shared runner capping two stages together.
- **Properties** (`AsyncPropertyTest`, 200 seeded inputs per property, fixed seeds): lists and dictionaries of 0-12 items, concurrency 1-10, a random non-blocking delay per item, so the closures finish out of source order (measured: in 128 of the 200 inputs of the first property). `selectAsync()`/`whereAsync()`/`selectManyAsync()` stream the keys, values and order of the core `select()`/`where()`/`selectMany()` and `toArray()` answers as the core over the same array (integer-keyed dictionaries included), over a lazy ALinq collection of it and over an `ArrayIterator` of the same items; `eachAsync()` runs each action once; `take(k)` starts at most k + concurrency items; `where`, `orderBy`, `concat` and `groupBy` downstream answer as over the synchronous `select()`.
- **The documentation** (`DocumentationBlocksTest`, `DocumentationTreeTest`): the 8 PHP blocks of each language and the Quick Start of each README run as written, with every answer in their comments checked; both languages have the same pages, navigation and code, token by token; every link resolves; nothing internal leaks.

## Proof of gain

`tests/Http/ProofOfGainTest.php` starts a non-blocking Amp HTTP server on a free port of 127.0.0.1 in a child process (`tests/Support/latency-server.php`; `GET /item?ms=200` answers after 200 ms without blocking) and runs 16 items. It asserts, with generous bounds and the measured times in every failure message: the core `select()` with a blocking client (`file_get_contents()`) above 2 500 ms; `selectAsync()` with the Amp HTTP client below 800 ms at concurrency 8 and below 600 ms at 16 (and never below the rounds it needs: 2 × 200 and 1 × 200 ms); concurrency 16 at least three times faster than the synchronous `select()`; the same blocking closure under `selectAsync(concurrency: 16)` above 2 500 ms. When the server cannot start, the tests are skipped with the reason, never passed.

The table, measured with `XDEBUG_MODE=off php tests/Support/timing-table.php 3` (median of 3 runs per cell, PHP 8.4.13, a new Amp client per run):

| Items × 200 ms | core select(), blocking | selectAsync(16), blocking | selectAsync(1), Amp client | selectAsync(8), Amp client | selectAsync(16), Amp client |
| ---: | ---: | ---: | ---: | ---: | ---: |
| 1 | 201 ms | 201 ms | 203 ms | 202 ms | 202 ms |
| 4 | 807 ms | 807 ms | 809 ms | 204 ms | 204 ms |
| 16 | 3 222 ms | 3 225 ms | 3 243 ms | 415 ms | 214 ms |

- **Why each number:** a blocking call holds the only thread, so n items take n × 200 ms whatever the concurrency (the first two columns, and concurrency 1, where the window holds one item). With the Amp client, `concurrency` requests wait together: 16 items take ⌈16 / c⌉ rounds of 200 ms (415 ms at 8 for two rounds, 214 ms at 16 for one); 4 items fit in one round at 8 and 16. The few milliseconds above each multiple of 200 are the requests themselves over loopback.
- **A fixture finding:** without `TCP_NODELAY` on the server socket, each request of the Amp client cost 44 ms more (measured at `ms=0`: 43.9 ms per request with the Amp client, 1.0 ms with `file_get_contents()`; 1.3 ms with `TCP_NODELAY`). The Amp client keeps the connection alive, and Nagle's algorithm held the body of the answer until the client acknowledged its headers, an acknowledgement the client delays; `file_get_contents()` closes the connection, which flushes. The server of the tests sets `TCP_NODELAY`; the overhead belonged to the fixture, not to what is measured.

## Mutation testing

Infection over the whole `src/` (`infection.json5`, the Quality workflow), the group `http` left out because it measures wall-clock time:

```bash
XDEBUG_MODE=off vendor/bin/infection --threads=max --only-covering-test-cases \
    --initial-tests-php-options="-d pcov.enabled=1 -d pcov.directory=$PWD/src"
```

Measured on 2026-10-20 (Infection 0.35.8, PHP 8.4.13 with pcov): **177 mutants; 168 killed by the tests, 1 error and 4 time-outs (detected), 4 escaped, all equivalent; MSI 97.74%** (covered-code MSI 97.74%, mutation code coverage 100%). The errors and time-outs are mutants that break the loop of the window (the source pulled again forever, or never advanced) or the hand-over of a free slot; between runs one of them (the removed `suspend()` of `RevoltRunner::start()`) has been classified as a time-out or as an error, with the same score. The mutant that declared the result of `selectManyAsync()` a dictionary instead of a list escaped the first run of this version and is killed by a test of the core `where()` after it. The 4 escaped mutants:

- removing `$scope->cancel()` in the `finally` of the window, and removing `$everything->cancel()` in `RevoltRunner::cancel()`: both `Amp\DeferredCancellation` objects are released right after, and its destructor cancels;
- removing the `unsubscribe()` of the combined cancellation when a task ends: that cancellation is released with the task;
- turning the `finally` of a task into plain code after its `try`/`catch`: the `catch (Throwable)` already receives every exception; the two differ only when a suspended Fiber is destroyed, which no caller can trigger through the API.

`minMsi` and `minCoveredMsi` are 96 in `infection.json5`, one integer point below the measured score.
