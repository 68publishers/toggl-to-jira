<?php

declare(strict_types=1);

namespace App\Synchronization;

use Psr\Log\NullLogger;
use App\ValueObject\Entry;
use Psr\Log\LoggerInterface;
use App\Exception\AbortException;
use App\Client\ReadClientInterface;
use App\Client\WriteClientInterface;

final class Synchronizer implements SynchronizerInterface
{
	public function __construct(
		private readonly ReadClientInterface $sourceReader,
		private readonly ReadClientInterface $destinationReader,
		private readonly WriteClientInterface $destinationWriter,
		private readonly DiffGeneratorInterface $diffGenerator,
	) {
	}

	/**
	 * @throws \Exception
	 */
	public function sync(Options $options, ?LoggerInterface $logger = NULL): void
	{
		$logger = $logger ?? new NullLogger();

		try {
			$this->doSync($options, $logger);
		} catch (AbortException $e) {
			$logger->error($e->getMessage());
		}
	}

	/**
	 * @throws \Exception
	 */
	private function doSync(Options $options, LoggerInterface $logger): void
	{
		$sourceEntries = $this->sourceReader->listEntries($options->range, [], $logger);

		if (empty($sourceEntries)) {
			$logger->info('Nothing to synchronize.');

			return;
		}

		$issuesCodes = array_unique(array_map(static fn (Entry $entry): string => $entry->issue, $sourceEntries));
		$destinationEntries = $this->destinationReader->listEntries($options->range, $issuesCodes, $logger);

		$diff = $this->diffGenerator->diff($sourceEntries, $destinationEntries, $options->syncMode);

		foreach ($diff->deletes as $entry) {
			$this->destinationWriter->deleteEntry($entry, $logger);
		}

		foreach ($diff->updates as $entry) {
			if (NULL !== $options->rounding) {
				$entry = $entry->withRoundedDuration($options->rounding);
			}

			$this->destinationWriter->updateEntry($entry, $logger);
		}

		foreach ($diff->inserts as $entry) {
			if (NULL !== $options->rounding) {
				$entry = $entry->withRoundedDuration($options->rounding);
			}

			$this->destinationWriter->createEntry($entry, $logger);
		}
	}
}
