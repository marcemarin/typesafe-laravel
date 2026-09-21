<?php

declare(strict_types=1);

namespace Marcemarin\TypeSafe;

use InvalidArgumentException;
use Marcemarin\TypeSafe\Answers\Answer;
use Marcemarin\TypeSafe\Answers\ChoiceAnswer;
use Marcemarin\TypeSafe\Answers\NoulAnswer;
use Marcemarin\TypeSafe\Answers\ScoreAnswer;
use Marcemarin\TypeSafe\Exceptions\UnexpectedResponseException;

final readonly class Result
{
    /**
     * @param  string  $model  The model that actually answered (an alias like `jev-latest` resolves to a pinned version).
     * @param  array<string, Answer>  $answers
     * @param  bool  $cached  True when served from the Laravel cache; no request was made and no tokens were spent.
     */
    public function __construct(
        public string $model,
        public array $answers,
        public Usage $usage,
        public bool $cached = false,
    ) {}

    /**
     * @param  array<mixed>  $payload  The decoded response body.
     *
     * @throws UnexpectedResponseException
     */
    public static function fromArray(array $payload, bool $cached = false): self
    {
        $rawAnswers = $payload['answers'] ?? null;
        if (! is_array($rawAnswers)) {
            throw new UnexpectedResponseException("The response has no 'answers' map.");
        }

        $answers = [];
        foreach ($rawAnswers as $id => $data) {
            if (! is_array($data)) {
                throw new UnexpectedResponseException("Answer '{$id}' is not an object.");
            }
            $answers[(string) $id] = Answer::fromArray((string) $id, $data);
        }

        $usage = $payload['usage'] ?? [];

        return new self(
            is_string($payload['model'] ?? null) ? $payload['model'] : '',
            $answers,
            Usage::fromArray(is_array($usage) ? $usage : []),
            $cached,
        );
    }

    public function has(string $id): bool
    {
        return isset($this->answers[$id]);
    }

    public function answer(string $id): Answer
    {
        return $this->answers[$id]
            ?? throw new InvalidArgumentException("There is no answer '{$id}'. Answered: ".($this->answers === [] ? '(none)' : implode(', ', array_keys($this->answers))).'.');
    }

    public function choice(string $id): ChoiceAnswer
    {
        $answer = $this->answer($id);

        return $answer instanceof ChoiceAnswer ? $answer : throw self::wrongType($id, 'choice', $answer);
    }

    public function score(string $id): ScoreAnswer
    {
        $answer = $this->answer($id);

        return $answer instanceof ScoreAnswer ? $answer : throw self::wrongType($id, 'score', $answer);
    }

    public function noul(string $id): NoulAnswer
    {
        $answer = $this->answer($id);

        return $answer instanceof NoulAnswer ? $answer : throw self::wrongType($id, 'noul', $answer);
    }

    private static function wrongType(string $id, string $wanted, Answer $actual): InvalidArgumentException
    {
        return new InvalidArgumentException("Answer '{$id}' is a {$actual->type()}, not a {$wanted}; use {$actual->type()}('{$id}') instead.");
    }
}
