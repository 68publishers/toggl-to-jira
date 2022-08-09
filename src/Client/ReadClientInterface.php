<?php

declare(strict_types=1);

namespace App\Client;

use App\ValueObject\Range;
use Psr\Log\LoggerInterface;

interface ReadClientInterface
{
	/**
	 * @throws \App\Exception\AbortException
	 */
	public function listEntries(Range $range, array $issueCodes, LoggerInterface $logger): array;
}
