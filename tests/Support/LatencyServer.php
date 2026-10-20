<?php

declare(strict_types=1);

namespace Antevemus\ALinqRevolt\Tests\Support;

use Amp\Cancellation;
use Amp\Http\Client\HttpClient;
use Amp\Http\Client\HttpClientBuilder;
use Amp\Http\Client\Request;
use RuntimeException;

/**
 * The child process of latency-server.php, and the three clients of the measurements: a blocking one
 * (file_get_contents()) and the Amp HTTP client (non-blocking) inside a closure of selectAsync().
 *
 * @version    1.0.0
 * @package    Antevemus\ALinqRevolt\Tests
 * @subpackage Support
 */
final class LatencyServer
{
    /** @var resource */
    private $process;

    /** @var array<int, resource> */
    private array $pipes;

    private HttpClient $client;

    private function __construct(public readonly int $port)
    {
        $this->client = HttpClientBuilder::buildDefault();
    }

    /**
     * Starts the server and waits up to 10 s for its "READY <port>" line.
     *
     * @throws RuntimeException When the process cannot start or does not report a port (it cannot bind)
     */
    public static function start(): self
    {
        $process = proc_open(
            [PHP_BINARY, '-d', 'xdebug.mode=off', __DIR__ . '/latency-server.php'],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            null,
            ['XDEBUG_MODE' => 'off'] + getenv()
        );
        if (!is_resource($process)) {
            throw new RuntimeException('cannot start tests/Support/latency-server.php');
        }
        stream_set_blocking($pipes[1], false);
        $deadline = microtime(true) + 10.0;
        $output = '';
        while (microtime(true) < $deadline) {
            $output .= (string) stream_get_contents($pipes[1]);
            if (preg_match('/^READY (\d+)$/m', $output, $m) === 1) {
                $server = new self((int) $m[1]);
                $server->process = $process;
                $server->pipes = $pipes;
                return $server;
            }
            if (!proc_get_status($process)['running']) {
                break;
            }
            usleep(10000);
        }
        $errors = (string) stream_get_contents($pipes[2]);
        proc_terminate($process);
        throw new RuntimeException('tests/Support/latency-server.php did not report a port: ' . trim(preg_replace('/^Cannot load Xdebug.*$/m', '', $errors . $output) ?? ''));
    }

    /**
     * A new Amp client, with an empty connection pool: called before each measurement, so no measurement
     * reuses a keep-alive connection the server may have closed while idle (its HTTP/1.1 timeout is 15 s;
     * found by the timing table, whose blocking columns leave the pool idle for about 20 s).
     */
    public function resetClient(): void
    {
        $this->client = HttpClientBuilder::buildDefault();
    }

    public function url(int $ms, int|string $id): string
    {
        return sprintf('http://127.0.0.1:%d/item?ms=%d&id=%s', $this->port, $ms, rawurlencode((string) $id));
    }

    /** A blocking GET: the PHP stream wrapper waits for the whole answer and the event loop stands still. */
    public function blockingGet(int $ms, int|string $id): string
    {
        $body = file_get_contents($this->url($ms, $id));
        if ($body === false) {
            throw new RuntimeException("GET item $id failed");
        }

        return $body;
    }

    /** A non-blocking GET with the Amp HTTP client: the Fiber is suspended while the answer travels. */
    public function asyncGet(int $ms, int|string $id, ?Cancellation $cancellation = null): string
    {
        $response = $this->client->request(new Request($this->url($ms, $id)), $cancellation);

        return $response->getBody()->buffer($cancellation);
    }

    public function stop(): void
    {
        foreach ($this->pipes as $pipe) {
            if (is_resource($pipe)) {
                fclose($pipe);
            }
        }
        proc_terminate($this->process);
        proc_close($this->process);
    }
}
