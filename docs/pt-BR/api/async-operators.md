# Operadores Assíncronos

## Assinaturas

| Método | Devolve | Equivalente no núcleo |
| :--- | :--- | :--- |
| `ALinqAsyncCollection::from(iterable\|IALinqCollection\|IALinqLazyCollection $source, ?RevoltRunner $runner = null)` | `ALinqAsyncCollection` | `ALinqLazyCollection::from()` |
| `selectAsync(callable $selector, int $concurrency = 8, ?float $timeout = null)` | `IALinqLazyCollection` | `select()` |
| `whereAsync(callable $predicate, int $concurrency = 8, ?float $timeout = null)` | `IALinqLazyCollection` | `where()` |
| `selectManyAsync(callable $selector, int $concurrency = 8, ?float $timeout = null)` | `IALinqLazyCollection` | `selectMany()` |
| `eachAsync(callable $action, int $concurrency = 8, ?float $timeout = null)` | `void` (terminal) | `each()` |
| `lazy()` / `collection()` | a fonte como `IALinqLazyCollection` / `IALinqCollection` | |

As três projeções devolvem uma `ALinqLazyCollection` do núcleo construída sobre um generator, então todo operador do núcleo encadeia depois delas, e cada percurso roda a janela de novo (como cada percurso de um estágio preguiçoso do núcleo lê a fonte de novo; `remember()` guarda os resultados). Uma fonte `Generator` é lida uma vez, como no núcleo.

- `$concurrency`: o máximo de itens iniciados e ainda não entregues, no mínimo 1 (senão `InvalidArgumentException`, na chamada do operador).
- `$timeout`: segundos que cada item pode levar, contados do seu início; `null` para sem limite; senão um número positivo e finito (`InvalidArgumentException`).

## A closure

A closure recebe `($item, $key)` pela regra de aridade do núcleo: um callable que aceita dois parâmetros recebe a chave, um callable de um parâmetro (ou uma função interna como `strval`) recebe só o item. Uma closure do usuário que declara um **terceiro** parâmetro recebe também o `Amp\Cancellation` do item, acionado quando o limite de tempo acaba ou o consumidor para cedo: passe-o ao cliente Amp para que a própria requisição seja abortada.

A closure roda na sua própria Fiber sob o event loop. Ela pode devolver um valor, devolver um `Amp\Future` (esperado por ela), ou suspender por dentro (um cliente Amp, `Amp\delay()`, outro `selectAsync()`).

<!-- tested: operators -->
```php
<?php

declare(strict_types=1);

use Antevemus\ALinqRevolt\ALinqAsyncCollection;

use function Amp\delay;

$orders = ['A-1' => 120, 'A-2' => 40, 'A-3' => 75];

// selectAsync(): um resultado por item, na ordem da fonte, chaves mantidas
$quotes = ALinqAsyncCollection::from($orders)
    ->selectAsync(function (int $amount, string $code): int {
        delay(0.01); // por exemplo, um serviço de frete
        return $amount + 15;
    })
    ->toArray();
// Resultado: ['A-1' => 135, 'A-2' => 55, 'A-3' => 90]

// whereAsync(): mantém os itens cujo predicado responde um valor verdadeiro
$approved = ALinqAsyncCollection::from($orders)
    ->whereAsync(function (int $amount): bool {
        delay(0.01); // por exemplo, uma checagem de fraude
        return $amount >= 50;
    })
    ->toArray();
// Resultado: ['A-1' => 120, 'A-3' => 75]

// selectManyAsync(): cada item dá um iterável, achatado em ordem numa lista
$lines = ALinqAsyncCollection::from(['A-1', 'A-3'])
    ->selectManyAsync(function (string $code): array {
        delay(0.01); // por exemplo, as linhas do pedido
        return ["$code/1", "$code/2"];
    })
    ->toArray();
// Resultado: ['A-1/1', 'A-1/2', 'A-3/1', 'A-3/2']

// eachAsync(): terminal; roda todas as ações e retorna quando todas terminaram
$notified = [];
ALinqAsyncCollection::from($orders)->eachAsync(function (int $amount, string $code) use (&$notified): void {
    delay(0.01); // por exemplo, uma notificação
    $notified[] = $code;
}, concurrency: 2);
count($notified); // 3
```

`eachAsync()` roda a mesma janela ordenada das projeções e lança a primeira falha na ordem da fonte.

## Chaves

As chaves seguem `select()`, `where()` e `selectMany()` do núcleo:

- `selectAsync()` e `whereAsync()` renumeram uma fonte que se sabe ser uma lista, como `select()` e `where()` do núcleo fazem sobre uma lista, e mantêm as chaves nos outros casos.
- `selectManyAsync()` produz uma lista.

Cada estágio declara o formato da sua fonte ao núcleo com `ALinqLazyCollection::from($factory, $isList)` (o parâmetro acrescentado no ALinq 2.3.0, o motivo de este pacote exigir `^2.3`), então `toArray()` e os operadores do núcleo depois dele respondem como sobre o próprio estágio do núcleo: uma lista continua lista, e um dicionário mantém as chaves, inclusive um de chaves inteiras (`[$id => $row]`). O formato vem da fonte como o núcleo o conhece: `array_is_list()` para um array, `isList()` para uma coleção ALinq ansiosa ou preguiçosa (o acessor preguiçoso também é novo na 2.3.0). Sobre um `Generator` ou outro `Traversable` o núcleo não conhece formato, e o estágio também não: como nos próprios estágios do núcleo sobre eles, `toArray()` é uma lista quando toda chave é inteira.

<!-- tested: keys -->
```php
<?php

declare(strict_types=1);

use Antevemus\ALinq\ALinqLazyCollection;
use Antevemus\ALinqRevolt\ALinqAsyncCollection;

$double = fn(int $n): int => $n * 2;

ALinqAsyncCollection::from([1, 2, 3])->whereAsync(fn(int $n) => $n !== 2)->toArray(); // [1, 3]
ALinqAsyncCollection::from(['x' => 1, 'y' => 2])->selectAsync($double)->toArray(); // ['x' => 2, 'y' => 4]

// um dicionário de chaves inteiras mantém as chaves, como no select() do núcleo sobre o mesmo array
ALinqAsyncCollection::from([7 => 1, 9 => 2])->selectAsync($double)->toArray(); // [7 => 2, 9 => 4]
ALinqLazyCollection::from([7 => 1, 9 => 2])->select($double)->toArray(); // [7 => 2, 9 => 4]
ALinqAsyncCollection::from([7 => 1, 9 => 2, 12 => 3])->whereAsync(fn(int $n) => $n !== 2)->toArray(); // [7 => 1, 12 => 3]

// um generator não tem formato conhecido, no núcleo como aqui: uma lista quando toda chave é inteira
$stream = (function () {
    yield 7 => 1;
    yield 9 => 2;
})();
ALinqAsyncCollection::from($stream)->selectAsync($double)->toArray(); // [2, 4]
```

## Erros

Uma exceção da closure é entregue **na posição do seu item**, embrulhada em `ALinqAsyncException` (final, estende `RuntimeException`): os itens anteriores já foram entregues, os itens em voo são cancelados e nada depois dela começa. Não há política de "ignorar erros" na 1.0.0: capture dentro da closure para transformar uma falha num valor.

| Método | Resposta |
| :--- | :--- |
| `getKey()` | A chave do item na fonte. |
| `getPosition()` | A posição do item na fonte, a partir de 0. |
| `getOperator()` | `selectAsync`, `whereAsync`, `selectManyAsync` ou `eachAsync`. |
| `isTimeout()` | Se o item estourou o tempo. |
| `getPrevious()` | A exceção original; num timeout, a `Amp\CancelledException` cuja anterior é a `Amp\TimeoutException`. |

A mensagem nomeia o operador, a posição, a chave e a classe da exceção original; nunca o valor do item nem a mensagem da original, que pode carregá-lo. `selectManyAsync()` recusa do mesmo jeito um resultado que não é iterável, com a `UnexpectedValueException` do `selectMany()` do núcleo como anterior. Uma exceção da própria fonte (um generator que lança, um cursor que falha) não é embrulhada: ela vem como é, depois dos itens lidos antes dela.

<!-- tested: errors -->
```php
<?php

declare(strict_types=1);

use Antevemus\ALinqRevolt\ALinqAsyncCollection;
use Antevemus\ALinqRevolt\ALinqAsyncException;

$ratios = ALinqAsyncCollection::from(['a' => 5, 'b' => 0, 'c' => 2])->selectAsync(fn(int $n): int => intdiv(10, $n));

$ratios->toArray(); // throws ALinqAsyncException: selectAsync(): the item at position 1 (key 'b') failed with DivisionByZeroError

$delivered = [];
try {
    foreach ($ratios as $key => $ratio) {
        $delivered[$key] = $ratio;
    }
} catch (ALinqAsyncException $error) {
    // os itens anteriores foram entregues
}
$delivered; // ['a' => 2]
$error->getKey(); // 'b'
$error->getPosition(); // 1
$error->isTimeout(); // false
$error->getPrevious()::class; // 'DivisionByZeroError'
```

## Limites de tempo

`timeout` é um número de segundos por item, contado do momento em que a closure dele começa, imposto com um `Amp\TimeoutCancellation`. Quando acaba, o item falha na sua posição com `isTimeout()` verdadeiro, mesmo que termine depois, e o `Cancellation` dele é acionado. Uma closure que passa o cancelamento ao seu cliente para na hora; uma que o ignora continua rodando em segundo plano até retornar (o resultado é descartado), ainda contando no limite do runner.

<!-- tested: timeout -->
```php
<?php

declare(strict_types=1);

use Amp\Cancellation;
use Antevemus\ALinqRevolt\ALinqAsyncCollection;

use function Amp\delay;

$calls = ALinqAsyncCollection::from([0.01, 5.0, 0.01])->selectAsync(
    function (float $seconds, int $key, Cancellation $cancellation): string {
        delay($seconds, cancellation: $cancellation); // passe o cancelamento ao cliente Amp
        return "took $seconds s";
    },
    timeout: 0.2,
);

$calls->toArray(); // throws ALinqAsyncException: selectAsync(): the item at position 1 (key 1) exceeded its time limit of 0.2 s
$calls->first(); // 'took 0.01 s'
```

`first()` para depois do primeiro item, então a chamada de 5 segundos é cancelada muito antes do seu limite de tempo.

## Um runner para vários operadores

Por padrão cada percurso de um operador ganha o seu próprio `RevoltRunner`, limitado pelo `concurrency` do operador. Um `RevoltRunner` passado a `from()` é compartilhado por todos os percursos de todos os operadores daquela coleção: o `concurrency` dele limita todos juntos, que é como um pipeline de vários estágios respeita um único limite de taxa de uma API. Cada operador continua com a sua janela de `concurrency` itens.

<!-- tested: runner -->
```php
<?php

declare(strict_types=1);

use Antevemus\ALinqRevolt\ALinqAsyncCollection;
use Antevemus\ALinqRevolt\RevoltRunner;

use function Amp\delay;

$api = new RevoltRunner(concurrency: 3); // no máximo 3 chamadas a esta API por vez, somando os estágios
$running = 0;
$peak = 0;
$call = function (int $n) use (&$running, &$peak): int {
    $peak = max($peak, ++$running);
    delay(0.01);
    $running--;
    return $n;
};

$users = ALinqAsyncCollection::from(range(1, 10), $api)->selectAsync($call, concurrency: 8);
$scores = ALinqAsyncCollection::from($users, $api)->selectAsync($call, concurrency: 8)->toArray();

$scores; // [1, 2, 3, 4, 5, 6, 7, 8, 9, 10]
$peak; // 3
```

`RevoltRunner` pode ser usado sozinho: `start(Closure $task, ?float $timeout = null, ?Cancellation $cancellation = null): Future` roda `$task(Cancellation)` assim que houver uma vaga (suspendendo a Fiber que chama, ou rodando o loop fora de uma), `cancel()` cancela todas as tarefas que ele iniciou, `running()` conta as tarefas rodando. Uma closure que percorre uma coleção assíncrona com o mesmo runner compartilhado dentro da sua própria tarefa pode esperar por uma vaga que o seu próprio chamador ocupa: dê ao trabalho aninhado um runner próprio.

## De volta ao núcleo

`lazy()` devolve a fonte como uma coleção preguiçosa do núcleo (a própria coleção preguiçosa, o `lazy()` da coleção ansiosa, ou `ALinqLazyCollection::from()` do array ou do `Traversable`), e `collection()` a devolve como uma `ALinqCollection` ansiosa (lendo uma fonte preguiçosa). Os dois são síncronos: o trabalho assíncrono acontece só nos quatro operadores.
