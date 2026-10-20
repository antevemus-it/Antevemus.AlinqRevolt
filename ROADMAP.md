# Product Roadmap & Future Milestones — Antevemus ALinq Revolt

This document outlines the engineering roadmap of **Antevemus ALinq Revolt**, the asynchronous companion of [Antevemus ALinq Collection](https://github.com/antevemus-it/Antevemus.AlinqCollection): what shipped and what is only planned. The list in [Roadmap](docs/en/project/roadmap.md) is the short form of this page.

---

## ✅ Completed Milestones

### 1. Asynchronous Operators over the Revolt Event Loop ⚡ (Shipped in v1.0.0)
- **Description:** `ALinqAsyncCollection::from()` over an array, an eager or lazy ALinq collection or any `Traversable`, with `selectAsync()`, `whereAsync()`, `selectManyAsync()` and `eachAsync()`. Each item's closure runs in its own Fiber (`Amp\async()`), through an ordered, bounded, lazy window: results in source order, at most `concurrency` items started and not delivered, the source pulled on demand, early stop cancelling what is in flight. Errors and per-item time limits (`Amp\TimeoutCancellation`) are delivered at the item's position as `ALinqAsyncException`. The result is a core `ALinqLazyCollection` that declares the shape of its source (`ALinqLazyCollection::from($factory, $isList)`, with the shape read from an array, an eager collection or a lazy collection's `isList()`: the reason it requires ALinq `^2.3`), so the whole core chains after it and an integer-keyed dictionary keeps its keys. `RevoltRunner`, the concurrency limit with a per-item time limit, free of ALinq types and shareable between operators.
- **Goal:** Milestone 12 of the ALinq roadmap, its companion part: per-item I/O (a `map()` that calls an API) against non-blocking clients (the Amp HTTP client, async SDKs), with bounded concurrency. Measured: 16 requests of 200 ms take 3 222 ms through the core `select()` with a blocking client and 214 ms through `selectAsync(concurrency: 16)` with the Amp HTTP client; the same blocking client under `selectAsync()` still takes 3 225 ms, because a Fiber only gains time on non-blocking I/O.

---

## 🔭 Planned Milestones

### 2. A Policy for Failures 🧯 (Planned)
- **Description:** Collect the failures, or skip the failed items, instead of stopping at the first one. 1.0.0 stops at the first failure in source order; a closure can catch and return a value of its own.
- **Target:** when a consumer needs it.

### 3. Unordered Results 🔀 (Planned)
- **Description:** `selectAsyncUnordered()` and an unordered `eachAsync()`: results as they complete, so a slow item does not hold the window, when the order of the source does not matter.
- **Target:** when a consumer needs it.
