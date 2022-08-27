<?php

declare(strict_types=1);

namespace App\Synchronization;

use App\ValueObject\Rounding;

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

	public function empty(): bool
	{
		return empty($this->inserts) && empty($this->updates) && empty($this->deletes);
	}

	public function merge(self $diff): self
	{
		return new self(
			array_merge($this->inserts, $diff->inserts),
			array_merge($this->updates, $diff->updates),
			array_merge($this->deletes, $diff->deletes),
		);
	}

	/**
	 * @throws \Exception
	 */
	public function withRounding(Rounding $rounding): self
	{
		$inserts = $updates = [];

		foreach ($this->inserts as $i => $insert) {
			$inserts[$i] = $insert->withRoundedDuration($rounding);
		}

		foreach ($this->updates as $i => $update) {
			$updates[$i] = $update->withRoundedDuration($rounding);
		}

		return new self($inserts, $updates, $this->deletes);
	}
}
