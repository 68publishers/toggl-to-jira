<?php

declare(strict_types=1);

namespace App\Synchronization;

use App\ValueObject\Entry;
use App\ValueObject\GroupMode;
use App\ValueObject\Rounding;
use App\ValueObject\SyncMode;

interface DiffGeneratorInterface
{
    /**
     * @param array<Entry> $sourceEntries
     * @param array<Entry> $destinationEntries
     */
    public function diff(array $sourceEntries, array $destinationEntries, GroupMode $groupMode, SyncMode $syncMode, ?Rounding $rounding = null): Diff;
}
