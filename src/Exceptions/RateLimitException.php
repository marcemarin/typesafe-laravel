<?php

declare(strict_types=1);

namespace Marcemarin\TypeSafe\Exceptions;

/**
 * The rate limit was exceeded (HTTP 429) and every retry was used up.
 */
class RateLimitException extends TypeSafeException {}
