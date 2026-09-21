<?php

declare(strict_types=1);

namespace Marcemarin\TypeSafe\Questions;

use Marcemarin\TypeSafe\Exceptions\InvalidQuestionException;

abstract readonly class Question
{
    public function __construct(public string $instructions) {}

    /** The `type` value the API expects: `choice`, `score` or `noul`. */
    abstract public function type(): string;

    /**
     * The question as the API expects it.
     *
     * @return array<string, mixed>
     */
    abstract public function toArray(): array;

    /**
     * Client-side checks so mistakes surface with a helpful message instead of a bare 422.
     *
     * @throws InvalidQuestionException
     */
    public function validate(string $id): void
    {
        if (trim($this->instructions) === '') {
            throw new InvalidQuestionException("Question '{$id}' ({$this->type()}) needs non-empty instructions.");
        }
    }
}
