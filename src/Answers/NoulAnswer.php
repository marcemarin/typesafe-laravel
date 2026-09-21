<?php

declare(strict_types=1);

namespace Marcemarin\TypeSafe\Answers;

use InvalidArgumentException;

final readonly class NoulAnswer extends Answer
{
    /** @param  float  $probability  0–1, the probability that the answer is yes. */
    public function __construct(public float $probability) {}

    /**
     * @param  array<mixed>  $data
     */
    public static function parse(string $id, array $data): self
    {
        return new self(self::float($id, $data, 'noul'));
    }

    public function type(): string
    {
        return 'noul';
    }

    /** Probability is at or above $threshold. */
    public function isTrue(float $threshold = 0.5): bool
    {
        self::assertProbability($threshold);

        return $this->probability >= $threshold;
    }

    /** Probability is strictly below $threshold. */
    public function isFalse(float $threshold = 0.5): bool
    {
        self::assertProbability($threshold);

        return $this->probability < $threshold;
    }

    /**
     * Probability is in the grey zone: at or above $low and below $high.
     * With the same numbers, isFalse($low), isUncertain($low, $high) and isTrue($high) partition 0–1.
     */
    public function isUncertain(float $low = 0.4, float $high = 0.6): bool
    {
        self::assertProbability($low);
        self::assertProbability($high);

        if ($low > $high) {
            throw new InvalidArgumentException("The lower bound ({$low}) cannot be above the upper bound ({$high}).");
        }

        return $this->probability >= $low && $this->probability < $high;
    }

    private static function assertProbability(float $threshold): void
    {
        if ($threshold < 0.0 || $threshold > 1.0) {
            throw new InvalidArgumentException("Thresholds are probabilities between 0 and 1, got {$threshold}.");
        }
    }
}
