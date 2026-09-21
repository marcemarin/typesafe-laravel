<?php

declare(strict_types=1);

namespace Marcemarin\TypeSafe\Answers;

use Marcemarin\TypeSafe\Exceptions\UnexpectedResponseException;

abstract readonly class Answer
{
    /** The question type this answer belongs to: `choice`, `score` or `noul`. */
    abstract public function type(): string;

    /**
     * @param  array<mixed>  $data  One entry of the response's `answers` map.
     *
     * @throws UnexpectedResponseException
     */
    public static function fromArray(string $id, array $data): self
    {
        return match ($data['type'] ?? null) {
            'choice' => ChoiceAnswer::parse($id, $data),
            'score' => ScoreAnswer::parse($id, $data),
            'noul' => NoulAnswer::parse($id, $data),
            default => throw new UnexpectedResponseException(
                "Answer '{$id}' has an unknown type: ".json_encode($data['type'] ?? null).'.'
            ),
        };
    }

    /**
     * @param  array<mixed>  $data
     */
    protected static function float(string $id, array $data, string $key): float
    {
        $value = $data[$key] ?? null;

        if (! is_int($value) && ! is_float($value)) {
            throw new UnexpectedResponseException("Answer '{$id}' is missing a numeric '{$key}'.");
        }

        return (float) $value;
    }

    /**
     * @param  array<mixed>  $data
     * @return array<int|string, float>
     */
    protected static function probabilities(string $id, array $data): array
    {
        $raw = $data['probabilities'] ?? null;

        if (! is_array($raw)) {
            throw new UnexpectedResponseException("Answer '{$id}' is missing 'probabilities'.");
        }

        return array_map(static fn (mixed $p): float => is_numeric($p) ? (float) $p : 0.0, $raw);
    }
}
