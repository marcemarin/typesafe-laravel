<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use Marcemarin\TypeSafe\Facades\TypeSafe;
use Marcemarin\TypeSafe\Questions\Choice;
use Marcemarin\TypeSafe\Questions\Noul;
use Marcemarin\TypeSafe\Questions\Score;
use Marcemarin\TypeSafe\Result;
use Marcemarin\TypeSafe\Tests\TestCase;

uses(TestCase::class)->in('Feature', 'Unit', 'Live');

/**
 * A response body shaped like the one documented at https://docs.typesafe.ai/api.md.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function jevBody(array $overrides = []): array
{
    return array_replace([
        'model' => 'jev-1.13.0',
        'answers' => [
            'intent' => [
                'type' => 'choice',
                'choice' => 'complaint',
                'probabilities' => ['complaint' => 0.97, 'request' => 0.03],
                'confidence' => 0.97,
            ],
            'on_air' => [
                'type' => 'score',
                'score' => 3.6,
                'legend' => ['0' => 'Unusable', '1' => 'Weak', '2' => 'Acceptable', '3' => 'Good', '4' => 'Excellent'],
                'probabilities' => ['0' => 0.0, '1' => 0.0, '2' => 0.1, '3' => 0.2, '4' => 0.7],
                'confidence' => 0.7,
            ],
            'insult' => ['type' => 'noul', 'noul' => 0.02],
        ],
        'usage' => ['input_tokens' => 1234, 'output_tokens' => 0],
    ], $overrides);
}

/** Send the standard three-question request through the facade. */
function askEverything(string|array $state = 'The bus never came.'): Result
{
    return TypeSafe::state($state)
        ->ask('intent', Choice::make('What is the purpose of `message`?')->options([
            'complaint' => 'Reports a problem with a public service',
            'request' => 'Asks the show to play a song',
        ]))
        ->ask('on_air', Score::make('How good is `message` to be read on air?')->levels([
            'Unusable', 'Weak', 'Acceptable', 'Good', 'Excellent',
        ]))
        ->ask('insult', Noul::make('Does `message` contain insults?'))
        ->get();
}

function fakeApi(array|int $body = [], int $status = 200): void
{
    Http::fake(['api.typesafe.ai/*' => Http::response(is_int($body) ? [] : ($status === 200 ? jevBody($body) : $body), is_int($body) ? $body : $status)]);
}
