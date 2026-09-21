<?php

declare(strict_types=1);

namespace Marcemarin\TypeSafe\Exceptions;

/**
 * The service was overloaded (HTTP 529) and every retry was used up.
 */
class OverloadedException extends TypeSafeException {}
