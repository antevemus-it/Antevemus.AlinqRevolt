# Arquitetura

Por que o pacote roda sobre o Revolt e o Amp, como ele se apoia no núcleo sem que o núcleo conheça event loop algum, e o que um companheiro para outro event loop implementa. Esta página é só prosa: o comportamento que ela descreve aparece com código testado em [Como funciona](../concepts/how-it-works.md) e [Operadores assíncronos](../api/async-operators.md).

## Por que Revolt e Amp, e não ReactPHP

Quatro razões, em ordem de peso:

1. **Fibers nativas, sem promises.** Um operador suspende a Fiber enquanto a janela espera a cabeça e volta com o valor, então o resultado dele é uma coleção preguiçosa comum do núcleo, percorrida por `foreach`, `toArray()` ou qualquer operador do núcleo. O Amp 3 foi reescrito sobre Fibers: `async()` inicia uma, `Future::await()` suspende até o valor chegar, um `Cancellation` encerra a espera. O modelo principal do ReactPHP são promises e callbacks; o `react/async` 4 acrescenta `await()` sobre Fibers, mas cancelamento e limites de tempo continuam na API de promises, e a janela precisaria de uma camada de adaptação entre eles e o seu próprio cancelamento.
2. **O Revolt é o denominador comum.** O Amp 3 roda sobre o event loop do Revolt, e o ReactPHP roda sobre o Revolt pelo adaptador oficial `revolt/event-loop-adapter-react`. O inverso não existe: nada roda o Revolt sobre o loop do ReactPHP. Uma aplicação ReactPHP roda este pacote no mesmo loop do seu próprio código; um pacote construído sobre o loop do ReactPHP teria deixado as aplicações Amp de fora.
3. **Cancelamento casado com a janela.** O `Amp\Cancellation` que chega à closure do usuário como terceiro parâmetro é o mesmo que a janela cancela quando o consumidor para cedo ou o limite de tempo do item se esgota, então uma closure que o repassa a um cliente do Amp aborta a própria requisição. É o modelo do `fromHttpPages()` do núcleo, que cancela a requisição `curl_multi` em voo quando o consumidor para.
4. **Peso.** Dois pacotes pequenos em tempo de execução: `revolt/event-loop` e `amphp/amp`. Sobre o ReactPHP seriam `react/event-loop`, `react/promise` e `react/async`, mais o adaptador para alcançar as aplicações que rodam sobre o Revolt.

## O gancho do núcleo não conhece event loop

Este pacote é um invólucro. `ALinqAsyncCollection` guarda uma fonte, e cada um dos seus operadores devolve uma `ALinqLazyCollection` do núcleo construída com `ALinqLazyCollection::from(Closure $source, ?bool $isList)`, o parâmetro acrescentado no ALinq 2.3.0: a Closure é uma factory geradora que roda a janela a cada percurso, e `$isList` declara o formato do que ela produz. O formato que ele declara é o formato da fonte: `array_is_list()` de um array, `isList()` de uma coleção ansiosa, e `isList()` de uma coleção preguiçosa, também acrescentado na 2.3.0, que responde `true`, `false` ou `null` quando desconhecido, para que um invólucro possa repassá-lo.

O gancho é só isso: uma factory geradora e um formato declarado. Nenhuma classe do núcleo usa Revolt, Amp ou Fibers, e o núcleo não os exige; o event loop, as Fibers e o cancelamento vivem neste pacote. O lado do núcleo está em [Pipelines de Streaming & Big Data](https://github.com/antevemus-it/Antevemus.AlinqCollection/blob/main/docs/pt-BR/api/lazy-streaming.md) da documentação do núcleo; a janela que este pacote roda por trás da factory, em [Como funciona](../concepts/how-it-works.md).

## O que um companheiro para outro event loop implementa

Um pacote que leve estes operadores a outro event loop não precisa de nada do núcleo além do gancho acima, e de nada deste pacote. O que ele implementa, como este pacote faz:

- **Um invólucro sobre a fonte**: qualquer iterável, uma `IALinqCollection` ou uma `IALinqLazyCollection`. As classes do núcleo são finais, então ele as envolve e nunca as estende. Ele lê o formato da fonte ao envolvê-la: `array_is_list()` de um array, `isList()` de uma coleção ansiosa ou preguiçosa; qualquer outro `Traversable`, um `Generator` incluído, tem formato desconhecido, no núcleo como aqui.
- **Operadores que devolvem `ALinqLazyCollection::from(Closure, $isList)`**, declarando o formato do resultado: `true`, uma lista (renumerada pelos operadores do núcleo, `toArray()` uma lista); `false`, um dicionário (as chaves mantidas pelo `toArray()`, um de chaves inteiras incluído); `null`, desconhecido (as chaves como produzidas, `toArray()` uma lista quando toda chave é inteira). Aqui, `selectAsync()` e `whereAsync()` declaram o formato da fonte e renumeram uma lista, como `select()` e `where()` do núcleo fazem; `selectManyAsync()` declara uma lista, como o `selectMany()` do núcleo; `eachAsync()` é terminal. O núcleo só aceita a declaração com uma Closure: com um array ou outro iterável ela é recusada, já que o formato deles vem da própria fonte.
- **A janela ordenada, limitada e preguiçosa**, o comportamento que os consumidores destes operadores esperam: resultados na ordem da fonte qualquer que seja a ordem em que o trabalho termina, no máximo `concurrency` itens iniciados e ainda não entregues, a fonte puxada só quando a janela tem espaço, e a janela rodada de novo a cada percurso.
- **Cancelamento na parada antecipada**: quando o consumidor para (`take()`, `first()`, `any()`, um `break`, uma exceção mais adiante), o `finally` do generator cancela os itens em voo, e um item que não começou nunca roda.
- **O erro na posição do item**: uma falha é entregue depois de os itens anteriores terem sido entregues, com os itens em voo cancelados; aqui, uma `ALinqAsyncException` que carrega a chave, a posição e a exceção original.

Nem o núcleo nem este pacote mudam por um companheiro desses: ele traz o seu próprio runner, sobre o seu próprio loop.

## ReactPHP hoje

Uma aplicação ReactPHP roda este pacote pelo adaptador: com o `revolt/event-loop-adapter-react` instalado, o loop do ReactPHP roda sobre o Revolt, o loop que os operadores usam, então a aplicação e a janela compartilham um único event loop.

Um companheiro dedicado ao ReactPHP, um pacote `alinq-react` sobre promises e o cancelamento do ReactPHP, seria um pacote novo de mais ou menos o tamanho deste, construído sobre o mesmo gancho, sem tocar o núcleo nem este pacote. Ele não está planejado; seria construído sob demanda ([Roadmap](../project/roadmap.md)).
