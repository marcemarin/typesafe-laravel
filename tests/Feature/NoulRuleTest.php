<?php

declare(strict_types=1);

use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Http\Client\ConnectionException as HttpConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Sleep;
use Marcemarin\TypeSafe\Contracts\Client;
use Marcemarin\TypeSafe\Exceptions\AuthenticationException;
use Marcemarin\TypeSafe\Exceptions\UnexpectedResponseException;
use Marcemarin\TypeSafe\Exceptions\ValidationException;
use Marcemarin\TypeSafe\Facades\TypeSafe;
use Marcemarin\TypeSafe\Rules\NoulRule;

beforeEach(function () {
    Sleep::fake();
    Http::preventStrayRequests();
});

/** A response as the API would return it for a single question with id `rule`. */
function ruleResponse(float $probability): array
{
    return [
        'model' => 'jev-1.13.0',
        'answers' => ['rule' => ['type' => 'noul', 'noul' => $probability]],
        'usage' => ['input_tokens' => 20, 'output_tokens' => 0],
    ];
}

function validateWith(NoulRule $rule, mixed $value = 'some text'): Illuminate\Validation\Validator
{
    $validator = Validator::make(['bio' => $value], ['bio' => [$rule]]);
    $validator->passes();

    return $validator;
}

describe('with the real client', function () {
    it('asks TypeSafe a single noul question about the value', function () {
        Http::fake(['*' => Http::response(ruleResponse(0.01))]);

        validateWith(new NoulRule('Does this text contain personal data?', max: 0.4), 'Call me maybe');

        Http::assertSent(fn (Request $request) => $request['state'] === 'Call me maybe'
            && $request['questions'] === ['rule' => ['type' => 'noul', 'instructions' => 'Does this text contain personal data?']]);
    });

    it('can use another model', function () {
        Http::fake(['*' => Http::response(ruleResponse(0.01))]);

        validateWith(new NoulRule('Spam?', max: 0.4, model: 'jev-1.13.0'));

        Http::assertSent(fn (Request $request) => $request['model'] === 'jev-1.13.0');
    });

    it('passes arrays through as structured state', function () {
        Http::fake(['*' => Http::response(ruleResponse(0.01))]);

        validateWith(new NoulRule('Spam?', max: 0.4), ['title' => 'Hi']);

        Http::assertSent(fn (Request $request) => $request['state'] === ['title' => 'Hi']);
    });

    it('turns numbers into text', function () {
        Http::fake(['*' => Http::response(ruleResponse(0.01))]);

        validateWith(new NoulRule('Is this a phone number?', max: 0.4), 12345);

        Http::assertSent(fn (Request $request) => $request['state'] === '12345');
    });
});

describe('thresholds', function () {
    it('passes when the probability is at or below max', function (float $probability, bool $passes) {
        TypeSafe::fake(['rule' => $probability]);

        expect(validateWith(new NoulRule('Personal data?', max: 0.4))->passes())->toBe($passes);
    })->with([
        'well below' => [0.02, true],
        'at the limit' => [0.4, true],
        'just above' => [0.41, false],
        'certain' => [1.0, false],
    ]);

    it('passes when the probability is at or above min', function (float $probability, bool $passes) {
        TypeSafe::fake(['rule' => $probability]);

        expect(validateWith(new NoulRule('Is this a real address?', min: 0.7))->passes())->toBe($passes);
    })->with([
        'certain' => [1.0, true],
        'at the limit' => [0.7, true],
        'just below' => [0.69, false],
        'unlikely' => [0.05, false],
    ]);

    it('supports a band with both min and max', function (float $probability, bool $passes) {
        TypeSafe::fake(['rule' => $probability]);

        expect(validateWith(new NoulRule('Borderline?', max: 0.8, min: 0.2))->passes())->toBe($passes);
    })->with([[0.1, false], [0.2, true], [0.5, true], [0.8, true], [0.9, false]]);

    it('defaults to max 0.5 when no bound is given', function (float $probability, bool $passes) {
        TypeSafe::fake(['rule' => $probability]);

        expect(validateWith(new NoulRule('Spam?'))->passes())->toBe($passes);
    })->with([[0.5, true], [0.51, false], [0.1, true]]);

    it('rejects impossible bounds', function (Closure $make) {
        $make();
    })->with([
        'max above 1' => [fn () => new NoulRule('x', max: 1.2)],
        'min below 0' => [fn () => new NoulRule('x', min: -0.1)],
        'min above max' => [fn () => new NoulRule('x', max: 0.3, min: 0.6)],
    ])->throws(InvalidArgumentException::class);
});

describe('failure message', function () {
    it('uses the translatable "flagged" message with the attribute name', function () {
        TypeSafe::fake(['rule' => 0.9]);

        expect(validateWith(new NoulRule('Spam?', max: 0.4))->errors()->first('bio'))
            ->toBe('The bio field did not pass the content check.');
    });

    it('can be translated', function () {
        app('translator')->addLines(['validation.flagged' => 'El campo :attribute no pasó la revisión.'], 'es', 'typesafe');
        app()->setLocale('es');
        TypeSafe::fake(['rule' => 0.9]);

        expect(validateWith(new NoulRule('Spam?', max: 0.4))->errors()->first('bio'))
            ->toBe('El campo bio no pasó la revisión.');
    });

    it('can be overridden in the app lang directory', function () {
        app('translator')->addLines(['validation.flagged' => 'Nope: :attribute.'], 'en', 'typesafe');
        TypeSafe::fake(['rule' => 0.9]);

        expect(validateWith(new NoulRule('Spam?', max: 0.4))->errors()->first('bio'))->toBe('Nope: bio.');
    });

    it('rejects values that are not text', function () {
        TypeSafe::fake();

        $validator = validateWith(new NoulRule('Spam?'), new stdClass);

        expect($validator->errors()->first('bio'))->toBe('The bio field must be text.');
        TypeSafe::assertNothingAsked();
    });
});

describe('when TypeSafe is unreachable', function () {
    it('fails closed by default, with its own message', function (Closure $response) {
        Http::fake($response);

        $validator = validateWith(new NoulRule('Spam?', max: 0.4));

        expect($validator->passes())->toBeFalse()
            ->and($validator->errors()->first('bio'))->toBe('The bio field could not be checked right now. Please try again.');
    })->with([
        'timeout' => [fn () => throw new HttpConnectionException('timed out')],
        'rate limited' => [fn () => Http::response([], 429)],
        'overloaded' => [fn () => Http::response([], 529)],
        'server error' => [fn () => Http::response(['message' => 'boom'], 503)],
    ]);

    it('fails open when the rule says so', function (Closure $response) {
        Http::fake($response);

        expect(validateWith(new NoulRule('Spam?', max: 0.4, failOpen: true))->passes())->toBeTrue();
    })->with([
        'timeout' => [fn () => throw new HttpConnectionException('timed out')],
        'rate limited' => [fn () => Http::response([], 429)],
        'overloaded' => [fn () => Http::response([], 529)],
        'server error' => [fn () => Http::response([], 500)],
    ]);

    it('follows the typesafe.fail_open config when the rule does not decide', function () {
        Http::fake(['*' => Http::response([], 503)]);
        config(['typesafe.fail_open' => true]);

        expect(validateWith(new NoulRule('Spam?', max: 0.4))->passes())->toBeTrue();

        config(['typesafe.fail_open' => false]);

        expect(validateWith(new NoulRule('Spam?', max: 0.4))->passes())->toBeFalse();
    });

    it('lets an explicit failOpen: false override a fail-open config', function () {
        Http::fake(['*' => Http::response([], 503)]);
        config(['typesafe.fail_open' => true]);

        expect(validateWith(new NoulRule('Spam?', max: 0.4, failOpen: false))->passes())->toBeFalse();
    });

    it('reports the swallowed exception', function () {
        Http::fake(['*' => Http::response(['message' => 'boom'], 503)]);
        $reported = [];
        app(ExceptionHandler::class)->reportable(function (Throwable $e) use (&$reported) {
            $reported[] = $e;

            return false;
        });

        validateWith(new NoulRule('Spam?', max: 0.4, failOpen: true));

        expect($reported)->toHaveCount(1)->and($reported[0]->getMessage())->toContain('HTTP 503');
    });
});

describe('errors that are bugs, not outages, stay loud even when failing open', function () {
    it('rethrows authentication errors', function () {
        Http::fake(['*' => Http::response(['error' => 'bad key'], 401)]);

        validateWith(new NoulRule('Spam?', max: 0.4, failOpen: true));
    })->throws(AuthenticationException::class);

    it('rethrows request validation errors', function () {
        Http::fake(['*' => Http::response(['message' => 'bad'], 422)]);

        validateWith(new NoulRule('Spam?', max: 0.4, failOpen: true));
    })->throws(ValidationException::class);

    it('rethrows malformed responses', function () {
        Http::fake(['*' => Http::response(['model' => 'x'])]);

        validateWith(new NoulRule('Spam?', max: 0.4, failOpen: true));
    })->throws(UnexpectedResponseException::class);

    it('rethrows a missing API key', function () {
        config(['typesafe.api_key' => null]);
        app()->forgetInstance(Client::class);
        TypeSafe::clearResolvedInstances();

        validateWith(new NoulRule('Spam?', max: 0.4, failOpen: true));
    })->throws(AuthenticationException::class, 'TYPESAFE_API_KEY');
});

it('composes with other rules in a normal validator', function () {
    TypeSafe::fake(['rule' => 0.9]);

    $validator = Validator::make(
        ['bio' => 'x'],
        ['bio' => ['required', 'string', 'max:500', new NoulRule('Spam?', max: 0.4)]],
    );

    expect($validator->fails())->toBeTrue()
        ->and($validator->errors()->keys())->toBe(['bio']);
});

it('is not called for empty optional values', function () {
    TypeSafe::fake();

    $validator = Validator::make(['bio' => null], ['bio' => ['nullable', new NoulRule('Spam?')]]);

    expect($validator->passes())->toBeTrue();
    TypeSafe::assertNothingAsked();
});
