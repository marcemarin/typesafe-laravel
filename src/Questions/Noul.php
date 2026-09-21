<?php

declare(strict_types=1);

namespace Marcemarin\TypeSafe\Questions;

/** "Is this true?" Returns a probability between 0 and 1 that the answer is yes. */
final readonly class Noul extends Question
{
    /** @param  array{true: string, false: string}|null  $criteria */
    private function __construct(string $instructions, public ?array $criteria = null)
    {
        parent::__construct($instructions);
    }

    public static function make(string $instructions): self
    {
        return new self($instructions);
    }

    public function instructions(string $instructions): self
    {
        return new self($instructions, $this->criteria);
    }

    /** Optionally clarify what a yes and a no mean. */
    public function criteria(string $true, string $false): self
    {
        return new self($this->instructions, ['true' => $true, 'false' => $false]);
    }

    public function type(): string
    {
        return 'noul';
    }

    public function toArray(): array
    {
        $question = ['type' => 'noul', 'instructions' => $this->instructions];

        if ($this->criteria !== null) {
            $question['criteria'] = $this->criteria;
        }

        return $question;
    }
}
