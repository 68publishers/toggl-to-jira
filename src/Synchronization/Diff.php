<?php

declare(strict_types=1);

namespace App\Synchronization;

final class Diff
{
	/**
	 * @param \App\ValueObject\Entry[] $inserts
	 * @param \App\ValueObject\Entry[] $updates
	 * @param \App\ValueObject\Entry[] $deletes
	 */
	public function __construct(
		public readonly array $inserts,
		public readonly array $updates,
		public readonly array $deletes,
	) {
	}

	public function merge(self $diff): self
	{
		return new self(
			array_merge($this->inserts, $diff->inserts),
			array_merge($this->updates, $diff->updates),
			array_merge($this->deletes, $diff->deletes),
		);
	}
}
