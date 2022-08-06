<?php

declare(strict_types=1);

namespace App\Synchronization;

use Psr\Log\LoggerInterface;

interface SynchronizerInterface
{
	public function sync(Options $options, ?LoggerInterface $logger = NULL): void;
}
