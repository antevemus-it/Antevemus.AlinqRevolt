# Getting Started

## 📦 Installation

Install via Composer:

```bash
composer require antevemus/alinq-revolt
```

Requirements: PHP 8.4 or later. Installed with it: [`antevemus/alinq-collection`](https://github.com/antevemus-it/Antevemus.AlinqCollection) `^2.3` (the core, from the same author; 2.3.0 is the first release whose `ALinqLazyCollection::from()` takes the declared shape of a generator, which every stage of this package uses), [`revolt/event-loop`](https://github.com/revoltphp/event-loop) `^1.0` (the event loop) and [`amphp/amp`](https://github.com/amphp/amp) `^3.0` (futures, cancellations, `delay()`). No PHP extension is required. The package uses the application's Revolt event loop when there is one and never creates or replaces a driver.

What it is for: per-item I/O against **non-blocking** clients, such as the Amp HTTP client or an SDK built on Amp or Revolt. A Fiber only gains time while the code inside it waits on non-blocking I/O; blocking calls (`file_get_contents()`, `curl_exec()`, PDO) and CPU work run one after the other, whatever the concurrency. See [How it works](../concepts/how-it-works.md).

### Git VCS Repository (Development or Private Environments)

If using directly from GitHub prior to or alongside Packagist:

```json
{
    "repositories": [
        {
            "type": "vcs",
            "url": "https://github.com/antevemus-it/Antevemus.AlinqRevolt.git"
        }
    ],
    "require": {
        "antevemus/alinq-revolt": "^1.0"
    }
}
```

## 🚀 Quick Start

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

`selectAsync()` returns a core `ALinqLazyCollection`, so `where()`, `select()`, `orderBy()`, `groupBy()` and every other operator of the core follow it. The results come out in source order, at most `concurrency` items are in flight, and nothing runs until the pipeline is traversed. The operators, their keys, errors and time limits: [Asynchronous operators](../api/async-operators.md).
