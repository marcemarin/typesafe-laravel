<?php

declare(strict_types=1);

use Marcemarin\TypeSafe\Http\Backoff;
use Marcemarin\TypeSafe\Support\JsonMap;

it('doubles the delay ceiling on every retry and keeps half of it as jitter', function (int $retry, int $min, int $max) {
    $backoff = new Backoff(baseMs: 500, maxMs: 8000);

    foreach (range(1, 50) as $_) {
        expect($backoff->delayMs($retry))->toBeGreaterThanOrEqual($min)->toBeLessThanOrEqual($max);
    }
})->with([
    'first retry' => [1, 250, 500],
    'second retry' => [2, 500, 1000],
    'third retry' => [3, 1000, 2000],
    'fifth retry' => [5, 4000, 8000],
    'capped at the maximum' => [10, 4000, 8000],
]);

it('actually jitters', function () {
    $delays = array_map(fn () => (new Backoff(500, 8000))->delayMs(3), range(1, 30));

    expect(count(array_unique($delays)))->toBeGreaterThan(1);
});

it('treats a retry number below one like the first retry', function () {
    expect((new Backoff(500, 8000))->delayMs(0))->toBeBetween(250, 500);
});

describe('JsonMap', function () {
    it('leaves string-keyed maps as arrays', function () {
        expect(JsonMap::of(['a' => 1]))->toBe(['a' => 1]);
    });

    it('turns list-shaped maps into objects so they encode as JSON objects', function () {
        expect(json_encode(JsonMap::of(['x', 'y'])))->toBe('{"0":"x","1":"y"}');
    });

    it('leaves an empty map alone', function () {
        expect(JsonMap::of([]))->toBe([]);
    });
});
