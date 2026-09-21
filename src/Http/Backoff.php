<?php

declare(strict_types=1);

namespace Marcemarin\TypeSafe\Http;

/** Exponential backoff with "equal jitter": half the delay is fixed, half is random. */
final readonly class Backoff
{
    public function __construct(
        private int $baseMs = 500,
        private int $maxMs = 8000,
    ) {}

    /**
     * Milliseconds to wait before retry number $retry (1 = the first retry).
     */
    public function delayMs(int $retry): int
    {
        $ceiling = (int) min($this->maxMs, $this->baseMs * (2 ** max(0, $retry - 1)));
        $floor = intdiv($ceiling, 2);

        return random_int($floor, max($floor, $ceiling));
    }
}
