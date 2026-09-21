<?php

declare(strict_types=1);

namespace Marcemarin\TypeSafe\Questions;

/**
 * Implement this on a backed enum so `Choice::fromEnum(Intent::class)` can read
 * each case's description from the enum itself.
 */
interface DescribedOption
{
    public function description(): string;
}
