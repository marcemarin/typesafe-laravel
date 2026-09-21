<?php

declare(strict_types=1);

namespace Marcemarin\TypeSafe;

use Closure;
use DateInterval;
use DateTimeInterface;
use Illuminate\Contracts\Support\Arrayable;
use InvalidArgumentException;
use JsonSerializable;
use Marcemarin\TypeSafe\Contracts\Client;
use Marcemarin\TypeSafe\Questions\Question;
use Stringable;

/**
 * Fluent, immutable request builder: every method returns a new instance, so a base
 * builder can be shared and extended safely.
 */
final readonly class PendingDecision
{
    /**
     * @param  string|array<mixed>  $state
     * @param  array<string, Question>  $questions
     */
    private function __construct(
        private Client $client,
        private string $model,
        private string|array $state,
        private array $questions = [],
        private DateTimeInterface|DateInterval|int|null $cacheFor = null,
    ) {}

    /**
     * @param  string|array<mixed>|object  $state
     */
    public static function for(Client $client, string $model, string|array|object $state): self
    {
        return new self($client, $model, self::normalizeState($state));
    }

    public function ask(string $id, Question $question): self
    {
        return new self($this->client, $this->model, $this->state, [...$this->questions, $id => $question], $this->cacheFor);
    }

    /** Use another model (an alias like `jev-preview`, or a pinned version like `jev-1.13.0`) for this request only. */
    public function model(string $model): self
    {
        return new self($this->client, $model, $this->state, $this->questions, $this->cacheFor);
    }

    /** Serve identical requests (same model, state and questions) from the Laravel cache for this long. */
    public function cacheFor(DateTimeInterface|DateInterval|int $ttl): self
    {
        return new self($this->client, $this->model, $this->state, $this->questions, $ttl);
    }

    public function request(): DecisionRequest
    {
        return new DecisionRequest($this->model, $this->state, $this->questions, $this->cacheFor);
    }

    /**
     * Send the request.
     *
     * @throws Exceptions\TypeSafeException
     */
    public function get(): Result
    {
        return $this->client->send($this->request());
    }

    /**
     * @param  string|array<mixed>|object  $state
     * @return string|array<mixed>
     */
    private static function normalizeState(string|array|object $state): string|array
    {
        return match (true) {
            is_string($state), is_array($state) => $state,
            $state instanceof Arrayable => $state->toArray(),
            $state instanceof JsonSerializable => self::fromJson($state),
            $state instanceof Stringable => (string) $state,
            $state instanceof Closure => throw new InvalidArgumentException('A Closure cannot be used as state.'),
            default => get_object_vars($state),
        };
    }

    /**
     * @return string|array<mixed>
     */
    private static function fromJson(JsonSerializable $state): string|array
    {
        $data = $state->jsonSerialize();

        return is_string($data) || is_array($data)
            ? $data
            : throw new InvalidArgumentException('JsonSerializable state must serialize to a string, array or object.');
    }
}
