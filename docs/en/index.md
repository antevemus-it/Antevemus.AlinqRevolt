# Antevemus ALinq Revolt

The official documentation of the package, one page per topic. The same pages exist in Portuguese (Brazil), in `docs/pt-BR`, and every PHP block of these pages is run by the test suite with the answers written in its comments.

> **The asynchronous companion of [ALinq](https://github.com/antevemus-it/Antevemus.AlinqCollection) for PHP 8.4+**  
> `selectAsync()`, `whereAsync()`, `selectManyAsync()` and `eachAsync()` over the Revolt event loop: each item's closure runs in its own Fiber (Amp futures), through an ordered, bounded, lazy window of items in flight, and the result is a core ALinq lazy collection, so every core operator chains after it. For per-item I/O against non-blocking clients (the Amp HTTP client, async SDKs).

## Documentation Map

### Getting started

- [Installation and quick start](getting-started/index.md): Composer, the requirements and the Quick Start.

### Concepts

- [How it works](concepts/how-it-works.md): The ordered, bounded, lazy window, early stop and cancellation, and why Fibers gain time only on non-blocking I/O.

### API reference

- [Asynchronous operators](api/async-operators.md): The four operators, the closure, keys, errors, time limits and a shared `RevoltRunner`.

### Internals

- [Architecture](internals/architecture.md): Why Revolt and Amp and not ReactPHP, the core's hook that knows no event loop, and what a companion for another event loop implements.

### Quality

- [Tests](quality/tests.md): The PHPUnit report, the measured timing table and the mutation testing.

### Project

- [Roadmap](project/roadmap.md): The release shipped and what comes next; the milestone view is in ROADMAP.md.

## Where Is Each Thing

| What | Where |
| :--- | :--- |
| Overview, badges and the Quick Start | [README.md](https://github.com/antevemus-it/Antevemus.AlinqRevolt/blob/main/README.md) |
| What changed in each version | [CHANGELOG.md](https://github.com/antevemus-it/Antevemus.AlinqRevolt/blob/main/CHANGELOG.md) |
| Milestones shipped and planned | [ROADMAP.md](https://github.com/antevemus-it/Antevemus.AlinqRevolt/blob/main/ROADMAP.md) |
| Provenance and third-party licenses | [NOTICE.md](https://github.com/antevemus-it/Antevemus.AlinqRevolt/blob/main/NOTICE.md), [THIRD_PARTY_NOTICES.md](https://github.com/antevemus-it/Antevemus.AlinqRevolt/blob/main/THIRD_PARTY_NOTICES.md) |
| License | [LICENSE](https://github.com/antevemus-it/Antevemus.AlinqRevolt/blob/main/LICENSE) |
