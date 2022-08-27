<?php

declare(strict_types=1);

namespace App\Synchronization;

use App\ValueObject\GroupMode;

interface DiffGeneratorInterface
{
	/**
	 * @param \App\ValueObject\Entry[]   $sourceEntries
	 * @param \App\ValueObject\Entry[]   $destinationEntries
	 * @param \App\ValueObject\GroupMode $mode
	 *
	 * @return \App\Synchronization\Diff
	 */
	public function diff(array $sourceEntries, array $destinationEntries, GroupMode $mode): Diff;
}
