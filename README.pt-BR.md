# Antevemus ALinq Revolt

[![CI](https://github.com/antevemus-it/Antevemus.AlinqRevolt/actions/workflows/tests.yml/badge.svg)](https://github.com/antevemus-it/Antevemus.AlinqRevolt/actions/workflows/tests.yml)
[![Latest Stable Version](https://img.shields.io/badge/release-v1.0.1-blue.svg)](https://github.com/antevemus-it/Antevemus.AlinqRevolt/releases)
[![PHP Version](https://img.shields.io/badge/php-%3E%3D8.4-8892BF.svg)](https://www.php.net/)
[![License: MIT](https://img.shields.io/badge/License-MIT-yellow.svg)](https://opensource.org/licenses/MIT)
[![Tests Passing](https://img.shields.io/badge/tests-99%20passed%20%7C%204436%20assertions-success.svg)](docs/pt-BR/quality/tests.md)
[![Core: ALinq](https://img.shields.io/badge/Core-Antevemus.AlinqCollection-orange)](https://github.com/antevemus-it/Antevemus.AlinqCollection)

> **O companheiro assíncrono do [ALinq](https://github.com/antevemus-it/Antevemus.AlinqCollection) para PHP 8.4+**  
> `selectAsync()`, `whereAsync()`, `selectManyAsync()` e `eachAsync()` sobre o event loop do Revolt: a closure de cada item roda na sua própria Fiber (futures do Amp), por uma janela ordenada, limitada e preguiçosa de itens em voo, e o resultado é uma coleção preguiçosa do núcleo ALinq, então todo operador do núcleo encadeia depois dele. Para I/O por item contra clientes não bloqueantes (o cliente HTTP do Amp, SDKs assíncronos).

---

[🇺🇸 English](README.md) • [🇧🇷 Português (Brasil)](README.pt-BR.md)

---

A documentação vive em [`docs/pt-BR`](docs/pt-BR/index.md) (português do Brasil) e [`docs/en`](docs/en/index.md) (inglês), uma página por tópico, no formato Docs Kit (um `docs-nav.json` por idioma), e todo bloco PHP das páginas é executado pela suíte de testes.

---

## 🚀 Início Rápido

O mesmo bloco abre [Primeiros Passos](docs/pt-BR/getting-started/index.md), e a suíte de testes o executa com as respostas escritas nos seus comentários:

<!-- tested: quick-start -->
```php
<?php

declare(strict_types=1);

use Antevemus\ALinqRevolt\ALinqAsyncCollection;

use function Amp\delay;

$started = microtime(true);

// Oito chamadas a um serviço lento; Amp\delay() faz o papel de um cliente HTTP do Amp ou de um SDK assíncrono
$activeIds = ALinqAsyncCollection::from([101, 102, 103, 104, 105, 106, 107, 108])
    ->selectAsync(function (int $id): array {
        delay(0.1); // 100 ms de I/O não bloqueante
        return ['id' => $id, 'active' => $id % 2 === 1];
    }, concurrency: 8)
    ->where(fn(array $profile) => $profile['active']) // os operadores do núcleo encadeiam depois dele
    ->select(fn(array $profile) => $profile['id'])
    ->toArray();

// Resultado: [101, 103, 105, 107]

$elapsed = microtime(true) - $started;
$elapsed < 0.4; // true (as oito esperas se sobrepuseram: cerca de 0,1 s, não 0,8 s)
```

---

## 📦 Instalação

Instale via Composer: `composer require antevemus/alinq-revolt`. Requisitos: PHP 8.4 ou superior; instalados junto: [`antevemus/alinq-collection`](https://github.com/antevemus-it/Antevemus.AlinqCollection) `^2.3` (o núcleo, mesmo autor; 2.3.0 pelo formato declarado de um estágio sobre generator), [`revolt/event-loop`](https://github.com/revoltphp/event-loop) `^1.0` e [`amphp/amp`](https://github.com/amphp/amp) `^3.0`. Nenhuma extensão do PHP é exigida.

Uma Fiber só ganha tempo enquanto o código dentro dela espera I/O **não bloqueante**: chamadas bloqueantes (`file_get_contents()`, `curl_exec()`, PDO) e trabalho de CPU rodam uma depois da outra, qualquer que seja a concorrência. Medido contra um servidor HTTP local, 16 requisições de 200 ms: 3 222 ms pelo `select()` do núcleo com um cliente bloqueante, 214 ms pelo `selectAsync(concurrency: 16)` com o cliente HTTP do Amp, 3 225 ms pelo `selectAsync()` com o cliente bloqueante ([Testes](docs/pt-BR/quality/tests.md)).

---

## 📚 Documentação

**Primeiros passos**

- [Instalação e início rápido](docs/pt-BR/getting-started/index.md) · [en](docs/en/getting-started/index.md): Composer, os requisitos e o Início Rápido.

**Conceitos**

- [Como funciona](docs/pt-BR/concepts/how-it-works.md) · [en](docs/en/concepts/how-it-works.md): A janela ordenada, limitada e preguiçosa, parada antecipada e cancelamento, e por que Fibers só ganham tempo em I/O não bloqueante.

**Referência da API**

- [Operadores assíncronos](docs/pt-BR/api/async-operators.md) · [en](docs/en/api/async-operators.md): Os quatro operadores, a closure, chaves, erros, limites de tempo e um `RevoltRunner` compartilhado.

**Internos**

- [Arquitetura](docs/pt-BR/internals/architecture.md) · [en](docs/en/internals/architecture.md): Por que Revolt e Amp e não ReactPHP, o gancho do núcleo que não conhece event loop, e o que um companheiro para outro event loop implementa.

**Qualidade**

- [Testes](docs/pt-BR/quality/tests.md) · [en](docs/en/quality/tests.md): O relatório do PHPUnit, a tabela de tempos medida e o teste de mutação.

**Projeto**

- [Roadmap](docs/pt-BR/project/roadmap.md) · [en](docs/en/project/roadmap.md): A versão entregue e o que vem depois; a visão por marcos está no ROADMAP.md.

---

## 🧪 Qualidade

- **Testes:** 99 testes PHPUnit (`vendor/bin/phpunit`) e 4436 asserções, 100% passando no PHP 8.4: os operadores e o runner, propriedades com o núcleo como oráculo, a prova de ganho contra um servidor HTTP local, e os blocos de código da documentação.
- **Propriedades:** 200 entradas semeadas por propriedade: `selectAsync()`/`whereAsync()`/`selectManyAsync()` respondem como `select()`/`where()`/`selectMany()` do núcleo para qualquer concorrência, `take(k)` inicia no máximo k + concorrência itens, e os operadores do núcleo mais adiante respondem como sobre a versão síncrona.
- **Teste de mutação:** Infection sobre todo o `src/`, MSI 97,74% (4 mutantes escapados, todos equivalentes); o workflow de Qualidade exige 96%.
- **O relatório**, a tabela de tempos e a rodada de mutação: [Testes](docs/pt-BR/quality/tests.md).

---

## 🗺️ Roadmap e Ecossistema

A versão entregue e o que vem depois: [Roadmap](docs/pt-BR/project/roadmap.md). A visão por marcos: 👉 **[ROADMAP.md](ROADMAP.md)**. O núcleo e o roadmap dele: [Antevemus.AlinqCollection](https://github.com/antevemus-it/Antevemus.AlinqCollection).

---

## 📄 Licença e Autores

Este software é open-source, sob a **[Licença MIT](LICENSE)**.

- **Autor:** Heliton Junior (CTO) — [`contato@antevemus.com.br`](mailto:contato@antevemus.com.br)
- **Organização:** [Antevemus Soluções Inovadoras em TI Ltda.](https://antevemus.com.br)
- **Copyright:** Copyright (c) 2026 Antevemus Soluções Inovadoras em TI Ltda.
