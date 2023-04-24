<?php

declare(strict_types=1);

namespace App\ValueObject;

use DateTimeImmutable;
use InvalidArgumentException;

final class Range
{
    public function __construct(
        public readonly DateTimeImmutable $start,
        public readonly DateTimeImmutable $end,
    ) {
        if ($this->end < $this->start) {
            throw new InvalidArgumentException('End date can not be before start date.');
        }
    }
}
