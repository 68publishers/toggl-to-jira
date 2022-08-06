<?php

declare(strict_types=1);

namespace App\Synchronization;

use Psr\Log\NullLogger;
use Psr\Log\LoggerInterface;
use App\Client\ReadClientInterface;
use App\Client\WriteClientInterface;

final class Synchronizer implements SynchronizerInterface
{
	public function __construct(
		private readonly ReadClientInterface $sourceReader,
		private readonly WriteClientInterface $destinationWriter,
	) {
	}

	public function sync(Options $options, ?LoggerInterface $logger = NULL): void
	{
		$logger = $logger ?? new NullLogger();
		$entries = $this->sourceReader->listEntries($options->range, $logger);

		$this->destinationWriter->writeEntries($entries, $options->syncMode, $logger);
	}
}
