<?php

declare(strict_types=1);

namespace Marcemarin\TypeSafe;

use DateInterval;
use DateTimeInterface;
use Marcemarin\TypeSafe\Exceptions\InvalidQuestionException;
use Marcemarin\TypeSafe\Questions\Question;
use Marcemarin\TypeSafe\Support\JsonMap;

/** A fully built request, ready to send. Immutable; `PendingDecision` is what you normally build it with. */
final readonly class DecisionRequest
{
    /**
     * @param  string|array<mixed>  $state
     * @param  array<string, Question>  $questions
     * @param  DateTimeInterface|DateInterval|int|null  $cacheFor  Seconds, interval or expiry; null disables caching.
     */
    public function __construct(
        public string $model,
        public string|array $state,
        public array $questions,
        public DateTimeInterface|DateInterval|int|null $cacheFor = null,
    ) {}

    public function has(string $id): bool
    {
        return isset($this->questions[$id]);
    }

    public function question(string $id): ?Question
    {
        return $this->questions[$id] ?? null;
    }

    /**
     * @throws InvalidQuestionException
     */
    public function validate(): void
    {
        if (trim($this->model) === '') {
            throw new InvalidQuestionException('The model cannot be empty. Set TYPESAFE_MODEL or call ->model().');
        }
        if ($this->questions === []) {
            throw new InvalidQuestionException('Ask at least one question before calling get().');
        }

        foreach ($this->questions as $id => $question) {
            if (trim((string) $id) === '') {
                throw new InvalidQuestionException('Question ids cannot be empty.');
            }
            $question->validate((string) $id);
        }
    }

    /**
     * The JSON body of `POST /v1/systemone`.
     *
     * @return array<string, mixed>
     */
    public function toPayload(): array
    {
        return [
            'model' => $this->model,
            'state' => $this->state,
            'questions' => JsonMap::of(array_map(static fn (Question $q): array => $q->toArray(), $this->questions)),
        ];
    }

    /** Stable hash of everything that influences the answer; used as the cache key. */
    public function fingerprint(): string
    {
        return hash('sha256', json_encode($this->toPayload(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
    }
}
