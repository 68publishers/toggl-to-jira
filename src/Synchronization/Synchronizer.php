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
	public function generateDataSet(Options $options, ?LoggerInterface $logger = NULL): DataSet
	{
		$logger = $logger ?? new NullLogger();

		try {
			$dataSet = $this->createDataSet($options, $logger);
		} catch (AbortException $e) {
			$logger->error($e->getMessage());
		}

		return $dataSet ?? DataSet::empty();
	}

	/**
	 * @throws \Exception
	 */
	public function sync(DataSet $dataSet, ?LoggerInterface $logger = NULL): bool
	{
		$logger = $logger ?? new NullLogger();

		try {
			if (!$dataSet->diff->empty()) {
				return $this->doSync($dataSet->diff, $logger);
			}

			return TRUE;
		} catch (AbortException $e) {
			$logger->error($e->getMessage());

			return FALSE;
		}
	}

	/**
	 * @throws \Exception
	 */
	private function createDataSet(Options $options, LoggerInterface $logger): DataSet
	{
		$sourceEntries = $this->sourceReader->listEntries($options->range, $options->issueCodes, $logger);

		if (empty($sourceEntries)) {
			$logger->info('Nothing to synchronize.');

			return DataSet::empty();
		}

		$issuesCodes = !empty($options->issueCodes) ? $options->issueCodes : array_unique(array_map(static fn (Entry $entry): string => $entry->issue, $sourceEntries));
		$destinationEntries = $this->destinationReader->listEntries($options->range, $issuesCodes, $logger);

		$diff = $this->diffGenerator->diff($sourceEntries, $destinationEntries, $options->groupMode);

		if (NULL !== $options->rounding) {
			$diff = $diff->withRounding($options->rounding);
		}

		return new DataSet($sourceEntries, $destinationEntries, $diff);
	}

	private function doSync(Diff $diff, LoggerInterface $logger): bool
	{
		$ok = TRUE;

		foreach ($diff->deletes as $entry) {
			if (!$this->destinationWriter->deleteEntry($entry, $logger)) {
				$ok = FALSE;
			}
		}

		foreach ($diff->updates as $entry) {
			if (!$this->destinationWriter->updateEntry($entry, $logger)) {
				$ok = FALSE;
			}
		}

		foreach ($diff->inserts as $entry) {
			if (!$this->destinationWriter->createEntry($entry, $logger)) {
				$ok = FALSE;
			}
		}

		return $ok;
	}
}
