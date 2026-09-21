<?php

declare(strict_types=1);

namespace Marcemarin\TypeSafe\Testing;

use BackedEnum;
use Closure;
use InvalidArgumentException;
use Marcemarin\TypeSafe\Answers\Answer;
use Marcemarin\TypeSafe\Answers\ChoiceAnswer;
use Marcemarin\TypeSafe\Answers\NoulAnswer;
use Marcemarin\TypeSafe\Answers\ScoreAnswer;
use Marcemarin\TypeSafe\Contracts\Client;
use Marcemarin\TypeSafe\DecisionRequest;
use Marcemarin\TypeSafe\PendingDecision;
use Marcemarin\TypeSafe\Questions\Choice;
use Marcemarin\TypeSafe\Questions\Noul;
use Marcemarin\TypeSafe\Questions\Question;
use Marcemarin\TypeSafe\Questions\Score;
use Marcemarin\TypeSafe\Result;
use Marcemarin\TypeSafe\Usage;
use PHPUnit\Framework\Assert;

/**
 * A stand-in for the real client that records requests and fabricates well-formed answers.
 *
 * Answers are given per question id:
 *  - choice: an option name or backed enum case, or ['choice' => 'x', 'confidence' => 0.7]
 *  - score:  a number (3.5 sits between levels 3 and 4), or ['score' => 3.5, 'confidence' => 0.7]
 *  - noul:   a probability, or a bool (true = 1.0, false = 0.0)
 *  - any of them can also be a ready-made Answer, or a Closure(Question, DecisionRequest): mixed.
 * Questions without a scripted answer get the first option, level 0 and probability 0.0.
 */
final class FakeClient implements Client
{
    private const DEFAULT_CONFIDENCE = 0.95;

    /** @var list<DecisionRequest> */
    private array $recorded = [];

    private Usage $usage;

    /**
     * @param  array<string, mixed>  $answers
     */
    public function __construct(
        private array $answers = [],
        private readonly string $model = 'jev-latest',
    ) {
        $this->usage = new Usage;
    }

    /**
     * Script more answers (merged over the existing ones).
     *
     * @param  array<string, mixed>  $answers
     */
    public function answer(array $answers): self
    {
        $this->answers = [...$this->answers, ...$answers];

        return $this;
    }

    /** Usage reported by every fake result (defaults to zero tokens). */
    public function usage(int $inputTokens, int $outputTokens = 0): self
    {
        $this->usage = new Usage($inputTokens, $outputTokens);

        return $this;
    }

    public function state(string|array|object $state): PendingDecision
    {
        return PendingDecision::for($this, $this->model, $state);
    }

    public function defaultModel(): string
    {
        return $this->model;
    }

    public function send(DecisionRequest $request): Result
    {
        // Validate like the real client so tests catch malformed questions too.
        $request->validate();
        $this->recorded[] = $request;

        $answers = [];
        foreach ($request->questions as $id => $question) {
            $answers[$id] = $this->answerFor($id, $question, $request);
        }

        return new Result($request->model, $answers, $this->usage);
    }

    /** @return list<DecisionRequest> */
    public function recorded(): array
    {
        return $this->recorded;
    }

    /**
     * Assert that a request matching $callback was sent (any request when null), optionally an exact number of times.
     *
     * @param  (Closure(DecisionRequest): bool)|null  $callback
     */
    public function assertAsked(?Closure $callback = null, ?int $times = null): void
    {
        $matching = $callback === null
            ? $this->recorded
            : array_filter($this->recorded, static fn (DecisionRequest $request): bool => $callback($request) === true);

        if ($times === null) {
            Assert::assertNotEmpty($matching, 'The expected TypeSafe request was not sent. Requests sent: '.count($this->recorded).'.');

            return;
        }

        Assert::assertCount($times, $matching, "Expected {$times} matching TypeSafe request(s), found ".count($matching).'.');
    }

    public function assertAskedCount(int $count): void
    {
        Assert::assertCount($count, $this->recorded, "Expected {$count} TypeSafe request(s), found ".count($this->recorded).'.');
    }

    public function assertNothingAsked(): void
    {
        Assert::assertEmpty($this->recorded, 'Unexpected TypeSafe requests were sent: '.count($this->recorded).'.');
    }

    private function answerFor(string $id, Question $question, DecisionRequest $request): Answer
    {
        if (! array_key_exists($id, $this->answers)) {
            return $this->build($id, $question, null);
        }

        $scripted = $this->answers[$id];
        if ($scripted instanceof Closure) {
            $scripted = $scripted($question, $request);
        }

        return $scripted instanceof Answer ? $scripted : $this->build($id, $question, $scripted);
    }

    private function build(string $id, Question $question, mixed $scripted): Answer
    {
        return match (true) {
            $question instanceof Choice => $this->choice($id, $question, $scripted),
            $question instanceof Score => $this->score($id, $question, $scripted),
            $question instanceof Noul => $this->noul($id, $scripted),
            default => throw new InvalidArgumentException("Cannot fake a '{$question->type()}' question."),
        };
    }

    private function choice(string $id, Choice $question, mixed $scripted): ChoiceAnswer
    {
        [$scripted, $confidence] = $this->unwrap($scripted, 'choice');
        $option = $scripted instanceof BackedEnum ? (string) $scripted->value : ($scripted ?? array_key_first($question->options));

        if (! is_string($option) && ! is_int($option)) {
            throw new InvalidArgumentException("The fake answer for '{$id}' must be an option name or backed enum case.");
        }
        $option = (string) $option;

        if (! array_key_exists($option, $question->options)) {
            throw new InvalidArgumentException("The fake answer for '{$id}' is '{$option}', but the question only offers: ".implode(', ', array_keys($question->options)).'.');
        }

        // The winner gets `confidence`; the remainder is split evenly among the other options.
        $others = count($question->options) - 1;
        $probabilities = [];
        foreach (array_keys($question->options) as $candidate) {
            $probabilities[(string) $candidate] = (string) $candidate === $option ? $confidence : (1 - $confidence) / $others;
        }

        return new ChoiceAnswer($option, $probabilities, $confidence);
    }

    private function score(string $id, Score $question, mixed $scripted): ScoreAnswer
    {
        [$scripted, $confidence] = $this->unwrap($scripted, 'score');
        $value = $scripted ?? 0;

        if (! is_int($value) && ! is_float($value)) {
            throw new InvalidArgumentException("The fake answer for '{$id}' must be a number.");
        }

        $top = count($question->levels) - 1;
        if ($value < 0 || $value > $top) {
            throw new InvalidArgumentException("The fake answer for '{$id}' is {$value}, but the levels only go from 0 to {$top}.");
        }

        // Spread the probability over the two neighbouring levels so the weighted value equals $value.
        $lower = (int) floor($value);
        $upper = (int) ceil($value);
        $probabilities = array_fill_keys(array_keys($question->levels), 0.0);
        if ($lower === $upper) {
            $probabilities[$lower] = 1.0;
        } else {
            $probabilities[$upper] = $value - $lower;
            $probabilities[$lower] = 1.0 - ($value - $lower);
        }

        return new ScoreAnswer((float) $value, $question->levels, $probabilities, $confidence);
    }

    private function noul(string $id, mixed $scripted): NoulAnswer
    {
        $probability = $scripted ?? 0.0;

        if (is_bool($probability)) {
            $probability = $probability ? 1.0 : 0.0;
        }
        if (! is_int($probability) && ! is_float($probability)) {
            throw new InvalidArgumentException("The fake answer for '{$id}' must be a probability or a bool.");
        }
        if ($probability < 0 || $probability > 1) {
            throw new InvalidArgumentException("The fake answer for '{$id}' is {$probability}, but a probability must be between 0 and 1.");
        }

        return new NoulAnswer((float) $probability);
    }

    /**
     * Split ['choice' => 'x', 'confidence' => 0.7] into [value, confidence]; plain values pass through.
     *
     * @return array{0: mixed, 1: float}
     */
    private function unwrap(mixed $scripted, string $key): array
    {
        if (! is_array($scripted)) {
            return [$scripted, self::DEFAULT_CONFIDENCE];
        }

        $confidence = $scripted['confidence'] ?? self::DEFAULT_CONFIDENCE;
        if (! is_int($confidence) && ! is_float($confidence)) {
            throw new InvalidArgumentException('A fake confidence must be a number between 0 and 1.');
        }

        return [$scripted[$key] ?? null, (float) $confidence];
    }
}
