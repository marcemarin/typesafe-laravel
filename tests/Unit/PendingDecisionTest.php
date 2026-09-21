<?php

declare(strict_types=1);

use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Support\Collection;
use Marcemarin\TypeSafe\Contracts\Client;
use Marcemarin\TypeSafe\Exceptions\InvalidQuestionException;
use Marcemarin\TypeSafe\Facades\TypeSafe;
use Marcemarin\TypeSafe\Questions\Noul;
use Marcemarin\TypeSafe\TypeSafeClient;

beforeEach(fn () => $this->client = new TypeSafeClient('key', model: 'jev-latest'));

it('starts from the default model and keeps questions in order', function () {
    $request = $this->client->state('hi')
        ->ask('b', Noul::make('Second?'))
        ->ask('a', Noul::make('First?'))
        ->request();

    expect($request->model)->toBe('jev-latest')
        ->and(array_keys($request->questions))->toBe(['b', 'a'])
        ->and($request->has('a'))->toBeTrue()
        ->and($request->question('a'))->toEqual(Noul::make('First?'))
        ->and($request->question('zzz'))->toBeNull();
});

it('is immutable: a shared base builder is not polluted by derived ones', function () {
    $base = $this->client->state('hi')->ask('a', Noul::make('A?'));
    $derived = $base->ask('b', Noul::make('B?'))->model('jev-1.13.0')->cacheFor(60);

    expect($base->request()->questions)->toHaveCount(1)
        ->and($base->request()->model)->toBe('jev-latest')
        ->and($base->request()->cacheFor)->toBeNull()
        ->and($derived->request()->questions)->toHaveCount(2)
        ->and($derived->request()->model)->toBe('jev-1.13.0')
        ->and($derived->request()->cacheFor)->toBe(60);
});

it('normalizes the state', function (mixed $state, string|array $expected) {
    expect($this->client->state($state)->request()->state)->toBe($expected);
})->with([
    'string' => ['hello', 'hello'],
    'array' => [['a' => 1], ['a' => 1]],
    'list' => [['x', 'y'], ['x', 'y']],
    'Arrayable' => [new Collection(['a' => 1]), ['a' => 1]],
    'JsonSerializable array' => [new class implements JsonSerializable
    {
        public function jsonSerialize(): array
        {
            return ['a' => 2];
        }
    }, ['a' => 2]],
    'JsonSerializable string' => [new class implements JsonSerializable
    {
        public function jsonSerialize(): string
        {
            return 'text';
        }
    }, 'text'],
    'Stringable' => [new class implements Stringable
    {
        public function __toString(): string
        {
            return 'stringable';
        }
    }, 'stringable'],
    'plain object' => [(object) ['a' => 3], ['a' => 3]],
]);

it('accepts any Arrayable', function () {
    $arrayable = new class implements Arrayable
    {
        public function toArray(): array
        {
            return ['id' => 7];
        }
    };

    expect($this->client->state($arrayable)->request()->state)->toBe(['id' => 7]);
});

it('rejects a JsonSerializable that does not produce text or a structure', function () {
    $this->client->state(new class implements JsonSerializable
    {
        public function jsonSerialize(): int
        {
            return 5;
        }
    });
})->throws(InvalidArgumentException::class, 'must serialize to a string, array or object');

it('rejects a Closure as state', function () {
    $this->client->state(fn () => 'x');
})->throws(InvalidArgumentException::class, 'Closure');

it('refuses to send a request without questions', function () {
    $this->client->state('hi')->get();
})->throws(InvalidQuestionException::class, 'Ask at least one question');

it('refuses to send an empty model', function () {
    $this->client->state('hi')->ask('a', Noul::make('A?'))->model(' ')->get();
})->throws(InvalidQuestionException::class, 'model cannot be empty');

it('refuses to send an empty question id', function () {
    $this->client->state('hi')->ask('', Noul::make('A?'))->get();
})->throws(InvalidQuestionException::class, 'ids cannot be empty');

it('builds the documented payload, with numeric ids and options kept as JSON objects', function () {
    $request = $this->client->state(['a' => 1])
        ->ask('1', Noul::make('A?'))
        ->request();

    expect(json_encode($request->toPayload()))->toBe(
        '{"model":"jev-latest","state":{"a":1},"questions":{"1":{"type":"noul","instructions":"A?"}}}'
    );
});

it('fingerprints on model, state and questions', function () {
    $base = $this->client->state('hi')->ask('a', Noul::make('A?'));

    expect($base->request()->fingerprint())->toBe($this->client->state('hi')->ask('a', Noul::make('A?'))->request()->fingerprint())
        ->and($base->request()->fingerprint())->not->toBe($base->model('jev-1.13.0')->request()->fingerprint())
        ->and($base->request()->fingerprint())->not->toBe($this->client->state('bye')->ask('a', Noul::make('A?'))->request()->fingerprint())
        ->and($base->request()->fingerprint())->not->toBe($this->client->state('hi')->ask('a', Noul::make('B?'))->request()->fingerprint())
        ->and($base->request()->fingerprint())->not->toBe($this->client->state('hi')->ask('b', Noul::make('A?'))->request()->fingerprint())
        ->and($base->cacheFor(10)->request()->fingerprint())->toBe($base->request()->fingerprint());
});

it('is reachable from the container through the facade', function () {
    expect(TypeSafe::defaultModel())->toBe('jev-latest')
        ->and(app('typesafe'))->toBe(app(Client::class));
});
