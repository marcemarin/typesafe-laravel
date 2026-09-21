<?php

declare(strict_types=1);

namespace Marcemarin\TypeSafe\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use InvalidArgumentException;
use Marcemarin\TypeSafe\Exceptions\AuthenticationException;
use Marcemarin\TypeSafe\Exceptions\ConnectionException;
use Marcemarin\TypeSafe\Exceptions\OverloadedException;
use Marcemarin\TypeSafe\Exceptions\RateLimitException;
use Marcemarin\TypeSafe\Exceptions\TypeSafeException;
use Marcemarin\TypeSafe\Facades\TypeSafe;
use Marcemarin\TypeSafe\Questions\Noul;

/**
 * Validate input by asking TypeSafe a yes/no question about it.
 *
 *     'bio' => ['required', new NoulRule('Does this text contain personal data?', max: 0.4)]
 *
 * The rule passes when the probability of "yes" is at most `max` and at least `min`.
 * With neither given, `max` defaults to 0.5 (fail when a yes is more likely than a no).
 */
final class NoulRule implements ValidationRule
{
    private readonly ?float $max;

    public function __construct(
        private readonly string $question,
        ?float $max = null,
        private readonly ?float $min = null,
        private readonly ?bool $failOpen = null,
        private readonly ?string $model = null,
    ) {
        foreach (['max' => $max, 'min' => $min] as $name => $bound) {
            if ($bound !== null && ($bound < 0.0 || $bound > 1.0)) {
                throw new InvalidArgumentException("The {$name} bound is a probability between 0 and 1, got {$bound}.");
            }
        }

        $this->max = $max === null && $min === null ? 0.5 : $max;

        if ($this->max !== null && $this->min !== null && $this->min > $this->max) {
            throw new InvalidArgumentException("min ({$this->min}) cannot be above max ({$this->max}).");
        }
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (is_int($value) || is_float($value)) {
            $value = (string) $value;
        }
        if (! is_string($value) && ! is_array($value)) {
            $fail('typesafe::validation.unreadable')->translate();

            return;
        }

        try {
            $pending = TypeSafe::state($value)->ask('rule', Noul::make($this->question));
            $probability = ($this->model === null ? $pending : $pending->model($this->model))->get()->noul('rule')->probability;
        } catch (AuthenticationException $e) {
            throw $e; // A bad key is a deployment bug, not "the API is down": never fail open on it.
        } catch (ConnectionException|RateLimitException|OverloadedException $e) {
            $this->unavailable($e, $fail);

            return;
        } catch (TypeSafeException $e) {
            // A 5xx means the service is unreachable in practice; everything else (422, invalid question,
            // malformed response) is a bug in the calling code and must stay loud.
            if ($e->status === null || $e->status < 500) {
                throw $e;
            }
            $this->unavailable($e, $fail);

            return;
        }

        if (($this->max !== null && $probability > $this->max) || ($this->min !== null && $probability < $this->min)) {
            $fail('typesafe::validation.flagged')->translate();
        }
    }

    private function unavailable(TypeSafeException $e, Closure $fail): void
    {
        report($e);

        if (! ($this->failOpen ?? (bool) config('typesafe.fail_open', false))) {
            $fail('typesafe::validation.unavailable')->translate();
        }
    }
}
