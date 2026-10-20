# Como Funciona

## A janela ordenada, limitada e preguiçosa

Cada operador assíncrono percorre a fonte por uma janela de no máximo `concurrency` itens:

1. **Encher.** O operador puxa itens da fonte um a um e inicia a closure de cada um na sua própria Fiber (`RevoltRunner::start()`, sobre `Amp\async()`), até a janela ter `concurrency` itens ou a fonte acabar.
2. **Esperar a cabeça.** Ele espera o item mais antigo da janela. Os outros itens continuam rodando enquanto isso; um item que termina antes da cabeça espera na janela.
3. **Reencher, depois entregar.** Quando a cabeça termina, a janela é enchida de novo (mais um item é puxado e iniciado) e só então o resultado da cabeça é entregue ao próximo estágio. Volta ao passo 2.

Daí vêm três propriedades:

- **Ordenada.** Os resultados saem na ordem da fonte, qualquer que seja a ordem em que as closures terminam. Uma cabeça lenta segura a janela: itens atrás dela podem ter terminado, mas nenhum item novo começa até a cabeça ser entregue.
- **Limitada.** No máximo `concurrency` itens estão iniciados e ainda não entregues. A memória guarda no máximo esse número de resultados.
- **Preguiçosa.** Nada roda até o estágio ser percorrido, e a fonte só é puxada quando a janela tem espaço. Consumir k resultados inicia no máximo k + `concurrency` itens.

Esperar significa `Amp\Future::await()`: fora de uma Fiber ele roda o event loop do Revolt até a cabeça ficar pronta; dentro de uma Fiber (num servidor Amp, ou dentro de outra closure deste pacote) ele suspende essa Fiber e deixa o loop rodar o resto da aplicação.

## Parar cedo cancela o que está em voo

`take()`, `first()`, `any()`, um `break` saindo do `foreach`, ou uma exceção mais adiante encerram o generator do estágio. O `finally` dele cancela os itens em voo pelo `Amp\Cancellation` de cada um; um item que entrou na janela mas cuja Fiber ainda não tinha começado nunca roda a closure. É o modelo do `fromHttpPages()` do núcleo, que cancela a requisição da próxima página quando o consumidor para.

<!-- tested: window -->
```php
<?php

declare(strict_types=1);

use Amp\Cancellation;
use Amp\CancelledException;
use Antevemus\ALinqRevolt\ALinqAsyncCollection;

use function Amp\delay;

$read = 0;
$started = 0;
$cancelled = 0;
$source = (function () use (&$read) {
    foreach (range(1, 1000) as $n) {
        $read++;
        yield $n;
    }
})();

$squares = ALinqAsyncCollection::from($source)->selectAsync(
    function (int $n, int $key, Cancellation $cancellation) use (&$started, &$cancelled): int {
        $started++;
        try {
            delay($n / 100, cancellation: $cancellation); // o item n leva n × 10 ms
        } catch (CancelledException $e) {
            $cancelled++;
            throw $e;
        }
        return $n * $n;
    },
    concurrency: 3,
);

$squares->take(2)->toArray(); // [1, 4]
$read; // 5
$started; // 4
delay(0.001); // os cancelamentos chegam na próxima volta do event loop
$cancelled; // 2
```

Os itens 1, 2 e 3 começam juntos. Quando o item 1 fica pronto, o item 4 é puxado e iniciado e o 1 é entregue; quando o item 2 fica pronto, o item 5 é puxado e o 2 é entregue, e `take(2)` para. Os itens 3 e 4 estavam rodando e são cancelados; o item 5 foi cancelado antes de a Fiber dele começar, então a closure dele nunca rodou; os itens 6 a 1000 nunca foram lidos.

## Fibers só ganham tempo em I/O não bloqueante

Uma Fiber é uma corrotina cooperativa na única thread do PHP: ela só devolve a thread quando espera algo que o event loop vigia (um socket de um cliente Amp, um timer do `Amp\delay()`). Sobrepor essas esperas é todo o ganho. Uma chamada bloqueante (`file_get_contents()`, `curl_exec()`, `sleep()`, PDO) segura a thread até retornar, e trabalho de CPU nunca a devolve, então com qualquer `concurrency` elas rodam uma depois da outra:

<!-- tested: blocking -->
```php
<?php

declare(strict_types=1);

use Antevemus\ALinqRevolt\ALinqAsyncCollection;

use function Amp\delay;

$started = microtime(true);
ALinqAsyncCollection::from([1, 2, 3, 4])->eachAsync(fn() => delay(0.05), concurrency: 4);
$nonBlocking = microtime(true) - $started; // cerca de 0,05 s: as quatro esperas se sobrepuseram

$started = microtime(true);
ALinqAsyncCollection::from([1, 2, 3, 4])->eachAsync(fn() => usleep(50_000), concurrency: 4);
$blocking = microtime(true) - $started; // cerca de 0,2 s: usleep() segura a thread, uma chamada depois da outra

$nonBlocking < 0.15; // true
$blocking > 0.19; // true
```

O mesmo vale pela rede: contra um servidor HTTP local que responde em 200 ms, 16 requisições levam cerca de 3 200 ms pelo `select()` do núcleo com um cliente bloqueante, cerca de 3 200 ms pelo `selectAsync()` com o mesmo cliente bloqueante, e cerca de 200 ms pelo `selectAsync(concurrency: 16)` com o cliente HTTP do Amp. A tabela medida e o teste que a afirma: [Testes](../quality/tests.md).

Para clientes bloqueantes, o núcleo já tem a resposta sem dependência onde ela se aplica: `ALinqLazyCollection::fromHttpPages()` baixa a próxima página com `curl_multi` enquanto a atual é consumida. Para trabalho de CPU, Fibers são a ferramenta errada: ele precisa de processos de trabalho.

## Erros e limites de tempo

Uma exceção de uma closure é entregue na posição do seu item, como uma `ALinqAsyncException` que carrega a chave, a posição e a exceção original (`getPrevious()`): os itens anteriores já foram entregues, os itens em voo são cancelados. Um `timeout` limita cada item a partir do seu início (um `Amp\TimeoutCancellation`) e o faz falhar do mesmo jeito, com `isTimeout()`. Detalhes e exemplos: [Operadores assíncronos](../api/async-operators.md#erros).

## As duas classes e o runner

| Classe | Papel |
| :--- | :--- |
| `ALinqAsyncCollection` (final) | Envolve uma fonte (array, coleção ALinq ansiosa ou preguiçosa, `Traversable`) e oferece `selectAsync()`, `whereAsync()`, `selectManyAsync()`, `eachAsync()`; `lazy()` e `collection()` devolvem o núcleo. As classes do núcleo são finais: ela as envolve, nunca as estende. |
| `RevoltRunner` (final) | Roda closures sob o loop do Revolt com um limite de concorrência e um limite de tempo por item; livre de tipos do ALinq. Um por percurso por padrão; um runner compartilhado limita vários operadores juntos. |
| `ALinqAsyncException` (final) | A falha de um item na sua posição: `getKey()`, `getPosition()`, `getOperator()`, `isTimeout()`. |
