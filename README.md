# TypeSafe for Laravel

[![Latest Version on Packagist](https://img.shields.io/packagist/v/marcemarin/typesafe-laravel.svg)](https://packagist.org/packages/marcemarin/typesafe-laravel)
[![CI](https://github.com/marcemarin/typesafe-laravel/actions/workflows/ci.yml/badge.svg)](https://github.com/marcemarin/typesafe-laravel/actions/workflows/ci.yml)
[![PHPStan level 8](https://img.shields.io/badge/PHPStan-level%208-brightgreen.svg)](phpstan.neon)
[![License](https://img.shields.io/packagist/l/marcemarin/typesafe-laravel.svg)](LICENSE)

A typed PHP / Laravel client for [TypeSafe AI](https://docs.typesafe.ai/introduction)'s **System One** decision model (Jev): ask a
model *choice*, *score* and *yes/no* questions about some text or data, and get calibrated probabilities back
instead of prose.

> **Unofficial.** This is a community SDK written by [Marcelo Marin](https://github.com/marcemarin). It is not made,
> endorsed or supported by TypeSafe AI, who only ship official Python and JavaScript SDKs. Everything here is built on
> their public docs (<https://docs.typesafe.ai/introduction>); where the docs and this README disagree, the docs win.

```php
$result = TypeSafe::state(['message' => $text, 'program' => 'Morning show'])
    ->ask('intent', Choice::make('What is the purpose of `message`?')->options([
        'complaint' => 'Reports a problem with a public service',
        'request'   => 'Asks the show to play a song',
    ]))
    ->ask('on_air', Score::make('How good is `message` to be read on air?')->levels([
        'Unusable', 'Weak', 'Acceptable', 'Good', 'Excellent',
    ]))
    ->ask('insult', Noul::make('Does `message` contain insults?'))
    ->get();

$result->choice('intent')->value;          // 'complaint'
$result->score('on_air')->normalized();    // 0.0–1.0
$result->noul('insult')->isTrue(0.6);      // false
```

- [What is a decision model?](#what-is-a-decision-model)
- [Installation](#installation)
- [The 30-second example](#the-30-second-example)
- [Question types](#question-types) · [Enums](#backed-enums) · [Thresholds and uncertainty](#thresholds-and-uncertainty)
- [The validation rule](#the-validation-rule)
- [Testing](#testing)
- [Errors and retries](#errors-and-retries)
- [Caching](#caching) · [Cost](#cost) · [Using it without the facade](#using-it-without-the-facade)
- [When *not* to use this](#when-not-to-use-this)

## What is a decision model?

An LLM answers a question by *generating text*, which you then have to parse and hope is consistent. A decision model
like Jev answers by *choosing*: in TypeSafe's words, it "returns a probability distribution over your options or levels,
never a value outside them" ([primitives](https://docs.typesafe.ai/primitives.md)).
There is nothing to parse, and the probabilities tell you how sure it is.

That makes it a good fit for the boring, high-volume decisions that sit in front of the rest of your app:

- **Triage and routing**: which team or queue should this ticket go to?
- **Moderation**: is this message an insult, spam, or does it contain personal data?
- **Scoring**: how urgent, how angry, how good is this?
- **Gating**: only send the messages that pass a cheap check to a more expensive (LLM) step.

TypeSafe's guidance, which this SDK is built around: ask fast, focused judgments (the kind "a knowledgeable person
makes in a second"), put several questions about the same `state` in **one request** (they are evaluated in parallel,
so extra questions barely change the response time), and combine the answers in your own code rather than in a prompt.

In a live check of this package, a single request with three questions took about one second round trip and used a
few hundred input tokens.

## Installation

Requires PHP 8.2+ and Laravel 12 or 13 (Laravel 13 itself needs PHP 8.3+). Laravel 11 is not supported: it no longer
receives security fixes, and Composer refuses to install it because of unpatched advisories.

```bash
composer require marcemarin/typesafe-laravel
```

The service provider and the `TypeSafe` facade alias are auto-discovered. Add your key to `.env`
(get one from TypeSafe):

```dotenv
TYPESAFE_API_KEY=your-key-here
```

Optionally publish the config (and the validation messages, to translate them):

```bash
php artisan vendor:publish --tag=typesafe-config
php artisan vendor:publish --tag=typesafe-lang
```

| Key | Env | Default | |
|---|---|---|---|
| `api_key` | `TYPESAFE_API_KEY` | `null` | Sent as `Authorization: Bearer <key>`. |
| `model` | `TYPESAFE_MODEL` | `jev-latest` | `jev-latest`, `jev-preview`, or a pinned version such as `jev-1.13.0`. Pin one if answers must not change between deploys. |
| `base_url` | `TYPESAFE_BASE_URL` | `https://api.typesafe.ai` | |
| `timeout` | `TYPESAFE_TIMEOUT` | `20` | Seconds. |
| `retries` | `TYPESAFE_RETRIES` | `3` | Extra attempts, on HTTP 429 and 529 only. |
| `fail_open` | `TYPESAFE_FAIL_OPEN` | `false` | What [`NoulRule`](#the-validation-rule) does when the API is unreachable. |
| `cache_store` | `TYPESAFE_CACHE_STORE` | `null` | Cache store for [`cacheFor()`](#caching); `null` is your default store. |

## The 30-second example

```php
use Marcemarin\TypeSafe\Facades\TypeSafe;
use Marcemarin\TypeSafe\Questions\{Choice, Score, Noul};

$result = TypeSafe::state($ticket->body)
    ->ask('team', Choice::make('Which team should handle this ticket?')->options([
        'billing'   => 'Payment or subscription issues',
        'technical' => 'Bugs or integration problems',
        'sales'     => 'Pricing or account questions',
    ]))
    ->ask('frustration', Score::make('How frustrated does the customer appear?')->levels([
        'Calm, just stating facts',
        'Frustrated but civil',
        'Very angry, strong language',
    ]))
    ->ask('refund', Noul::make('Does the customer request a refund?'))
    ->get();

if ($result->choice('team')->isConfident(0.8)) {
    $ticket->routeTo($result->choice('team')->value);
}

if ($result->score('frustration')->normalized() > 0.8 || $result->noul('refund')->isTrue()) {
    $ticket->escalate();
}
```

The builder is immutable: every call returns a new instance, so you can keep a base request and derive from it.

`state` can be a string, an array, or anything `Arrayable` / `JsonSerializable` (an Eloquent model works). Refer to
parts of an array or object state with backticked paths in your questions, for example
``"Does `ticket.messages[0].text` request a refund?"``.

You can pick another model for one request with `->model('jev-1.13.0')`.

## Question types

Every question has an **id** (the key you read the answer back with) and **instructions** (the question itself).
Write them in English if you can: it is the most accurate language, and other languages work with lower accuracy
([models](https://docs.typesafe.ai/models.md)).

### `Choice`: which of these options?

```php
Choice::make('What is the purpose of `message`?')->options([
    'complaint' => 'Reports a problem with a public service',
    'request'   => 'Asks the show to play a song',
]);
```

At most 255 options. The SDK also requires at least two, because a one-option choice cannot be a decision.

```php
$answer = $result->choice('intent');

$answer->value;                 // 'complaint': the most probable option
$answer->confidence;            // 0.97
$answer->probabilities;         // ['complaint' => 0.97, 'request' => 0.03]
$answer->probability('request'); // 0.03
$answer->is('complaint');       // true
$answer->isConfident(0.8);      // confidence >= 0.8
```

### `Score`: which level?

```php
Score::make('How good is `message` to be read on air?')->levels([
    'Unusable', 'Weak', 'Acceptable', 'Good', 'Excellent',
]);
```

An ordered rubric of 2 to 10 levels; the first entry is level 0. The score is a probability-weighted value, so it
can fall *between* levels.

```php
$answer = $result->score('on_air');

$answer->value;         // 3.6
$answer->normalized();  // 0.0–1.0, from the size of the legend: 3.6 / 4 = 0.9
$answer->level();       // 4, the nearest whole level
$answer->label();       // 'Excellent'
$answer->legend;        // [0 => 'Unusable', …, 4 => 'Excellent']
$answer->probabilities; // level => probability
$answer->confidence;
```

### `Noul`: is this true?

```php
Noul::make('Does `message` contain insults?');

// Optionally spell out what yes and no mean:
Noul::make('Is this urgent?')->criteria(true: 'Needs action today', false: 'Can wait');
```

The answer is the probability of *yes*, between 0 and 1. The probability itself is the useful signal, so do not
throw it away by comparing to 0.5 everywhere.

```php
$result->noul('insult')->probability; // 0.02
```

## Backed enums

Use a backed enum for the options and map the answer straight back:

```php
enum Intent: string
{
    case Complaint = 'complaint';
    case Request = 'request';
}

$question = Choice::fromEnum(Intent::class, [
    'complaint' => 'Reports a problem with a public service',   // keyed by the case's backing value
    'request'   => 'Asks the show to play a song',
], instructions: 'What is the purpose of `message`?');

// or keep the make() style:
Choice::make('What is the purpose of `message`?')->enum(Intent::class, [/* descriptions */]);

$result->choice('intent')->as(Intent::class);          // Intent::Complaint
$result->choice('intent')->is(Intent::Complaint);      // true
$result->choice('intent')->probability(Intent::Request); // 0.03
```

If you would rather keep the descriptions on the enum, implement `DescribedOption` and drop the array:

```php
enum Intent: string implements \Marcemarin\TypeSafe\Questions\DescribedOption
{
    case Complaint = 'complaint';
    case Request = 'request';

    public function description(): string
    {
        return match ($this) {
            self::Complaint => 'Reports a problem with a public service',
            self::Request => 'Asks the show to play a song',
        };
    }
}

Choice::fromEnum(Intent::class, instructions: 'What is the purpose of `message`?');
```

Int-backed enums work too (the option names go over the wire as strings). If the API ever answers with something
that is not a case, `as()` throws an `UnexpectedResponseException`.

## Thresholds and uncertainty

A probability of 0.55 is not a decision. **Treat the middle band (roughly 0.4–0.6) as "needs review", not as
yes or no.** The helpers make that explicit:

```php
$insult = $result->noul('insult');

$insult->isTrue(0.6);          // probability >= 0.6
$insult->isFalse(0.4);         // probability <  0.4
$insult->isUncertain(0.4, 0.6); // 0.4 <= probability < 0.6
```

With the same numbers the three are an exact partition of 0–1, so exactly one of them is true:

```php
match (true) {
    $insult->isTrue(0.6)           => $message->reject(),
    $insult->isUncertain(0.4, 0.6) => $message->sendToModerator(),
    default                        => $message->publish(),
};
```

Defaults are `isTrue(0.5)`, `isFalse(0.5)` and `isUncertain(0.4, 0.6)`. Choices and scores carry a `confidence`
as well; `isConfident($threshold)` is a shortcut for comparing it.

## The validation rule

`NoulRule` validates input by asking TypeSafe a yes/no question about it:

```php
use Marcemarin\TypeSafe\Rules\NoulRule;

$request->validate([
    'bio'     => ['required', 'string', new NoulRule('Does this text contain personal data?', max: 0.4)],
    'company' => ['required', new NoulRule('Is this a real company name?', min: 0.7)],
]);
```

The value being validated is sent as the `state`, and the rule passes when the probability of *yes* is at most
`max` and at least `min`. With neither bound it defaults to `max: 0.5`. A rejection produces a translatable message
(`typesafe::validation.flagged`; publish the `typesafe-lang` tag to change it).

Other options: `model: 'jev-1.13.0'` and `failOpen: true|false`.

**When TypeSafe cannot be reached** (connection failure or timeout, 429/529 after the retries, or a 5xx) the rule
*fails closed* by default: the input is rejected with `typesafe::validation.unavailable` ("could not be checked
right now"). Set `TYPESAFE_FAIL_OPEN=true` (or pass `failOpen: true`) to let the input through instead. Either way the
exception is passed to Laravel's `report()`, so you find out. Failing open is right for "nice to have" checks and wrong
for anything that protects people; choose deliberately.

Two things to know before putting it on a form:

- **It is a network call inside validation.** Expect roughly a second per rule (each `NoulRule` is its own request), on
  the request thread. That is fine for a profile form and wrong for a hot endpoint; for those, accept the input, decide
  in a queued job and act on the result. Put cheap rules (`required`, `string`, `max`) first so obviously bad input never
  reaches the API.
- **The validated value leaves your server.** It is sent to TypeSafe as the `state`. Check that against your privacy
  policy before validating anything sensitive, and never point it at passwords or payment data.

Errors that are bugs rather than outages are **never** swallowed: a rejected API key, a 422, a malformed response or an
invalid question all throw, even with `failOpen: true`. Otherwise a wrong key would silently turn moderation off.

## Testing

`TypeSafe::fake()` works like `Http::fake()`: it swaps the client for one that never touches the network, returns
well-formed answers for whatever you ask, and records what was asked.

```php
use Marcemarin\TypeSafe\DecisionRequest;
use Marcemarin\TypeSafe\Facades\TypeSafe;

it('routes billing tickets to billing', function () {
    TypeSafe::fake(['team' => 'billing', 'frustration' => 1.5, 'refund' => 0.9]);

    $this->post('/tickets', ['body' => 'Charged twice!'])->assertCreated();

    expect(Ticket::first()->team)->toBe('billing');

    TypeSafe::assertAsked(fn (DecisionRequest $request) => $request->state === 'Charged twice!'
        && $request->has('team'));
    TypeSafe::assertAskedCount(1);
});

it('does nothing for empty tickets', function () {
    TypeSafe::fake();

    // …

    TypeSafe::assertNothingAsked();
});
```

Scripted answers, by question id:

| Question | You script | Fake returns |
|---|---|---|
| `Choice` | an option, a backed enum case, or `['choice' => 'x', 'confidence' => 0.7]` | that option as the winner, the rest of the probability split evenly |
| `Score` | a number (`3.5` sits between levels 3 and 4), or `['score' => 3.5, 'confidence' => 0.7]` | that value, with probabilities that weigh out to it |
| `Noul` | a probability, or `true` / `false` | that probability |
| any | an `Answer` object, or `fn (Question $q, DecisionRequest $r) => …` | whatever you build |

Questions you do not script get the first option, level 0 and probability `0.0`. The fake validates requests the same
way the real client does, so a malformed question fails in your tests too, and it refuses impossible answers (an option
that was not offered, a score outside the levels). Use `->usage(2000, 0)` on the returned fake to control the token
counts, and `->answer([...])` to script more answers later.

To test the HTTP layer itself, `Http::fake()` works as usual: the client is built on Laravel's HTTP client.

## Errors and retries

Everything the package throws deliberately extends `Marcemarin\TypeSafe\Exceptions\TypeSafeException`.

| HTTP status | Exception | Retried? |
|---|---|---|
| 401 | `AuthenticationException` | no |
| 422 | `ValidationException`, with the decoded response in `$e->body` | no |
| 429 | `RateLimitException` | yes |
| 529 | `OverloadedException` | yes |
| other (5xx, 400, …) | `TypeSafeException` with `$e->status` | no |
| no response (DNS, timeout, TLS) | `ConnectionException` | no |
| 200 with a body that is not the documented shape | `UnexpectedResponseException` | no |

`InvalidQuestionException` is thrown *before* anything is sent when a request is malformed: no questions, empty
instructions, a choice with fewer than 2 or more than 255 options, a score with fewer than 2 or more than 10 levels.

The docs tell you to back off exponentially when rate limited; the client does that for 429 and treats 529
(overloaded) the same way. It makes up to `retries` extra attempts, each after a jittered delay that starts around
0.5 s and doubles every time (capped at 8 s). Nothing else is retried.

```php
try {
    $result = TypeSafe::state($text)->ask(/* … */)->get();
} catch (RateLimitException | OverloadedException) {
    // still failing after the retries: degrade gracefully, e.g. queue it for later
} catch (TypeSafeException $e) {
    report($e);
}
```

Limits from the docs: 64k tokens per request (32k for the state plus your longest question), 1,200 requests per
minute, text only. The SDK does not enforce them client-side.

## Caching

Identical requests (same model, state and questions) can be served from your Laravel cache:

```php
TypeSafe::state($text)->ask(/* … */)->cacheFor(now()->addDay())->get();   // or ->cacheFor(3600)

$result->cached; // true when no request was made and no tokens were spent
```

Only successful responses are cached, under a key derived from a hash of the model, the state and the questions. Note
that `jev-latest` is an alias: if you want cached answers to be tied to a model version, pin it.

## Cost

Per [the models page](https://docs.typesafe.ai/models.md), input costs **USD 0.042 per million tokens** and output
tokens are free. Every result carries the token counts:

```php
$result->usage->inputTokens;
$result->usage->outputTokens;
$result->usage->costUsd(); // 0.042 per million input tokens; override with costUsd(inputPerMillion: …)
```

`costUsd()` is an estimate from the published price. Because the price is in a constant you can override, it will not
silently be wrong if TypeSafe changes it.

## Using it without the facade

The client is a plain class built on Laravel's HTTP client. Inject it, or construct it yourself:

```php
use Marcemarin\TypeSafe\Contracts\Client;
use Marcemarin\TypeSafe\TypeSafeClient;

class TicketRouter
{
    public function __construct(private Client $typesafe) {}

    public function route(string $body): string
    {
        return $this->typesafe->state($body)
            ->ask('team', /* … */)
            ->get()
            ->choice('team')->value;
    }
}

// Outside the container:
$client = new TypeSafeClient(apiKey: getenv('TYPESAFE_API_KEY'), model: 'jev-latest', timeout: 10, retries: 2);
```

Type-hint `Marcemarin\TypeSafe\Contracts\Client` (not the concrete class) and `TypeSafe::fake()` replaces it in tests too.

## When *not* to use this

A decision model chooses among options *you* define. It does not write anything. Use an LLM instead (or as the
second step) when you need:

- **Generated text**: replies, summaries, rewrites, translations.
- **Extraction**: pulling out names, places, dates, amounts or any other free-form values.
- **Open-ended reasoning** over a long context: state plus your longest question must fit in 32k tokens, and the model
  is meant for fast, focused judgments.
- **Images, audio or video**: the model is text only.

A common shape is the one in the real-world example below: let the decision model triage everything cheaply, and spend
LLM calls only on the messages that deserve them.

## A real-world use

[radio-chat](https://github.com/marcemarin/radio-chat) uses Jev to triage WhatsApp messages sent to a live radio show:
intent, tone, moderation flags and an "is this good enough to read on air?" score come from one decision-model request
with several questions, and an LLM extractor (topic, place, name, summary) is only called for the messages worth the cost.

## Alternatives

TypeSafe is new and several community PHP clients appeared within days of each other. If this one does not fit, look at:

- [sanmai/typesafe-ai-php](https://github.com/sanmai/typesafe-ai-php): framework-agnostic PHP client (Guzzle, JMS Serializer).
- [butochnikov/typesafe-sdk-php](https://packagist.org/packages/butochnikov/typesafe-sdk-php): PHP client with synchronous and asynchronous requests, plus a separate Laravel bridge.
- [valksor/typesafe-sdk-php](https://packagist.org/packages/valksor/typesafe-sdk-php): aims for 1:1 parity with the official SDKs.

What this package is for: Laravel applications. It builds on Laravel's own HTTP client, and adds the pieces that only make
sense inside the framework: a validation rule, `TypeSafe::fake()` with assertions, backed-enum answers, config, translations
and cache integration.

## Development

```bash
composer install
composer check          # pest, phpstan (level 8), pint --test
vendor/bin/pest --coverage   # needs Xdebug or PCOV
```

There is one opt-in smoke test that talks to the real API. It is skipped unless you provide a key, and is never run in
CI:

```bash
TYPESAFE_LIVE_API_KEY=your-key vendor/bin/pest tests/Live
```

## Changelog and license

See [CHANGELOG.md](CHANGELOG.md). Released under the [MIT license](LICENSE).
