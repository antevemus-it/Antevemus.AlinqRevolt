<?php

declare(strict_types=1);

namespace Antevemus\ALinqRevolt\Tests\Http;

use Amp\Cancellation;
use Antevemus\ALinq\ALinqLazyCollection;
use Antevemus\ALinqRevolt\ALinqAsyncCollection;
use Antevemus\ALinqRevolt\Tests\Support\LatencyServer;
use PHPUnit\Framework\Attributes\Depends;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * The proof of gain, against a non-blocking Amp HTTP server started on 127.0.0.1 in a child process
 * (tests/Support/latency-server.php): 16 items, each a GET /item?ms=200.
 *
 * - The core select() with a blocking client (file_get_contents()) waits for one answer after the other:
 *   about 16 × 200 ms = 3 200 ms.
 * - selectAsync() with the Amp HTTP client and concurrency 8 waits for two rounds of eight (about 400 ms),
 *   with concurrency 16 for one round of sixteen (about 200 ms).
 * - The SAME blocking closure under selectAsync(16) still takes about 3 200 ms: a Fiber does not make a
 *   blocking call non-blocking (nor CPU work parallel); only the time spent waiting on non-blocking I/O
 *   overlaps.
 *
 * The bounds are generous (the CI machine may be slow) and every failure message carries the measured
 * times. When the server cannot bind or start in the environment the tests are skipped with the reason,
 * never passed silently.
 *
 * @version    1.0.0
 * @package    Antevemus\ALinqRevolt\Tests
 * @subpackage Http
 */
#[Group('http')]
final class ProofOfGainTest extends TestCase
{
    private const ITEMS = 16;

    private const LATENCY_MS = 200;

    private static ?LatencyServer $server = null;

    private static ?string $unavailable = null;

    /** @var array<string, float> measured milliseconds, by case */
    private static array $measured = [];

    public static function setUpBeforeClass(): void
    {
        try {
            self::$server = LatencyServer::start();
            // warm-up: the first request of each client pays for its setup, not for the latency under test
            self::$server->blockingGet(0, 'warm-up');
            ALinqAsyncCollection::from([0])->eachAsync(static fn(int $ms) => self::$server?->asyncGet($ms, 'warm-up'));
        } catch (RuntimeException $e) {
            self::$unavailable = $e->getMessage();
        }
    }

    public static function tearDownAfterClass(): void
    {
        self::$server?->stop();
        self::$server = null;
    }

    protected function setUp(): void
    {
        if (self::$server === null) {
            $this->markTestSkipped('The local Amp HTTP server could not start in this environment: ' . self::$unavailable);
        }
    }

    public function testTheCoreSelectWithABlockingClientWaitsForEachAnswerInTurn(): void
    {
        $server = $this->server();
        [$ms, $bodies] = self::time(static fn(): array => ALinqLazyCollection::from(range(1, self::ITEMS))
            ->select(static fn(int $id): string => $server->blockingGet(self::LATENCY_MS, $id))
            ->toArray());
        self::$measured['sync'] = $ms;

        $this->assertAnswers($bodies);
        $this->assertGreaterThan(2500, $ms, sprintf('core select(), blocking client: %.0f ms', $ms));
    }

    public function testSelectAsyncWithTheAmpClientOverlapsTheWaits(): void
    {
        $server = $this->server();
        foreach ([8 => 800, 16 => 600] as $concurrency => $bound) {
            $server->resetClient();
            [$ms, $bodies] = self::time(static fn(): array => ALinqAsyncCollection::from(range(1, self::ITEMS))
                ->selectAsync(static fn(int $id, int $key, Cancellation $cancellation): string => $server->asyncGet(self::LATENCY_MS, $id, $cancellation), $concurrency)
                ->toArray());
            self::$measured["async-$concurrency"] = $ms;

            $this->assertAnswers($bodies);
            $this->assertLessThan($bound, $ms, sprintf('selectAsync(%d), Amp client: %.0f ms', $concurrency, $ms));
            $this->assertGreaterThanOrEqual(self::LATENCY_MS * (self::ITEMS / $concurrency) - 20, $ms, sprintf('selectAsync(%d) cannot beat %d rounds of %d ms: %.0f ms', $concurrency, self::ITEMS / $concurrency, self::LATENCY_MS, $ms));
        }
        $this->assertLessThan(1000, self::$measured['async-16'], sprintf('selectAsync(16): %.0f ms', self::$measured['async-16']));
    }

    #[Depends('testTheCoreSelectWithABlockingClientWaitsForEachAnswerInTurn')]
    #[Depends('testSelectAsyncWithTheAmpClientOverlapsTheWaits')]
    public function testAsyncSixteenIsAtLeastThreeTimesFasterThanTheSynchronousSelect(): void
    {
        $this->assertLessThan(self::$measured['sync'] / 3, self::$measured['async-16'], sprintf('sync %.0f ms, async-16 %.0f ms', self::$measured['sync'], self::$measured['async-16']));
    }

    public function testTheSameBlockingClosureUnderSelectAsyncGainsNothing(): void
    {
        $server = $this->server();
        [$ms, $bodies] = self::time(static fn(): array => ALinqAsyncCollection::from(range(1, self::ITEMS))
            ->selectAsync(static fn(int $id): string => $server->blockingGet(self::LATENCY_MS, $id), 16)
            ->toArray());
        self::$measured['blocking-under-async'] = $ms;

        $this->assertAnswers($bodies);
        $this->assertGreaterThan(2500, $ms, sprintf('selectAsync(16), blocking client: %.0f ms; a Fiber does not overlap a blocking call', $ms));
    }

    private function server(): LatencyServer
    {
        $server = self::$server;
        $this->assertNotNull($server);

        return $server;
    }

    /** @param array<int, string> $bodies */
    private function assertAnswers(array $bodies): void
    {
        $ids = array_map(static fn(string $body): string => json_decode($body, true, 512, JSON_THROW_ON_ERROR)['id'], $bodies);
        $this->assertSame(array_map('strval', range(1, self::ITEMS)), $ids, 'every answer, in source order');
    }

    /**
     * @param \Closure(): array<int, string> $run
     * @return array{float, array<int, string>}
     */
    private static function time(\Closure $run): array
    {
        $started = hrtime(true);
        $result = $run();

        return [(hrtime(true) - $started) / 1e6, $result];
    }
}
