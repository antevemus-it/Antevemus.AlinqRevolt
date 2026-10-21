# 🧪 Testes

A suíte roda no PHPUnit 11 sob o PHP 8.4, sem extensão do PHP exigida:

```bash
XDEBUG_MODE=off vendor/bin/phpunit --no-coverage
```

```text
PHPUnit 11.5.57 by Sebastian Bergmann and contributors.

Runtime:       PHP 8.4.13
Configuration: phpunit.xml.dist

................................................................. 65 / 99 ( 65%)
..................................                                99 / 99 (100%)

Time: 00:11.438, Memory: 8.00 MB

OK (99 tests, 4436 assertions)
```

- **99 testes e 4436 asserções**, nenhum pulado, em três suítes: `tests/Unit` (o runner, os quatro operadores, a documentação), `tests/Property` (propriedades com o núcleo como oráculo) e `tests/Http` (a prova de ganho, grupo `http`). O relato arquivo por arquivo está em `tests/README.md`.
- **O runner** (`RevoltRunnerTest`): o valor de uma tarefa, um `Future` devolvido e esperado, a exceção dela, no máximo `concurrency` rodando com os chamadores em espera atendidos na ordem de chegada (dentro de uma Fiber, o chamador é suspenso, não bloqueado), o limite de tempo como uma `CancelledException` que carrega uma `TimeoutException`, uma tarefa que ignora o cancelamento mantendo a vaga até retornar, o cancelamento passado, `cancel()`.
- **Os operadores** (`ALinqAsyncCollectionTest`): valores, chaves e ordem (contra `select()`/`where()`/`selectMany()` do núcleo, incluindo o dicionário de chaves inteiras, cujas chaves `toArray()` mantém como o núcleo), preguiça (nada roda antes do percurso; a fonte puxada um item por vaga livre, medido item a item), a janela nunca acima de `concurrency` (contadores em 1, 2, 5 e no padrão 8 de cada operador), parada antecipada (`take()`, `first()`, `any()`, `break`) iniciando no máximo os itens consumidos mais `concurrency` e cancelando o que está em voo, erros e limites de tempo na posição do item com os anteriores entregues, um erro da fonte sem embrulho, as checagens de argumentos, todo tipo de fonte e de callable, um runner compartilhado limitando dois estágios juntos.
- **Propriedades** (`AsyncPropertyTest`, 200 entradas semeadas por propriedade, sementes fixas): listas e dicionários de 0 a 12 itens, concorrência de 1 a 10, um atraso não bloqueante aleatório por item, para que as closures terminem fora da ordem da fonte (medido: em 128 das 200 entradas da primeira propriedade). `selectAsync()`/`whereAsync()`/`selectManyAsync()` produzem as chaves, valores e ordem de `select()`/`where()`/`selectMany()` do núcleo e `toArray()` responde como o núcleo sobre o mesmo array (dicionários de chaves inteiras incluídos), sobre uma coleção preguiçosa do ALinq dele e sobre um `ArrayIterator` dos mesmos itens; `eachAsync()` roda cada ação uma vez; `take(k)` inicia no máximo k + concorrência itens; `where`, `orderBy`, `concat` e `groupBy` mais adiante respondem como sobre o `select()` síncrono.
- **A documentação** (`DocumentationBlocksTest`, `DocumentationTreeTest`): os 8 blocos PHP de cada idioma e o Início Rápido de cada README rodam como escritos, com cada resposta dos comentários conferida; os dois idiomas têm as mesmas páginas, navegação e código, token a token; todo link resolve; nada interno vaza.

## Prova de ganho

`tests/Http/ProofOfGainTest.php` sobe um servidor HTTP não bloqueante do Amp numa porta livre de 127.0.0.1, num processo filho (`tests/Support/latency-server.php`; `GET /item?ms=200` responde depois de 200 ms sem bloquear) e roda 16 itens. Ele afirma, com limites folgados e os tempos medidos em toda mensagem de falha: o `select()` do núcleo com um cliente bloqueante (`file_get_contents()`) acima de 2 500 ms; `selectAsync()` com o cliente HTTP do Amp abaixo de 800 ms com concorrência 8 e abaixo de 600 ms com 16 (e nunca abaixo das rodadas de que precisa: 2 × 200 e 1 × 200 ms); concorrência 16 pelo menos três vezes mais rápida que o `select()` síncrono; a mesma closure bloqueante sob `selectAsync(concurrency: 16)` acima de 2 500 ms. Quando o servidor não consegue subir, os testes são pulados com o motivo, nunca aprovados.

A tabela, medida com `XDEBUG_MODE=off php tests/Support/timing-table.php 3` (mediana de 3 rodadas por célula, PHP 8.4.13, um cliente Amp novo por rodada):

| Itens × 200 ms | select() do núcleo, bloqueante | selectAsync(16), bloqueante | selectAsync(1), cliente Amp | selectAsync(8), cliente Amp | selectAsync(16), cliente Amp |
| ---: | ---: | ---: | ---: | ---: | ---: |
| 1 | 201 ms | 201 ms | 203 ms | 202 ms | 202 ms |
| 4 | 807 ms | 807 ms | 809 ms | 204 ms | 204 ms |
| 16 | 3 222 ms | 3 225 ms | 3 243 ms | 415 ms | 214 ms |

- **Por que cada número:** uma chamada bloqueante segura a única thread, então n itens levam n × 200 ms qualquer que seja a concorrência (as duas primeiras colunas, e a concorrência 1, em que a janela tem um item). Com o cliente Amp, `concurrency` requisições esperam juntas: 16 itens levam ⌈16 / c⌉ rodadas de 200 ms (415 ms com 8, duas rodadas; 214 ms com 16, uma); 4 itens cabem numa rodada com 8 e 16. Os poucos milissegundos acima de cada múltiplo de 200 são as próprias requisições pelo loopback.
- **Um achado do fixture:** sem `TCP_NODELAY` no socket do servidor, cada requisição do cliente Amp custava 44 ms a mais (medido com `ms=0`: 43,9 ms por requisição com o cliente Amp, 1,0 ms com `file_get_contents()`; 1,3 ms com `TCP_NODELAY`). O cliente Amp mantém a conexão viva, e o algoritmo de Nagle segurava o corpo da resposta até o cliente confirmar os cabeçalhos, uma confirmação que o cliente atrasa; `file_get_contents()` fecha a conexão, o que descarrega. O servidor dos testes liga `TCP_NODELAY`; o custo era do fixture, não do que se mede.

## Teste de mutação

Infection sobre todo o `src/` (`infection.json5`, o workflow de Qualidade), com o grupo `http` de fora porque ele mede tempo de relógio:

```bash
XDEBUG_MODE=off vendor/bin/infection --threads=max --only-covering-test-cases \
    --initial-tests-php-options="-d pcov.enabled=1 -d pcov.directory=$PWD/src"
```

Medido em 2026-10-20 (Infection 0.35.8, PHP 8.4.13 com pcov): **177 mutantes; 168 mortos pelos testes, 1 erro e 4 timeouts (detectados), 4 escapados, todos equivalentes; MSI 97,74%** (MSI do código coberto 97,74%, cobertura de mutação 100%). Os erros e timeouts são mutantes que quebram o laço da janela (a fonte puxada de novo para sempre, ou nunca avançada) ou a entrega de uma vaga livre; entre rodadas um deles (o `suspend()` removido de `RevoltRunner::start()`) já foi classificado como timeout ou como erro, com o mesmo placar. O mutante que declarava o resultado de `selectManyAsync()` um dicionário em vez de uma lista escapou da primeira rodada desta versão e é morto por um teste do `where()` do núcleo depois dele. Os 4 escapados:

- remover `$scope->cancel()` do `finally` da janela, e remover `$everything->cancel()` de `RevoltRunner::cancel()`: os dois objetos `Amp\DeferredCancellation` são liberados logo em seguida, e o destrutor deles cancela;
- remover o `unsubscribe()` do cancelamento combinado quando uma tarefa termina: esse cancelamento é liberado junto com a tarefa;
- transformar o `finally` de uma tarefa em código comum depois do `try`/`catch`: o `catch (Throwable)` já recebe toda exceção; os dois só diferem quando uma Fiber suspensa é destruída, o que nenhum chamador provoca pela API.

`minMsi` e `minCoveredMsi` são 96 no `infection.json5`, um ponto inteiro abaixo do placar medido.
