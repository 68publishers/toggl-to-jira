<?php

declare(strict_types=1);

namespace App\Synchronization;

use App\ValueObject\Entry;
use App\ValueObject\GroupMode;

interface DiffGeneratorInterface
{
    /**
     * @param array<Entry> $sourceEntries
     * @param array<Entry> $destinationEntries
     */
    public function diff(array $sourceEntries, array $destinationEntries, GroupMode $mode): Diff;
}
