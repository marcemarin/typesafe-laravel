<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use Marcemarin\TypeSafe\Answers\ChoiceAnswer;
use Marcemarin\TypeSafe\Contracts\Client;
use Marcemarin\TypeSafe\DecisionRequest;
use Marcemarin\TypeSafe\Exceptions\InvalidQuestionException;
use Marcemarin\TypeSafe\Facades\TypeSafe;
use Marcemarin\TypeSafe\Questions\Choice;
use Marcemarin\TypeSafe\Questions\Noul;
use Marcemarin\TypeSafe\Questions\Question;
use Marcemarin\TypeSafe\Questions\Score;
use Marcemarin\TypeSafe\Testing\FakeClient;
use Marcemarin\TypeSafe\Tests\Support\Intent;
use PHPUnit\Framework\AssertionFailedError;

describe('scripted answers', function () {
    it('returns well-formed answers for each question type', function () {
        TypeSafe::fake(['intent' => 'complaint', 'on_air' => 3.5, 'insult' => 0.02]);

        $result = askEverything();

        expect($result->model)->toBe('jev-latest')
            ->and($result->choice('intent')->value)->toBe('complaint')
            ->and($result->choice('intent')->confidence)->toBe(0.95)
            ->and($result->choice('intent')->probabilities)->toEqualWithDelta(['complaint' => 0.95, 'request' => 0.05], 1e-12)
            ->and($result->score('on_air')->value)->toBe(3.5)
            ->and($result->score('on_air')->legend)->toBe([0 => 'Unusable', 1 => 'Weak', 2 => 'Acceptable', 3 => 'Good', 4 => 'Excellent'])
            ->and($result->score('on_air')->normalized())->toBe(0.875)
            ->and($result->noul('insult')->probability)->toBe(0.02);
    });

    it('spreads a between-levels score over its neighbours so the weighted value adds up', function () {
        TypeSafe::fake(['on_air' => 3.5]);

        $probabilities = askEverything()->score('on_air')->probabilities;

        expect($probabilities)->toBe([0 => 0.0, 1 => 0.0, 2 => 0.0, 3 => 0.5, 4 => 0.5])
            ->and(array_sum(array_map(fn ($level, $p) => $level * $p, array_keys($probabilities), $probabilities)))->toBe(3.5);
    });

    it('puts all the mass on a whole-level score', function () {
        TypeSafe::fake(['on_air' => 2]);

        expect(askEverything()->score('on_air')->probabilities)->toBe([0 => 0.0, 1 => 0.0, 2 => 1.0, 3 => 0.0, 4 => 0.0]);
    });

    it('accepts a backed enum case for a choice', function () {
        TypeSafe::fake(['intent' => Intent::Request]);

        expect(askEverything()->choice('intent')->as(Intent::class))->toBe(Intent::Request);
    });

    it('accepts detailed choice and score answers with a confidence', function () {
        TypeSafe::fake([
            'intent' => ['choice' => 'request', 'confidence' => 0.55],
            'on_air' => ['score' => 1, 'confidence' => 0.4],
        ]);

        $result = askEverything();

        expect($result->choice('intent')->value)->toBe('request')
            ->and($result->choice('intent')->confidence)->toBe(0.55)
            ->and($result->choice('intent')->probabilities)->toEqualWithDelta(['complaint' => 0.45, 'request' => 0.55], 1e-12)
            ->and($result->score('on_air')->confidence)->toBe(0.4);
    });

    it('accepts booleans for noul', function () {
        TypeSafe::fake(['insult' => true]);

        expect(askEverything()->noul('insult')->probability)->toBe(1.0);

        TypeSafe::fake(['insult' => false]);

        expect(askEverything()->noul('insult')->probability)->toBe(0.0);
    });

    it('accepts ready-made answers', function () {
        $answer = new ChoiceAnswer('request', ['complaint' => 0.1, 'request' => 0.9], 0.9);
        TypeSafe::fake(['intent' => $answer]);

        expect(askEverything()->choice('intent'))->toBe($answer);
    });

    it('accepts a closure that sees the question and the request', function () {
        TypeSafe::fake([
            'insult' => fn (Question $question, DecisionRequest $request) => str_contains(json_encode($request->state), 'idiot') ? 0.99 : 0.01,
        ]);

        expect(askEverything('you idiot')->noul('insult')->probability)->toBe(0.99)
            ->and(askEverything('lovely day')->noul('insult')->probability)->toBe(0.01);
    });

    it('falls back to the first option, level 0 and probability 0 for unscripted questions', function () {
        TypeSafe::fake();

        $result = askEverything();

        expect($result->choice('intent')->value)->toBe('complaint')
            ->and($result->score('on_air')->value)->toBe(0.0)
            ->and($result->noul('insult')->probability)->toBe(0.0);
    });

    it('can script more answers later, and usage', function () {
        $fake = TypeSafe::fake(['insult' => 0.1])->answer(['insult' => 0.7])->usage(2000, 5);

        $result = askEverything();

        expect($result->noul('insult')->probability)->toBe(0.7)
            ->and($result->usage->inputTokens)->toBe(2000)
            ->and($result->usage->outputTokens)->toBe(5)
            ->and($fake)->toBeInstanceOf(FakeClient::class);
    });

    it('uses the configured model as its default and honours ->model()', function () {
        config(['typesafe.model' => 'jev-1.13.0']);
        app()->forgetInstance(Client::class);
        TypeSafe::clearResolvedInstances();

        $fake = TypeSafe::fake();

        expect(askEverything()->model)->toBe('jev-1.13.0')
            ->and($fake->defaultModel())->toBe('jev-1.13.0')
            ->and(TypeSafe::state('x')->ask('a', Noul::make('A?'))->model('jev-preview')->get()->model)->toBe('jev-preview');
    });

    it('never touches the network', function () {
        Http::fake();
        TypeSafe::fake();

        askEverything();

        Http::assertNothingSent();
    });

    it('validates questions like the real client', function () {
        TypeSafe::fake();

        TypeSafe::state('x')->ask('a', Choice::make('Pick')->options(['only' => 'one']))->get();
    })->throws(InvalidQuestionException::class, 'at least 2 options');

    it('rejects scripted answers that could never come back from the API', function (mixed $answer, string $message) {
        TypeSafe::fake(['answer' => $answer]);
        $question = match (true) {
            is_string($answer) || $answer instanceof BackedEnum || (is_array($answer) && isset($answer['choice'])) => Choice::make('Pick')->options(['a' => 'A', 'b' => 'B']),
            is_float($answer) || is_int($answer) && $answer > 1 || is_array($answer) => Score::make('Level')->levels(['x', 'y', 'z']),
            default => Noul::make('Yes?'),
        };

        expect(fn () => TypeSafe::state('x')->ask('answer', $question)->get())->toThrow(InvalidArgumentException::class, $message);
    })->with([
        'unknown option' => ['zzz', "is 'zzz', but the question only offers: a, b"],
        'score above the top level' => [5, 'levels only go from 0 to 2'],
        'score below zero' => [-1.0, 'levels only go from 0 to 2'],
        'non-numeric score' => [['score' => 'high'], 'must be a number'],
        'bad confidence' => [['score' => 1, 'confidence' => 'sure'], 'confidence must be a number'],
    ]);

    it('rejects scripted noul answers outside 0–1', function () {
        TypeSafe::fake(['insult' => 1.5]);

        askEverything();
    })->throws(InvalidArgumentException::class, 'must be between 0 and 1');

    it('rejects a scripted noul answer that is not a number', function () {
        TypeSafe::fake(['insult' => 'yes']);

        askEverything();
    })->throws(InvalidArgumentException::class, 'probability or a bool');

    it('rejects a scripted choice that is not an option name', function () {
        TypeSafe::fake(['intent' => 1.5]);

        askEverything();
    })->throws(InvalidArgumentException::class, 'option name or backed enum case');

    it('rejects a scripted score that is not a number', function () {
        TypeSafe::fake(['on_air' => 'good']);

        askEverything();
    })->throws(InvalidArgumentException::class, 'must be a number');
});

describe('assertions', function () {
    it('asserts that a matching request was sent', function () {
        TypeSafe::fake();

        askEverything(['message' => 'The bus never came.']);

        TypeSafe::assertAsked();
        TypeSafe::assertAsked(fn (DecisionRequest $request) => $request->state === ['message' => 'The bus never came.']
            && $request->has('intent')
            && $request->question('on_air') instanceof Score
            && $request->model === 'jev-latest');
    });

    it('counts matching requests', function () {
        TypeSafe::fake();

        askEverything('one');
        askEverything('two');
        askEverything('two');

        TypeSafe::assertAsked(fn (DecisionRequest $request) => $request->state === 'two', times: 2);
        TypeSafe::assertAsked(fn (DecisionRequest $request) => $request->state === 'one', times: 1);
        TypeSafe::assertAsked(fn (DecisionRequest $request) => $request->state === 'three', times: 0);
        TypeSafe::assertAskedCount(3);
    });

    it('fails when nothing matches', function () {
        TypeSafe::fake();

        askEverything('one');

        TypeSafe::assertAsked(fn (DecisionRequest $request) => $request->state === 'other');
    })->throws(AssertionFailedError::class, 'The expected TypeSafe request was not sent');

    it('fails when the count is off', function () {
        TypeSafe::fake();

        askEverything('one');

        TypeSafe::assertAsked(fn (DecisionRequest $request) => true, times: 2);
    })->throws(AssertionFailedError::class, 'Expected 2 matching TypeSafe request(s), found 1');

    it('fails assertAsked when nothing at all was asked', function () {
        TypeSafe::fake();

        TypeSafe::assertAsked();
    })->throws(AssertionFailedError::class);

    it('asserts that nothing was asked', function () {
        TypeSafe::fake();

        TypeSafe::assertNothingAsked();
        TypeSafe::assertAskedCount(0);
    });

    it('fails assertNothingAsked when something was asked', function () {
        TypeSafe::fake();

        askEverything();

        TypeSafe::assertNothingAsked();
    })->throws(AssertionFailedError::class, 'Unexpected TypeSafe requests were sent: 1');

    it('fails assertAskedCount when the count is off', function () {
        TypeSafe::fake();

        askEverything();

        TypeSafe::assertAskedCount(2);
    })->throws(AssertionFailedError::class, 'Expected 2 TypeSafe request(s), found 1');

    it('exposes the recorded requests', function () {
        $fake = TypeSafe::fake();

        askEverything('one');

        expect($fake->recorded())->toHaveCount(1)->and($fake->recorded()[0]->state)->toBe('one');
    });

    it('does not record requests that failed validation', function () {
        $fake = TypeSafe::fake();

        try {
            TypeSafe::state('x')->get();
        } catch (InvalidQuestionException) {
        }

        expect($fake->recorded())->toBe([]);
    });

    it('tells you to fake first when asserting against the real client', function () {
        TypeSafe::assertAsked();
    })->throws(LogicException::class, 'Call TypeSafe::fake() before making assertions.');

    it('can be faked twice, the second fake replacing the first', function () {
        TypeSafe::fake(['insult' => 0.9]);
        askEverything();
        TypeSafe::fake(['insult' => 0.1]);

        expect(askEverything()->noul('insult')->probability)->toBe(0.1);
        TypeSafe::assertAskedCount(1);
    });

    it('is also used by code that resolves the client from the container', function () {
        TypeSafe::fake(['insult' => 0.3]);

        $result = app(Client::class)
            ->state('x')->ask('insult', Noul::make('Insult?'))->get();

        expect($result->noul('insult')->probability)->toBe(0.3);
    });
});
