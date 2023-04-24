<?php

declare(strict_types=1);

namespace App\ValueObject;

use DateTimeImmutable;
use DateTimeZone;
use Exception;
use InvalidArgumentException;
use function ceil;
use function sprintf;

final class Rounding
{
    public function __construct(
        private readonly int $minutes,
    ) {
        if (1 >= $this->minutes || 60 < $this->minutes) {
            throw new InvalidArgumentException(sprintf(
                'Minutes for rounding must be integer in the range [2-60], %d passed.',
                $this->minutes,
            ));
        }
    }

    /**
     * @throws Exception
     */
    public function round(int $seconds): int
    {
        $datetime = new DateTimeImmutable('@' . $seconds, new DateTimeZone('UTC'));

        $datetime = $datetime->setTime(
            (int) $datetime->format('H'),
            (int) ceil($datetime->format('i') / $this->minutes) * $this->minutes,
        );

        return $datetime->getTimestamp();
    }
}
