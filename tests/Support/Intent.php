<?php

declare(strict_types=1);

namespace Marcemarin\TypeSafe\Tests\Support;

enum Intent: string
{
    case Complaint = 'complaint';
    case Request = 'request';
}
