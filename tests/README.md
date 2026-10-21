# Antevemus ALinq Revolt — Test Suite

PHPUnit 11 test suite of **Antevemus ALinq Revolt**, the asynchronous companion of Antevemus ALinq Collection.

## 📊 Overview & Metrics

- **Test Framework:** PHPUnit 11.5+ (PHP 8.4+), `phpunit.xml.dist` with `failOnWarning`, `failOnDeprecation` and `failOnRisky`
- **Test Files:** 6 test classes in `tests/Unit` (4), `tests/Property` (1) and `tests/Http` (1), plus the fixtures of `tests/Support`
- **Total Tests:** 99 tests (1.0.1)
- **Total Assertions:** 4436 assertions, none skipped
- **Success Rate:** 100% Passing (0 failures, 0 errors, 0 deprecations)
- **Runtime Dependencies:** `antevemus/alinq-collection` ^2.3, `revolt/event-loop` ^1.0, `amphp/amp` ^3.0; the tests also use `amphp/http-server` and `amphp/http-client` (require-dev)

---

## 🏛️ Test Suite Structure

### 1. `tests/Unit/RevoltRunnerTest.php` (16 tests)
`RevoltRunner`, on its own:
- Concurrency below 1 and a timeout that is not a positive finite number are refused (a refused task takes no slot); the default concurrency is 8
- A task's value, a returned `Future` awaited, a task that suspends; its exception settles the future as it is
- No more than the limit run at once and the waiting callers start in arrival order; inside a Fiber the waiting caller is suspended, not blocked
- The time limit settles with a `CancelledException` carrying a `TimeoutException` (`The time limit of 0.02 s was exceeded`) and the task receives the cancellation; a task that ignores its cancellation times out but keeps its slot until it returns; a task within its limit is not cancelled; a returned `Future` is abandoned on timeout
- The cancellation passed in cancels only its tasks; `cancel()` cancels everything in flight and later tasks run

### 2. `tests/Unit/ALinqAsyncCollectionTest.php` (49 tests)
`ALinqAsyncCollection` and `ALinqAsyncException`:
- Values, keys and order: source order whatever finishes first; a core `ALinqLazyCollection` that chains; the core arity rule (one parameter: the item alone; two: item and key; a third: the `Cancellation`; internal functions never receive it); the streamed keys of the core `select()` over lists, string, mixed and repeated keys; `toArray()` keeping the shape of the source as the core does (an integer-keyed dictionary, array, eager or lazy, keeps its keys, through `whereAsync()` and the core operators downstream too; a lazy list declared over a generator is renumbered while streaming, as the core `select()`; a `Generator` and an undeclared lazy factory have the core's unknown shape); `whereAsync()` renumbering a known list only; truthy answers; `selectManyAsync()` flattening in order into a list and refusing a non-iterable at its position; `eachAsync()` running every action, throwing the first failure in source order; an empty source; a returned `Future`; every kind of callable and source; every traversal runs again and a `Generator` is read once (`Cannot traverse an already closed generator`, as the core); inside a Fiber
- The window: nothing runs before the traversal; the source pulled one item per free slot (head k delivered with k + 4 items read at concurrency 3); never more than `concurrency` in flight (1, 2, 5, and the default 8 for each operator); a slow head holds the window; early stop cancels what is in flight and an item whose Fiber had not begun never runs; `take(3)`, `first()`, `any()`, `break` start at most the consumed items plus `concurrency` and leave nothing running
- Errors and time limits: an exception at its position with the items before delivered and the one in flight cancelled, code 0, a message without the item's value nor the original message; keys that are not int or string named by their type; a time limit at its position, counted from the item's start and not from when the window reaches it; a closure's own `CancelledException` is not a timeout; an error of the source comes after the items before it, unwrapped
- Arguments: concurrency below 1 and non-positive, infinite or NaN timeouts refused when the operator is called, before any closure runs
- A shared runner caps two stages together (peak 3 with windows of 8); without one, each traversal has its own window

### 3. `tests/Property/AsyncPropertyTest.php` (3 tests)
Properties with the core as the oracle, 200 seeded inputs each (fixed seeds), lists and dictionaries of 0-12 items, concurrency 1-10, a random non-blocking delay per item (the closures finished out of source order in 128 of the 200 inputs of the first property; at least one in five is required):
- `selectAsync()`/`whereAsync()`/`selectManyAsync()` stream the keys, values and order of the core `select()`/`where()`/`selectMany()`, and `toArray()` is the core's over the same array, integer-keyed dictionaries included, over a lazy ALinq collection of it (with `whereAsync()`'s stream too) and over an `ArrayIterator` of the same items; `eachAsync()` runs each action once
- `take(k)` after `selectAsync(f, c)` answers as the core `take(k)` and starts at most k + c items
- `where`, `orderBy`, `concat` and `groupBy` downstream answer as over the synchronous `select()`

### 4. `tests/Http/ProofOfGainTest.php` (4 tests, group `http`)
The proof of gain against a non-blocking Amp HTTP server started on a free port of 127.0.0.1 in a child process (`tests/Support/latency-server.php`, `TCP_NODELAY`), 16 items of `GET /item?ms=200`: the core `select()` with `file_get_contents()` above 2 500 ms; `selectAsync()` with the Amp HTTP client below 800 ms at concurrency 8 and below 600 ms at 16, never below the rounds it needs; concurrency 16 at least three times faster than the synchronous `select()`; the same blocking closure under `selectAsync(16)` above 2 500 ms. Every failure message carries the measured times; if the server cannot start, the tests are skipped with the reason. They take about 7 s; exclude them with `--exclude-group http`. The table of the documentation comes from `tests/Support/timing-table.php`.

### 5. `tests/Unit/DocumentationBlocksTest.php` (19 tests)
Runs the 8 PHP blocks of `docs/en` and `docs/pt-BR`, marked `<!-- tested: id -->`, and the Quick Start of each README, as written, and checks every answer in their comments (`// [1, 4]`, `// 'b'`, `// true (...)`, `// Result: [...]`, `// throws Class: message`); a guard checks that both languages carry the same blocks on the same pages with the same code (comments aside) and the same answers.

### 6. `tests/Unit/DocumentationTreeTest.php` (8 tests)
The guard of the documentation, adapted from ALinq's: `docs/en` and `docs/pt-BR` have the same files; both `docs-nav.json` parse, have the same shape and list every page; every relative link resolves; every page has the same code fences and PHP blocks in both languages, token by token; the PHP blocks of each page are the declared ones (8 per language); each README has one code block, the Quick Start, and stays below 25 000 bytes; every PHP block carries a tested marker; no page or README cites internal material or links outside the language folders but to the public root files.

---

## 🚀 Running the Tests

```bash
# The whole suite (Xdebug off: a debugger session would make the suite wait)
XDEBUG_MODE=off vendor/bin/phpunit --no-coverage

# Without the proof of gain (no local server, about 7 s less)
XDEBUG_MODE=off vendor/bin/phpunit --no-coverage --exclude-group http

# The timing table of the documentation
XDEBUG_MODE=off php tests/Support/timing-table.php 3

# Mutation testing
XDEBUG_MODE=off vendor/bin/infection --threads=max --only-covering-test-cases \
    --initial-tests-php-options="-d pcov.enabled=1 -d pcov.directory=$PWD/src"
```

---

## 📄 License & Copyright

- **License:** MIT License — see [LICENSE](../LICENSE)
- **Copyright:** Copyright (c) 2026 Antevemus Soluções Inovadoras em TI Ltda.
