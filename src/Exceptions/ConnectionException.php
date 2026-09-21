<?php

declare(strict_types=1);

namespace Marcemarin\TypeSafe\Exceptions;

/**
 * The request never got a response: DNS, TLS, connection or timeout failure.
 */
class ConnectionException extends TypeSafeException {}
