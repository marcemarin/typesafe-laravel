<?php

declare(strict_types=1);

namespace Marcemarin\TypeSafe\Exceptions;

/**
 * TypeSafe rejected the request as invalid (HTTP 422). The decoded response is available in $body.
 */
class ValidationException extends TypeSafeException {}
