<?php

declare(strict_types=1);

namespace App\Client;

use Psr\Log\LoggerInterface;
use App\ValueObject\SyncMode;

interface WriteClientInterface
{
	public function writeEntries(array $entries, SyncMode $syncMode, LoggerInterface $logger): void;
}
