<?php

declare(strict_types=1);

namespace Marcemarin\TypeSafe\Contracts;

use Marcemarin\TypeSafe\DecisionRequest;
use Marcemarin\TypeSafe\PendingDecision;
use Marcemarin\TypeSafe\Result;

interface Client
{
    /**
     * Start building a request about $state (a string, an array, or an Arrayable / JsonSerializable object).
     *
     * @param  string|array<mixed>|object  $state
     */
    public function state(string|array|object $state): PendingDecision;

    public function send(DecisionRequest $request): Result;

    /** Model used when a request does not pick one. */
    public function defaultModel(): string;
}
