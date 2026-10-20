# Primeiros Passos

## 📦 Instalação

Instale via Composer:

```bash
composer require antevemus/alinq-revolt
```

Requisitos: PHP 8.4 ou superior. Instalados junto: [`antevemus/alinq-collection`](https://github.com/antevemus-it/Antevemus.AlinqCollection) `^2.3` (o núcleo, do mesmo autor; a 2.3.0 é a primeira versão cujo `ALinqLazyCollection::from()` aceita o formato declarado de um generator, que todo estágio deste pacote usa), [`revolt/event-loop`](https://github.com/revoltphp/event-loop) `^1.0` (o event loop) e [`amphp/amp`](https://github.com/amphp/amp) `^3.0` (futures, cancelamentos, `delay()`). Nenhuma extensão do PHP é exigida. O pacote usa o event loop Revolt da aplicação quando existe um e nunca cria nem troca um driver.

Para que serve: I/O por item contra clientes **não bloqueantes**, como o cliente HTTP do Amp ou um SDK construído sobre Amp ou Revolt. Uma Fiber só ganha tempo enquanto o código dentro dela espera I/O não bloqueante; chamadas bloqueantes (`file_get_contents()`, `curl_exec()`, PDO) e trabalho de CPU rodam uma depois da outra, qualquer que seja a concorrência. Veja [Como funciona](../concepts/how-it-works.md).

### Repositório Git VCS (Ambientes de Desenvolvimento ou Privados)

Para usar direto do GitHub antes do Packagist ou ao lado dele:

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

## 🚀 Início Rápido

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

`selectAsync()` devolve uma `ALinqLazyCollection` do núcleo, então `where()`, `select()`, `orderBy()`, `groupBy()` e todos os outros operadores do núcleo vêm depois dele. Os resultados saem na ordem da fonte, no máximo `concurrency` itens ficam em voo e nada roda até o pipeline ser percorrido. Os operadores, suas chaves, erros e limites de tempo: [Operadores assíncronos](../api/async-operators.md).
