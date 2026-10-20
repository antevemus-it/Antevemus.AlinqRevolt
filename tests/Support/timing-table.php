<?php

/**
 * Measures the timing table of the documentation (docs/<language>/quality/tests.md and the README):
 *
 *   XDEBUG_MODE=off php tests/Support/timing-table.php [repetitions]
 *
 * Starts tests/Support/latency-server.php on 127.0.0.1 and, for 1, 4 and 16 items of GET /item?ms=200,
 * runs the core select() with a blocking client (file_get_contents()), selectAsync() with the same
 * blocking closure (concurrency 16), and selectAsync() with the Amp HTTP client at concurrency 1, 8 and
 * 16. Each cell is the median of the repetitions (default 3), in milliseconds, after one warm-up request
 * per client; each run starts with a new Amp client (a new connection pool), its connection setup included. Prints a Markdown table and the PHP version.
 *
 * @version    1.0.0
 * @package    Antevemus\ALinqRevolt\Tests
 * @subpackage Support
 */

declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Amp\Cancellation;
use Antevemus\ALinq\ALinqLazyCollection;
use Antevemus\ALinqRevolt\ALinqAsyncCollection;
use Antevemus\ALinqRevolt\Tests\Support\LatencyServer;

$repetitions = max(1, (int) ($argv[1] ?? 3));
$latency = 200;
$server = LatencyServer::start();
$server->blockingGet(0, 'warm-up');
ALinqAsyncCollection::from([0])->eachAsync(static fn(int $ms) => $server->asyncGet($ms, 'warm-up'));

$columns = [
    'core select(), blocking' => static fn(array $ids): array => ALinqLazyCollection::from($ids)->select(static fn(int $id): string => $server->blockingGet($latency, $id))->toArray(),
    'selectAsync(16), blocking' => static fn(array $ids): array => ALinqAsyncCollection::from($ids)->selectAsync(static fn(int $id): string => $server->blockingGet($latency, $id), 16)->toArray(),
];
foreach ([1, 8, 16] as $concurrency) {
    $columns["selectAsync($concurrency), Amp client"] = static fn(array $ids): array => ALinqAsyncCollection::from($ids)
        ->selectAsync(static fn(int $id, int $key, Cancellation $cancellation): string => $server->asyncGet($latency, $id, $cancellation), $concurrency)
        ->toArray();
}

$median = static function (array $values): float {
    sort($values);
    return $values[intdiv(count($values), 2)];
};

echo '| Items × ' . $latency . ' ms | ' . implode(' | ', array_keys($columns)) . " |\n";
echo '| ---: |' . str_repeat(' ---: |', count($columns)) . "\n";
foreach ([1, 4, 16] as $items) {
    $ids = range(1, $items);
    $cells = [];
    foreach ($columns as $run) {
        $times = [];
        for ($r = 0; $r < $repetitions; $r++) {
            $server->resetClient();
            $started = hrtime(true);
            $answers = $run($ids);
            $times[] = (hrtime(true) - $started) / 1e6;
            if (count($answers) !== $items) {
                throw new RuntimeException('missing answers');
            }
        }
        $cells[] = number_format($median($times), 0, '.', ' ') . ' ms';
    }
    echo "| $items | " . implode(' | ', $cells) . " |\n";
}
echo "\nPHP " . PHP_VERSION . ", median of $repetitions runs per cell\n";
$server->stop();
