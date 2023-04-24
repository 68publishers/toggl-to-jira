<?php

declare(strict_types=1);

namespace App\Helper;

use DateTimeImmutable;
use DateTimeZone;
use Exception;
use function sprintf;

final class DurationFormatter
{
    public function __construct() {}

    /**
     * @throws Exception
     */
    public static function format(int $seconds): string
    {
        $start = new DateTimeImmutable('now', new DateTimeZone('UTC'));

        $end = $start->modify(sprintf(
            '+%d seconds',
            $seconds,
        ));

        $interval = $start->diff($end);
        $hours = $interval->h;
        $minutes = $interval->i;

        return 0 < $hours ? ($hours . 'h ' . $minutes . 'm') : ($minutes . 'm');
    }
}
