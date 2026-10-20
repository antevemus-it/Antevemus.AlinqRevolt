<?php

declare(strict_types=1);

namespace Antevemus\ALinqRevolt;

use RuntimeException;
use Throwable;

/**
 * ALinqAsyncException - the failure of one item of an asynchronous operator, delivered at its position.
 *
 * Thrown by the stage of selectAsync(), whereAsync(), selectManyAsync() and eachAsync() when the item at
 * that position fails: the closure threw (the original is getPrevious()), its time limit ran out
 * (isTimeout(); getPrevious() is the Amp\CancelledException whose previous is the Amp\TimeoutException), or
 * selectManyAsync() received something that is not iterable (getPrevious() is the UnexpectedValueException
 * of the core's selectMany()). The items before it were already delivered and the items in flight are
 * cancelled. The message names the operator, the position and the key, and the class of the original;
 * never the value of the item nor the message of the original, which may carry it.
 *
 * @version    1.0.0
 * @package    Antevemus\ALinqRevolt
 * @author     Heliton Junior
 * @copyright  Copyright (c) 2026 Antevemus Soluções Inovadoras em TI Ltda. (https://antevemus.com.br)
 * @license    MIT License
 */
final class ALinqAsyncException extends RuntimeException
{
    /**
     * @param string $operator The operator, e.g. "selectAsync".
     * @param int $position The position of the item in the source, from 0.
     * @param mixed $key The key of the item in the source.
     * @param float|null $timeout The time limit in seconds when it ran out; null when the item failed.
     * @param Throwable $previous The original exception.
     */
    public function __construct(
        private readonly string $operator,
        private readonly int $position,
        private readonly mixed $key,
        private readonly ?float $timeout,
        Throwable $previous,
    ) {
        $where = sprintf('%s(): the item at position %d (key %s)', $operator, $position, self::describe($key));
        parent::__construct(
            $timeout !== null
                ? sprintf('%s exceeded its time limit of %s s', $where, $timeout)
                : sprintf('%s failed with %s', $where, $previous::class),
            0,
            $previous,
        );
    }

    /** The operator whose item failed, e.g. "selectAsync". */
    public function getOperator(): string
    {
        return $this->operator;
    }

    /** The position of the item in the source, from 0. */
    public function getPosition(): int
    {
        return $this->position;
    }

    /** The key of the item in the source. */
    public function getKey(): mixed
    {
        return $this->key;
    }

    /** Whether the item failed because its time limit ran out. */
    public function isTimeout(): bool
    {
        return $this->timeout !== null;
    }

    /** An integer or string key as PHP writes it; any other key by its type only. */
    private static function describe(mixed $key): string
    {
        return is_int($key) || is_string($key) ? var_export($key, true) : get_debug_type($key);
    }
}
