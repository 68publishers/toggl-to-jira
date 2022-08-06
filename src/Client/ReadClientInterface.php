<?php

declare(strict_types=1);

namespace App\Client;

use App\ValueObject\Range;
use Psr\Log\LoggerInterface;

interface ReadClientInterface
{
	public function listEntries(Range $range, LoggerInterface $logger): array;
}
