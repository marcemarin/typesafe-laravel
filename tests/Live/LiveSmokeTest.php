<?php

declare(strict_types=1);

/*
 * Talks to the real TypeSafe API. Skipped unless TYPESAFE_LIVE_API_KEY is set, and never run in CI.
 *
 *   TYPESAFE_LIVE_API_KEY=... vendor/bin/pest tests/Live
 *
 * It spends a few hundred tokens (a fraction of a cent).
 */

use Marcemarin\TypeSafe\Exceptions\AuthenticationException;
use Marcemarin\TypeSafe\Questions\Choice;
use Marcemarin\TypeSafe\Questions\Noul;
use Marcemarin\TypeSafe\Questions\Score;
use Marcemarin\TypeSafe\Tests\Support\Intent;
use Marcemarin\TypeSafe\TypeSafeClient;

$liveKey = getenv('TYPESAFE_LIVE_API_KEY') ?: null;

it('gets typed answers from the real API', function () use ($liveKey) {
    $client = new TypeSafeClient($liveKey);

    $started = hrtime(true);
    $result = $client
        ->state(['message' => 'The 42 bus has not come by my street in three days, please say something on air', 'program' => 'Morning show'])
        ->ask('intent', Choice::fromEnum(Intent::class, [
            'complaint' => 'Reports a problem with a public service',
            'request' => 'Asks the show to play a song',
        ], 'What is the purpose of `message`?'))
        ->ask('on_air', Score::make('How good is `message` to be read on air?')->levels(['Unusable', 'Weak', 'Acceptable', 'Good', 'Excellent']))
        ->ask('insult', Noul::make('Does `message` contain insults?'))
        ->get();
    $milliseconds = (int) ((hrtime(true) - $started) / 1e6);

    expect($result->model)->toStartWith('jev-')
        ->and($result->choice('intent')->as(Intent::class))->toBe(Intent::Complaint)
        ->and($result->choice('intent')->confidence)->toBeBetween(0.0, 1.0)
        ->and($result->choice('intent')->probabilities)->toHaveKeys(['complaint', 'request'])
        ->and($result->score('on_air')->value)->toBeBetween(0.0, 4.0)
        ->and($result->score('on_air')->legend)->toHaveCount(5)
        ->and($result->score('on_air')->normalized())->toBeBetween(0.0, 1.0)
        ->and($result->noul('insult')->probability)->toBeBetween(0.0, 1.0)
        ->and($result->noul('insult')->isTrue(0.6))->toBeFalse()
        ->and($result->usage->inputTokens)->toBeGreaterThan(0);

    fwrite(STDERR, sprintf(
        "\n[live] %s answered in %d ms; %d input / %d output tokens; est. cost $%.8f\n",
        $result->model, $milliseconds, $result->usage->inputTokens, $result->usage->outputTokens, $result->usage->costUsd(),
    ));
})->skip($liveKey === null, 'Set TYPESAFE_LIVE_API_KEY to run against the real API.');

it('maps a rejected key to AuthenticationException', function () {
    (new TypeSafeClient('invalid-key-for-testing'))
        ->state('hello')
        ->ask('greeting', Noul::make('Is `state` a greeting?'))
        ->get();
})->throws(AuthenticationException::class)
    ->skip(getenv('TYPESAFE_LIVE_API_KEY') === false, 'Set TYPESAFE_LIVE_API_KEY to run against the real API.');
