Antevemus ALinq Revolt
Copyright (c) 2026 Antevemus Soluções Inovadoras em TI Ltda.
License: MIT (SPDX: MIT) — see LICENSE.

Website: https://antevemus.com.br
Email: contato@antevemus.com.br

-------------------------------------------------------------------------------
1. SCOPE & PURPOSE
-------------------------------------------------------------------------------

Antevemus ALinq Revolt is the asynchronous companion of Antevemus ALinq
Collection (antevemus/alinq-collection) for PHP 8.4+. It offers selectAsync(),
whereAsync(), selectManyAsync() and eachAsync() over the Revolt event loop:
each item's closure runs in its own Fiber through Amp futures, inside an
ordered, bounded, lazy window of items in flight, and the result is a core
ALinq lazy collection.

-------------------------------------------------------------------------------
2. PROVENANCE — NOTHING PORTED
-------------------------------------------------------------------------------

No source code of any other project was copied, ported or translated into this
package. It is an independent implementation, written for this package by
Antevemus Soluções Inovadoras em TI Ltda., on the public APIs of its runtime
dependencies (section 4).

-------------------------------------------------------------------------------
3. CONCEPTUAL INSPIRATIONS
-------------------------------------------------------------------------------

The model of the window (results handed over in source order, a bounded
prefetch, the work in flight cancelled by the generator's `finally` when the
consumer stops early, and an error delivered at the position of the item that
failed) follows ALinqLazyCollection::fromHttpPages() of the core package, by
the same author.

    Conceptual Origin: ALinqLazyCollection::fromHttpPages() (antevemus/alinq-collection 1.5.0)
    Originator: Antevemus Soluções Inovadoras em TI Ltda.
    License: MIT License
    Website: https://github.com/antevemus-it/Antevemus.AlinqCollection

The operator names (Select, Where, SelectMany) come from the Language
Integrated Query (LINQ) of Microsoft .NET, through the core package; the
asynchronous variants recall the idea of IAsyncEnumerable<T> with bounded
parallel projection (for example Parallel.ForEachAsync and its
MaxDegreeOfParallelism). Only the ideas are taken; no .NET source code is
contained, reproduced or redistributed.

    Conceptual Origin: .NET LINQ and System.Threading.Tasks.Parallel.ForEachAsync
    Originator: Microsoft Corporation
    Website: https://learn.microsoft.com/en-us/dotnet/

-------------------------------------------------------------------------------
4. THIRD-PARTY COMPONENTS & LICENSES
-------------------------------------------------------------------------------

See the THIRD_PARTY_NOTICES.md file for the runtime dependencies (revolt/
event-loop and amphp/amp, MIT) and the development tools.
