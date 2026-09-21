<?php

declare(strict_types=1);

namespace Marcemarin\TypeSafe\Exceptions;

/**
 * The API key is missing or was rejected (HTTP 401).
 */
class AuthenticationException extends TypeSafeException {}
