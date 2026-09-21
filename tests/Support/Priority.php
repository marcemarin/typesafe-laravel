<?php

declare(strict_types=1);

namespace Marcemarin\TypeSafe\Tests\Support;

enum Priority: int
{
    case Low = 1;
    case High = 2;
}
