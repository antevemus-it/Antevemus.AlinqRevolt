# 🗺️ Roadmap

- [x] **v1.0.0**: `ALinqAsyncCollection` com `selectAsync()`, `whereAsync()`, `selectManyAsync()` e `eachAsync()` sobre o event loop do Revolt (Fibers, futures do Amp): uma janela ordenada, limitada e preguiçosa de itens em voo, parada antecipada que cancela o que está em voo, erros e limites de tempo na posição do item (`ALinqAsyncException`), e uma `ALinqLazyCollection` do núcleo como resultado, com o formato da fonte declarado, para todo tipo de fonte cujo formato o núcleo conhece (exige o ALinq 2.3.0 para isso). `RevoltRunner`, o limite de concorrência com limite de tempo por item, compartilhável entre operadores. O pacote companheiro do marco 12 do [roadmap do ALinq](https://github.com/antevemus-it/Antevemus.AlinqCollection/blob/main/ROADMAP.md).
- [ ] **Uma política para falhas** (coletá-las, ou pular os itens que falharam) em vez de parar na primeira; a 1.0.0 para, e uma closure pode capturar e devolver um valor seu.
- [ ] **Uma variante sem ordem** (`selectAsyncUnordered()`): resultados conforme terminam, para que um item lento não segure a janela, quando a ordem da fonte não importa.

A visão por marcos desta lista vive no [ROADMAP.md](https://github.com/antevemus-it/Antevemus.AlinqRevolt/blob/main/ROADMAP.md).
