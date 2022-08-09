<?php

declare(strict_types=1);

namespace App\Synchronization;

use App\ValueObject\SyncMode;

interface DiffGeneratorInterface
{
	/**
	 * @param \App\ValueObject\Entry[]  $sourceEntries
	 * @param \App\ValueObject\Entry[]  $destinationEntries
	 * @param \App\ValueObject\SyncMode $mode
	 *
	 * @return \App\Synchronization\Diff
	 */
	public function diff(array $sourceEntries, array $destinationEntries, SyncMode $mode): Diff;
}
