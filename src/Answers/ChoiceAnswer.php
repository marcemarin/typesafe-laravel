<?php

declare(strict_types=1);

namespace Marcemarin\TypeSafe\Answers;

use BackedEnum;
use Marcemarin\TypeSafe\Exceptions\UnexpectedResponseException;

final readonly class ChoiceAnswer extends Answer
{
    /**
     * @param  string  $value  The most probable option.
     * @param  array<string, float>  $probabilities  option => probability, over every option you offered.
     * @param  float  $confidence  0–1, as reported by the API.
     */
    public function __construct(
        public string $value,
        public array $probabilities,
        public float $confidence,
    ) {}

    /**
     * @param  array<mixed>  $data
     */
    public static function parse(string $id, array $data): self
    {
        $choice = $data['choice'] ?? null;
        if (! is_string($choice)) {
            throw new UnexpectedResponseException("Answer '{$id}' is missing a string 'choice'.");
        }

        /** @var array<string, float> $probabilities */
        $probabilities = self::probabilities($id, $data);

        return new self($choice, $probabilities, self::float($id, $data, 'confidence'));
    }

    public function type(): string
    {
        return 'choice';
    }

    /** Whether the winning option is $option (a string or a backed enum case). */
    public function is(string|BackedEnum $option): bool
    {
        return $this->value === self::key($option);
    }

    /** Probability of one option; 0.0 when it was not part of the question. */
    public function probability(string|BackedEnum $option): float
    {
        return $this->probabilities[self::key($option)] ?? 0.0;
    }

    public function isConfident(float $threshold = 0.8): bool
    {
        return $this->confidence >= $threshold;
    }

    /**
     * Map the winning option onto a backed enum.
     *
     * @template T of BackedEnum
     *
     * @param  class-string<T>  $enum
     * @return T
     *
     * @throws UnexpectedResponseException when the option is not a case of the enum
     */
    public function as(string $enum): BackedEnum
    {
        // Compare as strings: JSON option names are always strings, even for int-backed enums.
        foreach ($enum::cases() as $case) {
            if ((string) $case->value === $this->value) {
                return $case;
            }
        }

        throw new UnexpectedResponseException("'{$this->value}' is not a case of {$enum}.");
    }

    private static function key(string|BackedEnum $option): string
    {
        return $option instanceof BackedEnum ? (string) $option->value : $option;
    }
}
