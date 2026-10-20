# Antevemus ALinq Revolt

[![CI](https://github.com/antevemus-it/Antevemus.AlinqRevolt/actions/workflows/tests.yml/badge.svg)](https://github.com/antevemus-it/Antevemus.AlinqRevolt/actions/workflows/tests.yml)
[![Latest Stable Version](https://img.shields.io/badge/release-v1.0.0-blue.svg)](https://github.com/antevemus-it/Antevemus.AlinqRevolt/releases)
[![PHP Version](https://img.shields.io/badge/php-%3E%3D8.4-8892BF.svg)](https://www.php.net/)
[![License: MIT](https://img.shields.io/badge/License-MIT-yellow.svg)](https://opensource.org/licenses/MIT)
[![Tests Passing](https://img.shields.io/badge/tests-99%20passed%20%7C%204370%20assertions-success.svg)](docs/en/quality/tests.md)
[![Core: ALinq](https://img.shields.io/badge/Core-Antevemus.AlinqCollection-orange)](https://github.com/antevemus-it/Antevemus.AlinqCollection)

> **The asynchronous companion of [ALinq](https://github.com/antevemus-it/Antevemus.AlinqCollection) for PHP 8.4+**  
> `selectAsync()`, `whereAsync()`, `selectManyAsync()` and `eachAsync()` over the Revolt event loop: each item's closure runs in its own Fiber (Amp futures), through an ordered, bounded, lazy window of items in flight, and the result is a core ALinq lazy collection, so every core operator chains after it. For per-item I/O against non-blocking clients (the Amp HTTP client, async SDKs).

---

[🇺🇸 English](README.md) • [🇧🇷 Português (Brasil)](README.pt-BR.md)

---

The documentation lives in [`docs/en`](docs/en/index.md) (English) and [`docs/pt-BR`](docs/pt-BR/index.md) (Portuguese, Brazil), one page per topic, in the Docs Kit format (a `docs-nav.json` per language), and every PHP block of the pages is run by the test suite.

---

## 🚀 Quick Start

The same block opens [Getting Started](docs/en/getting-started/index.md), and the test suite runs it with the answers written in its comments:

<!-- tested: quick-start -->
```php
<?php

declare(strict_types=1);

use Antevemus\ALinqRevolt\ALinqAsyncCollection;

use function Amp\delay;

$started = microtime(true);

// Eight calls to a slow service; Amp\delay() stands for an Amp HTTP client or an async SDK
$activeIds = ALinqAsyncCollection::from([101, 102, 103, 104, 105, 106, 107, 108])
    ->selectAsync(function (int $id): array {
        delay(0.1); // 100 ms of non-blocking I/O
        return ['id' => $id, 'active' => $id % 2 === 1];
    }, concurrency: 8)
    ->where(fn(array $profile) => $profile['active']) // the core operators chain after it
    ->select(fn(array $profile) => $profile['id'])
    ->toArray();

// Result: [101, 103, 105, 107]

$elapsed = microtime(true) - $started;
$elapsed < 0.4; // true (the eight waits overlapped: about 0.1 s, not 0.8 s)
```

---

## 📦 Installation

Install via Composer: `composer require antevemus/alinq-revolt`. Requirements: PHP 8.4 or later; installed with it: [`antevemus/alinq-collection`](https://github.com/antevemus-it/Antevemus.AlinqCollection) `^2.3` (the core, same author; 2.3.0 for the declared shape of a generator stage), [`revolt/event-loop`](https://github.com/revoltphp/event-loop) `^1.0` and [`amphp/amp`](https://github.com/amphp/amp) `^3.0`. No PHP extension is required.

A Fiber only gains time while the code inside it waits on **non-blocking** I/O: blocking calls (`file_get_contents()`, `curl_exec()`, PDO) and CPU work run one after the other, whatever the concurrency. Measured against a local HTTP server, 16 requests of 200 ms: 3 222 ms through the core `select()` with a blocking client, 214 ms through `selectAsync(concurrency: 16)` with the Amp HTTP client, 3 225 ms through `selectAsync()` with the blocking client ([Tests](docs/en/quality/tests.md)).

---

## 📚 Documentation

**Getting started**

- [Installation and quick start](docs/en/getting-started/index.md) · [pt-BR](docs/pt-BR/getting-started/index.md): Composer, the requirements and the Quick Start.

**Concepts**

- [How it works](docs/en/concepts/how-it-works.md) · [pt-BR](docs/pt-BR/concepts/how-it-works.md): The ordered, bounded, lazy window, early stop and cancellation, and why Fibers gain time only on non-blocking I/O.

**API reference**

- [Asynchronous operators](docs/en/api/async-operators.md) · [pt-BR](docs/pt-BR/api/async-operators.md): The four operators, the closure, keys, errors, time limits and a shared `RevoltRunner`.

**Quality**

- [Tests](docs/en/quality/tests.md) · [pt-BR](docs/pt-BR/quality/tests.md): The PHPUnit report, the measured timing table and the mutation testing.

**Project**

- [Roadmap](docs/en/project/roadmap.md) · [pt-BR](docs/pt-BR/project/roadmap.md): The release shipped and what comes next; the milestone view is in ROADMAP.md.

---

## 🧪 Quality

- **Tests:** 99 PHPUnit tests (`vendor/bin/phpunit`) and 4370 assertions, 100% passing on PHP 8.4: the operators and the runner, properties against the core as the oracle, the proof of gain against a local HTTP server, and the code blocks of the documentation.
- **Properties:** 200 seeded inputs per property: `selectAsync()`/`whereAsync()`/`selectManyAsync()` answer as the core `select()`/`where()`/`selectMany()` for any concurrency, `take(k)` starts at most k + concurrency items, and the core operators downstream answer as over the synchronous version.
- **Mutation testing:** Infection over the whole `src/`, MSI 97.74% (4 escaped mutants, all equivalent); the Quality workflow requires 96%.
- **The report**, the timing table and the mutation run: [Tests](docs/en/quality/tests.md).

---

## 🗺️ Roadmap & Ecosystem

The release shipped and what comes next: [Roadmap](docs/en/project/roadmap.md). The milestone view: 👉 **[ROADMAP.md](ROADMAP.md)**. The core and its own roadmap: [Antevemus.AlinqCollection](https://github.com/antevemus-it/Antevemus.AlinqCollection).

---

## 📄 License & Authors

This software is open-source, licensed under the **[MIT License](LICENSE)**.

- **Author:** Heliton Junior (CTO) — [`contato@antevemus.com.br`](mailto:contato@antevemus.com.br)
- **Organization:** [Antevemus Soluções Inovadoras em TI Ltda.](https://antevemus.com.br)
- **Copyright:** Copyright (c) 2026 Antevemus Soluções Inovadoras em TI Ltda.
