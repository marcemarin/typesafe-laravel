<?php

declare(strict_types=1);

namespace Marcemarin\TypeSafe\Questions;

use Marcemarin\TypeSafe\Exceptions\InvalidQuestionException;

/** "Which level?" Ordered rubric; the answer is a probability-weighted value that can fall between levels. */
final readonly class Score extends Question
{
    public const MIN_LEVELS = 2;

    public const MAX_LEVELS = 10;

    /** @param  list<string>  $levels  Level descriptions, lowest first (level 0 is the first entry). */
    private function __construct(string $instructions, public array $levels = [])
    {
        parent::__construct($instructions);
    }

    public static function make(string $instructions): self
    {
        return new self($instructions);
    }

    public function instructions(string $instructions): self
    {
        return new self($instructions, $this->levels);
    }

    /**
     * Describe each level of the rubric, lowest first.
     *
     * @param  array<int|string, string>  $levels
     */
    public function levels(array $levels): self
    {
        return new self($this->instructions, array_values($levels));
    }

    public function type(): string
    {
        return 'score';
    }

    public function validate(string $id): void
    {
        parent::validate($id);

        $count = count($this->levels);
        if ($count < self::MIN_LEVELS || $count > self::MAX_LEVELS) {
            throw new InvalidQuestionException(
                "Question '{$id}' (score) needs between ".self::MIN_LEVELS.' and '.self::MAX_LEVELS." levels, got {$count}."
            );
        }
    }

    public function toArray(): array
    {
        return [
            'type' => 'score',
            'instructions' => $this->instructions,
            'criteria' => $this->levels,
        ];
    }
}
