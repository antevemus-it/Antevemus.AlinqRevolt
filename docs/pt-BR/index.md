# Antevemus ALinq Revolt

A documentação oficial do pacote, uma página por tópico. As mesmas páginas existem em inglês, em `docs/en`, e todo bloco PHP destas páginas é executado pela suíte de testes com as respostas escritas nos seus comentários.

> **O companheiro assíncrono do [ALinq](https://github.com/antevemus-it/Antevemus.AlinqCollection) para PHP 8.4+**  
> `selectAsync()`, `whereAsync()`, `selectManyAsync()` e `eachAsync()` sobre o event loop do Revolt: a closure de cada item roda na sua própria Fiber (futures do Amp), por uma janela ordenada, limitada e preguiçosa de itens em voo, e o resultado é uma coleção preguiçosa do núcleo ALinq, então todo operador do núcleo encadeia depois dele. Para I/O por item contra clientes não bloqueantes (o cliente HTTP do Amp, SDKs assíncronos).

## Mapa da Documentação

### Primeiros passos

- [Instalação e início rápido](getting-started/index.md): Composer, os requisitos e o Início Rápido.

### Conceitos

- [Como funciona](concepts/how-it-works.md): A janela ordenada, limitada e preguiçosa, parada antecipada e cancelamento, e por que Fibers só ganham tempo em I/O não bloqueante.

### Referência da API

- [Operadores assíncronos](api/async-operators.md): Os quatro operadores, a closure, chaves, erros, limites de tempo e um `RevoltRunner` compartilhado.

### Internos

- [Arquitetura](internals/architecture.md): Por que Revolt e Amp e não ReactPHP, o gancho do núcleo que não conhece event loop, e o que um companheiro para outro event loop implementa.

### Qualidade

- [Testes](quality/tests.md): O relatório do PHPUnit, a tabela de tempos medida e o teste de mutação.

### Projeto

- [Roadmap](project/roadmap.md): A versão entregue e o que vem depois; a visão por marcos está no ROADMAP.md.

## Onde Está Cada Coisa

| O quê | Onde |
| :--- | :--- |
| Visão geral, badges e o Início Rápido | [README.pt-BR.md](https://github.com/antevemus-it/Antevemus.AlinqRevolt/blob/main/README.pt-BR.md) |
| O que mudou em cada versão | [CHANGELOG.md](https://github.com/antevemus-it/Antevemus.AlinqRevolt/blob/main/CHANGELOG.md) |
| Marcos entregues e planejados | [ROADMAP.md](https://github.com/antevemus-it/Antevemus.AlinqRevolt/blob/main/ROADMAP.md) |
| Procedência e licenças de terceiros | [NOTICE.md](https://github.com/antevemus-it/Antevemus.AlinqRevolt/blob/main/NOTICE.md), [THIRD_PARTY_NOTICES.md](https://github.com/antevemus-it/Antevemus.AlinqRevolt/blob/main/THIRD_PARTY_NOTICES.md) |
| Licença | [LICENSE](https://github.com/antevemus-it/Antevemus.AlinqRevolt/blob/main/LICENSE) |
