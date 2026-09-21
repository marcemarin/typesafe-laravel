<?php

declare(strict_types=1);

namespace Marcemarin\TypeSafe\Tests\Support;

use Marcemarin\TypeSafe\Questions\DescribedOption;

enum DescribedIntent: string implements DescribedOption
{
    case Complaint = 'complaint';
    case Request = 'request';

    public function description(): string
    {
        return match ($this) {
            self::Complaint => 'Reports a problem',
            self::Request => 'Asks for something',
        };
    }
}
