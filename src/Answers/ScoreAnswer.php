<?php

declare(strict_types=1);

namespace Marcemarin\TypeSafe\Answers;

final readonly class ScoreAnswer extends Answer
{
    /**
     * @param  float  $value  Probability-weighted level; it can fall between two levels (e.g. 3.6).
     * @param  array<int, string>  $legend  level => description, as echoed back by the API.
     * @param  array<int|string, float>  $probabilities  level => probability.
     * @param  float  $confidence  0–1, as reported by the API.
     */
    public function __construct(
        public float $value,
        public array $legend,
        public array $probabilities,
        public float $confidence,
    ) {}

    /**
     * @param  array<mixed>  $data
     */
    public static function parse(string $id, array $data): self
    {
        $legend = [];
        $rawLegend = $data['legend'] ?? [];
        foreach (is_array($rawLegend) ? $rawLegend : [] as $level => $description) {
            $legend[(int) $level] = (string) $description;
        }
        ksort($legend);

        return new self(
            self::float($id, $data, 'score'),
            $legend,
            self::probabilities($id, $data),
            self::float($id, $data, 'confidence'),
        );
    }

    public function type(): string
    {
        return 'score';
    }

    /** The value scaled to 0.0–1.0 using the number of levels in the legend. */
    public function normalized(): float
    {
        $top = count($this->legend) - 1;

        if ($top < 1) {
            return 0.0;
        }

        return max(0.0, min(1.0, $this->value / $top));
    }

    /** The nearest whole level. */
    public function level(): int
    {
        return (int) round($this->value);
    }

    /** Description of the nearest whole level, or an empty string if the legend does not have it. */
    public function label(): string
    {
        return $this->legend[$this->level()] ?? '';
    }

    public function isConfident(float $threshold = 0.8): bool
    {
        return $this->confidence >= $threshold;
    }
}
