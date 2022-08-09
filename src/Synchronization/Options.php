<?php

declare(strict_types=1);

namespace App\Synchronization;

use App\ValueObject\Range;
use App\ValueObject\Rounding;
use App\ValueObject\SyncMode;

final class Options
{
	public function __construct(
		public readonly Range $range,
		public readonly SyncMode $syncMode,
		public readonly ?Rounding $rounding = NULL
	) {
	}
}
