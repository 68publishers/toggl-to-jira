<?php

declare(strict_types=1);

namespace App\Synchronization;

use App\ValueObject\Entry;
use function array_merge;

final class Diff
{
    /**
     * @param array<Entry> $inserts
     * @param array<Entry> $updates
     * @param array<Entry> $deletes
     * @param array<Entry> $intersections
     */
    public function __construct(
        public readonly array $inserts,
        public readonly array $updates,
        public readonly array $deletes,
        public readonly array $intersections,
    ) {}

    public function hasChanges(): bool
    {
        return empty($this->inserts) && empty($this->updates) && empty($this->deletes);
    }

    public function merge(self $diff): self
    {
        return new self(
            array_merge($this->inserts, $diff->inserts),
            array_merge($this->updates, $diff->updates),
            array_merge($this->deletes, $diff->deletes),
            array_merge($this->intersections, $diff->intersections),
        );
    }
}
