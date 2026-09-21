<?php

declare(strict_types=1);

use Marcemarin\TypeSafe\Answers\ChoiceAnswer;
use Marcemarin\TypeSafe\Exceptions\UnexpectedResponseException;
use Marcemarin\TypeSafe\Result;
use Marcemarin\TypeSafe\Usage;

describe('Result', function () {
    it('parses a full response', function () {
        $result = Result::fromArray(jevBody());

        expect($result->model)->toBe('jev-1.13.0')
            ->and($result->cached)->toBeFalse()
            ->and($result->has('intent'))->toBeTrue()
            ->and($result->has('nope'))->toBeFalse()
            ->and($result->choice('intent'))->toBeInstanceOf(ChoiceAnswer::class)
            ->and($result->choice('intent')->value)->toBe('complaint')
            ->and($result->score('on_air')->value)->toBe(3.6)
            ->and($result->noul('insult')->probability)->toBe(0.02)
            ->and($result->usage->inputTokens)->toBe(1234)
            ->and($result->usage->outputTokens)->toBe(0)
            ->and($result->answer('insult')->type())->toBe('noul');
    });

    it('flags results served from the cache', function () {
        expect(Result::fromArray(jevBody(), cached: true)->cached)->toBeTrue();
    });

    it('handles numeric question ids', function () {
        $result = Result::fromArray(jevBody(['answers' => ['1' => ['type' => 'noul', 'noul' => 0.5]]]));

        expect($result->noul('1')->probability)->toBe(0.5);
    });

    it('tolerates a missing model and usage', function () {
        $result = Result::fromArray(['answers' => ['a' => ['type' => 'noul', 'noul' => 0.5]]]);

        expect($result->model)->toBe('')
            ->and($result->usage->inputTokens)->toBe(0);
    });

    it('throws when an answer is requested with the wrong type', function () {
        Result::fromArray(jevBody())->score('intent');
    })->throws(InvalidArgumentException::class, "Answer 'intent' is a choice, not a score; use choice('intent') instead.");

    it('lists what was answered when the id is unknown', function () {
        Result::fromArray(jevBody())->noul('nope');
    })->throws(InvalidArgumentException::class, "There is no answer 'nope'. Answered: intent, on_air, insult.");

    it('says so when nothing was answered', function () {
        Result::fromArray(['answers' => []])->choice('nope');
    })->throws(InvalidArgumentException::class, '(none)');

    it('rejects a response without answers', function () {
        Result::fromArray(['model' => 'jev-1.13.0']);
    })->throws(UnexpectedResponseException::class, "no 'answers' map");

    it('rejects an answer that is not an object', function () {
        Result::fromArray(['answers' => ['a' => 'yes']]);
    })->throws(UnexpectedResponseException::class, "Answer 'a' is not an object");
});

describe('Usage', function () {
    it('parses token counts', function () {
        $usage = Usage::fromArray(['input_tokens' => 500, 'output_tokens' => 7]);

        expect($usage->inputTokens)->toBe(500)->and($usage->outputTokens)->toBe(7);
    });

    it('defaults to zero when counts are missing', function () {
        expect(Usage::fromArray([])->inputTokens)->toBe(0);
    });

    it('prices input at $0.042 per million tokens and output as free', function () {
        expect((new Usage(1_000_000, 500_000))->costUsd())->toBe(0.042)
            ->and((new Usage(1234, 0))->costUsd())->toEqualWithDelta(0.000051828, 1e-12)
            ->and((new Usage(0, 0))->costUsd())->toBe(0.0);
    });

    it('accepts price overrides', function () {
        expect((new Usage(1_000_000, 1_000_000))->costUsd(inputPerMillion: 1.0, outputPerMillion: 2.0))->toBe(3.0);
    });
});
