<?php

declare(strict_types=1);

namespace Marcemarin\TypeSafe\Exceptions;

use RuntimeException;
use Throwable;

/**
 * Base class for everything this package throws on purpose, so a single
 * `catch (TypeSafeException)` covers client-side validation, transport and API errors.
 */
class TypeSafeException extends RuntimeException
{
    /**
     * @param  array<mixed>  $body  Decoded JSON body of the failed response, when there was one.
     */
    public function __construct(
        string $message,
        public readonly ?int $status = null,
        public readonly array $body = [],
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, $status ?? 0, $previous);
    }
}
