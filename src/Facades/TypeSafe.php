<?php

declare(strict_types=1);

namespace Marcemarin\TypeSafe\Facades;

use Closure;
use Illuminate\Support\Facades\Facade;
use LogicException;
use Marcemarin\TypeSafe\Contracts\Client;
use Marcemarin\TypeSafe\DecisionRequest;
use Marcemarin\TypeSafe\PendingDecision;
use Marcemarin\TypeSafe\Result;
use Marcemarin\TypeSafe\Testing\FakeClient;

/**
 * @method static PendingDecision state(string|array<mixed>|object $state)
 * @method static Result send(DecisionRequest $request)
 * @method static string defaultModel()
 *
 * @see Client
 */
final class TypeSafe extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return Client::class;
    }

    /**
     * Replace the client with a fake, like `Http::fake()`. See FakeClient for the answer formats.
     *
     * @param  array<string, mixed>  $answers  question id => scripted answer
     */
    public static function fake(array $answers = []): FakeClient
    {
        $fake = new FakeClient($answers, self::resolveFacadeInstance(self::getFacadeAccessor())->defaultModel());
        self::swap($fake);

        return $fake;
    }

    /**
     * @param  (Closure(DecisionRequest): bool)|null  $callback
     */
    public static function assertAsked(?Closure $callback = null, ?int $times = null): void
    {
        self::fakeInstance()->assertAsked($callback, $times);
    }

    public static function assertAskedCount(int $count): void
    {
        self::fakeInstance()->assertAskedCount($count);
    }

    public static function assertNothingAsked(): void
    {
        self::fakeInstance()->assertNothingAsked();
    }

    private static function fakeInstance(): FakeClient
    {
        $root = self::getFacadeRoot();

        return $root instanceof FakeClient
            ? $root
            : throw new LogicException('Call TypeSafe::fake() before making assertions.');
    }
}
