<?php

declare(strict_types=1);

namespace App\Synchronization;

final class DataSet
{
	/**
	 * @param \App\ValueObject\Entry[]  $sourceEntries
	 * @param \App\ValueObject\Entry[]  $destinationEntries
	 * @param \App\Synchronization\Diff $diff
	 */
	public function __construct(
		public readonly array $sourceEntries,
		public readonly array $destinationEntries,
		public readonly Diff $diff,
	) {
	}

	public static function empty(): self
	{
		return new self([], [], new Diff([], [], []));
	}
}
