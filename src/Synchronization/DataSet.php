<?php

declare(strict_types=1);

namespace App\Synchronization;

use App\ValueObject\Entry;

final class DataSet
{
    /**
     * @param array<Entry> $sourceEntries
     * @param array<Entry> $destinationEntries
     */
    public function __construct(
        public readonly array $sourceEntries,
        public readonly array $destinationEntries,
        public readonly Diff $diff,
    ) {}

    public static function empty(): self
    {
        return new self([], [], new Diff([], [], [], []));
    }
}
