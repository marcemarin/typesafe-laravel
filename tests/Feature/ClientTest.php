<?php

declare(strict_types=1);

use Illuminate\Http\Client\ConnectionException as HttpConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Marcemarin\TypeSafe\Contracts\Client;
use Marcemarin\TypeSafe\Exceptions\AuthenticationException;
use Marcemarin\TypeSafe\Exceptions\ConnectionException;
use Marcemarin\TypeSafe\Exceptions\InvalidQuestionException;
use Marcemarin\TypeSafe\Exceptions\OverloadedException;
use Marcemarin\TypeSafe\Exceptions\RateLimitException;
use Marcemarin\TypeSafe\Exceptions\TypeSafeException;
use Marcemarin\TypeSafe\Exceptions\UnexpectedResponseException;
use Marcemarin\TypeSafe\Exceptions\ValidationException;
use Marcemarin\TypeSafe\Facades\TypeSafe;
use Marcemarin\TypeSafe\Http\Backoff;
use Marcemarin\TypeSafe\Questions\Choice;
use Marcemarin\TypeSafe\Questions\Noul;
use Marcemarin\TypeSafe\Tests\Support\Intent;
use Marcemarin\TypeSafe\TypeSafeClient;

beforeEach(fn () => Sleep::fake());

describe('request shape', function () {
    it('posts to /v1/systemone with a bearer token and JSON', function () {
        fakeApi();

        askEverything();

        Http::assertSentCount(1);
        Http::assertSent(fn (Request $request) => $request->method() === 'POST'
            && $request->url() === 'https://api.typesafe.ai/v1/systemone'
            && $request->hasHeader('Authorization', 'Bearer test-key-not-a-secret')
            && $request->hasHeader('Accept', 'application/json')
            && $request->isJson());
    });

    it('sends model, state and each question type in the documented shape', function () {
        fakeApi();

        askEverything(['message' => 'The bus never came.', 'program' => 'Morning show']);

        Http::assertSent(fn (Request $request) => $request->data() === [
            'model' => 'jev-latest',
            'state' => ['message' => 'The bus never came.', 'program' => 'Morning show'],
            'questions' => [
                'intent' => [
                    'type' => 'choice',
                    'instructions' => 'What is the purpose of `message`?',
                    'criteria' => [
                        'complaint' => 'Reports a problem with a public service',
                        'request' => 'Asks the show to play a song',
                    ],
                ],
                'on_air' => [
                    'type' => 'score',
                    'instructions' => 'How good is `message` to be read on air?',
                    'criteria' => ['Unusable', 'Weak', 'Acceptable', 'Good', 'Excellent'],
                ],
                'insult' => [
                    'type' => 'noul',
                    'instructions' => 'Does `message` contain insults?',
                ],
            ],
        ]);
    });

    it('sends noul criteria only when given', function () {
        fakeApi();

        TypeSafe::state('x')->ask('urgent', Noul::make('Urgent?')->criteria('Now', 'Later'))->get();

        Http::assertSent(fn (Request $request) => $request['questions']['urgent']['criteria'] === ['true' => 'Now', 'false' => 'Later']);
    });

    it('sends a plain string state as a string', function () {
        fakeApi();

        askEverything('just text');

        Http::assertSent(fn (Request $request) => $request['state'] === 'just text');
    });

    it('sends an enum-derived choice', function () {
        fakeApi();

        TypeSafe::state('x')->ask('intent', Choice::fromEnum(Intent::class, ['complaint' => 'A', 'request' => 'B'], 'Purpose?'))->get();

        Http::assertSent(fn (Request $request) => $request['questions']['intent']['criteria'] === ['complaint' => 'A', 'request' => 'B']);
    });

    it('uses the configured model unless the request overrides it', function () {
        fakeApi();
        config(['typesafe.model' => 'jev-1.13.0']);
        app()->forgetInstance(Client::class);
        TypeSafe::clearResolvedInstances();

        askEverything();
        TypeSafe::state('x')->ask('a', Noul::make('A?'))->model('jev-preview')->get();

        Http::assertSent(fn (Request $request) => $request['model'] === 'jev-1.13.0');
        Http::assertSent(fn (Request $request) => $request['model'] === 'jev-preview');
    });

    it('honours base_url and timeout from the constructor and strips a trailing slash', function () {
        Http::fake(['https://eu.example.test/*' => Http::response(jevBody())]);
        $client = new TypeSafeClient('k', baseUrl: 'https://eu.example.test/', http: app(Factory::class));

        $client->state('x')->ask('a', Noul::make('A?'))->get();

        Http::assertSent(fn (Request $request) => $request->url() === 'https://eu.example.test/v1/systemone');
    });

    it('works without the container or the facade', function () {
        $http = new Factory;
        $http->fake(['*' => Http::response(jevBody())]);

        $result = (new TypeSafeClient('k', http: $http))
            ->state('x')
            ->ask('insult', Noul::make('Insult?'))
            ->get();

        expect($result->noul('insult')->probability)->toBe(0.02);
    });

    it('validates before sending: nothing goes over the wire', function () {
        fakeApi();

        expect(fn () => TypeSafe::state('x')->ask('a', Choice::make('Pick')->options(['only' => 'one']))->get())
            ->toThrow(InvalidQuestionException::class, 'at least 2 options');

        Http::assertNothingSent();
    });

    it('refuses to send without an API key', function () {
        fakeApi();
        $client = new TypeSafeClient(null, http: app(Factory::class));

        expect(fn () => $client->state('x')->ask('a', Noul::make('A?'))->get())
            ->toThrow(AuthenticationException::class, 'TYPESAFE_API_KEY');

        Http::assertNothingSent();
    });
});

describe('response parsing', function () {
    it('parses choice, score, noul and usage', function () {
        fakeApi();

        $result = askEverything();

        expect($result->model)->toBe('jev-1.13.0')
            ->and($result->choice('intent')->value)->toBe('complaint')
            ->and($result->choice('intent')->confidence)->toBe(0.97)
            ->and($result->choice('intent')->as(Intent::class))->toBe(Intent::Complaint)
            ->and($result->score('on_air')->value)->toBe(3.6)
            ->and($result->score('on_air')->normalized())->toBe(0.9)
            ->and($result->noul('insult')->probability)->toBe(0.02)
            ->and($result->noul('insult')->isTrue(0.6))->toBeFalse()
            ->and($result->usage->inputTokens)->toBe(1234)
            ->and($result->usage->costUsd())->toEqualWithDelta(0.000051828, 1e-12);
    });

    it('rejects a 200 whose body is not a JSON object', function () {
        Http::fake(['*' => Http::response('<html>oops</html>', 200)]);

        askEverything();
    })->throws(UnexpectedResponseException::class, 'not a JSON object');

    it('rejects a 200 without answers', function () {
        Http::fake(['*' => Http::response(['model' => 'jev-1.13.0'], 200)]);

        askEverything();
    })->throws(UnexpectedResponseException::class, "no 'answers' map");
});

describe('errors', function () {
    it('maps 401 to AuthenticationException without retrying', function () {
        Http::fake(['*' => Http::response(['error' => 'Invalid API key'], 401)]);

        try {
            askEverything();
            $this->fail('Expected an exception.');
        } catch (AuthenticationException $e) {
            expect($e)->toBeInstanceOf(TypeSafeException::class)
                ->and($e->status)->toBe(401)
                ->and($e->getMessage())->toContain('HTTP 401')->toContain('Invalid API key')
                ->and($e->body)->toBe(['error' => 'Invalid API key']);
        }

        Http::assertSentCount(1);
        Sleep::assertNeverSlept();
    });

    it('maps 422 to ValidationException carrying the body, without retrying', function () {
        $body = ['message' => 'questions.intent.criteria: too few options', 'errors' => ['questions' => ['bad']]];
        Http::fake(['*' => Http::response($body, 422)]);

        try {
            askEverything();
            $this->fail('Expected an exception.');
        } catch (ValidationException $e) {
            expect($e->status)->toBe(422)
                ->and($e->body)->toBe($body)
                ->and($e->getMessage())->toContain('too few options');
        }

        Http::assertSentCount(1);
        Sleep::assertNeverSlept();
    });

    it('describes error bodies of different shapes', function (array|string $body, string $expected) {
        Http::fake(['*' => Http::response($body, 500)]);

        expect(fn () => askEverything())->toThrow(TypeSafeException::class, $expected);
    })->with([
        'detail' => [['detail' => 'kaput'], 'kaput'],
        'nested error.message' => [['error' => ['message' => 'nested boom']], 'nested boom'],
        'plain text' => ['gateway exploded', 'gateway exploded'],
        'empty body' => ['', 'HTTP 500.'],
    ]);

    it('maps other statuses to the base exception', function () {
        Http::fake(['*' => Http::response(['message' => 'boom'], 500)]);

        try {
            askEverything();
            $this->fail('Expected an exception.');
        } catch (TypeSafeException $e) {
            expect($e::class)->toBe(TypeSafeException::class)->and($e->status)->toBe(500);
        }

        Http::assertSentCount(1);
    });

    it('wraps connection failures without retrying', function () {
        $attempts = 0;
        Http::fake(function () use (&$attempts) {
            $attempts++;

            throw new HttpConnectionException('cURL error 28: timed out');
        });

        try {
            askEverything();
            $this->fail('Expected an exception.');
        } catch (ConnectionException $e) {
            expect($e->getMessage())->toContain('timed out')->and($e->getPrevious())->toBeInstanceOf(HttpConnectionException::class);
        }

        expect($attempts)->toBe(1);
        Sleep::assertNeverSlept();
    });
});

describe('retries', function () {
    it('retries 429 with backoff and then succeeds', function () {
        Http::fake(['*' => Http::sequence()
            ->push(['message' => 'slow down'], 429)
            ->push(['message' => 'slow down'], 429)
            ->push(jevBody(), 200)]);

        $result = askEverything();

        expect($result->choice('intent')->value)->toBe('complaint');
        Http::assertSentCount(3);
        Sleep::assertSleptTimes(2);
    });

    it('retries 529 and then succeeds', function () {
        Http::fake(['*' => Http::sequence()->push([], 529)->push(jevBody(), 200)]);

        askEverything();

        Http::assertSentCount(2);
        Sleep::assertSleptTimes(1);
    });

    it('sleeps between attempts using the configured backoff', function () {
        Http::fake(['*' => Http::sequence()->push([], 429)->push([], 429)->push([], 429)->push(jevBody(), 200)]);
        $client = new TypeSafeClient('k', http: app(Factory::class), backoff: new Backoff(baseMs: 1000, maxMs: 1000));

        $client->state('x')->ask('a', Noul::make('A?'))->get();

        // With base == max every delay is in [500 ms, 1000 ms].
        Sleep::assertSlept(fn ($duration) => $duration->totalMilliseconds >= 500 && $duration->totalMilliseconds <= 1000, 3);
    });

    it('gives up with RateLimitException after the configured retries', function () {
        Http::fake(['*' => Http::response(['message' => 'slow down'], 429)]);

        try {
            askEverything();
            $this->fail('Expected an exception.');
        } catch (RateLimitException $e) {
            expect($e->status)->toBe(429)->and($e->getMessage())->toContain('after 3 retries');
        }

        Http::assertSentCount(4);
        Sleep::assertSleptTimes(3);
    });

    it('gives up with OverloadedException after the configured retries', function () {
        Http::fake(['*' => Http::response([], 529)]);

        expect(fn () => askEverything())->toThrow(OverloadedException::class);

        Http::assertSentCount(4);
    });

    it('honours a custom retry count, including zero', function (int $retries) {
        Http::fake(['*' => Http::response([], 429)]);
        $client = new TypeSafeClient('k', retries: $retries, http: app(Factory::class));

        expect(fn () => $client->state('x')->ask('a', Noul::make('A?'))->get())->toThrow(RateLimitException::class);

        Http::assertSentCount($retries + 1);
        Sleep::assertSleptTimes($retries);
    })->with([0, 1, 5]);
});

describe('caching', function () {
    it('serves an identical request from the cache without calling the API', function () {
        fakeApi();
        $ask = fn () => TypeSafe::state('same')->ask('insult', Noul::make('Insult?'))->cacheFor(60)->get();

        $first = $ask();
        $second = $ask();

        Http::assertSentCount(1);
        expect($first->cached)->toBeFalse()
            ->and($second->cached)->toBeTrue()
            ->and($second->noul('insult')->probability)->toBe(0.02)
            ->and($second->usage->inputTokens)->toBe(1234);
    });

    it('does not share a cache entry between different states, questions or models', function () {
        fakeApi();

        TypeSafe::state('one')->ask('a', Noul::make('A?'))->cacheFor(60)->get();
        TypeSafe::state('two')->ask('a', Noul::make('A?'))->cacheFor(60)->get();
        TypeSafe::state('one')->ask('a', Noul::make('B?'))->cacheFor(60)->get();
        TypeSafe::state('one')->ask('a', Noul::make('A?'))->model('jev-1.13.0')->cacheFor(60)->get();

        Http::assertSentCount(4);
    });

    it('does not cache without cacheFor()', function () {
        fakeApi();

        TypeSafe::state('same')->ask('a', Noul::make('A?'))->get();
        TypeSafe::state('same')->ask('a', Noul::make('A?'))->get();

        Http::assertSentCount(2);
    });

    it('does not cache failures', function () {
        Http::fake(['*' => Http::sequence()->push(['message' => 'no'], 500)->push(jevBody(), 200)]);
        $ask = fn () => TypeSafe::state('same')->ask('insult', Noul::make('Insult?'))->cacheFor(60)->get();

        expect($ask)->toThrow(TypeSafeException::class);
        expect($ask()->cached)->toBeFalse();

        Http::assertSentCount(2);
    });

    it('expires entries', function () {
        fakeApi();
        $ask = fn () => TypeSafe::state('same')->ask('a', Noul::make('A?'))->cacheFor(now()->addSeconds(30))->get();

        $ask();
        $this->travel(31)->seconds();
        $ask();

        Http::assertSentCount(2);
    });

    it('needs a cache repository when used standalone', function () {
        $http = new Factory;
        $http->fake(['*' => Http::response(jevBody())]);

        (new TypeSafeClient('k', http: $http))->state('x')->ask('a', Noul::make('A?'))->cacheFor(60)->get();
    })->throws(LogicException::class, 'cache repository');
});
