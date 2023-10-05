<?php

declare(strict_types=1);

namespace App\Synchronization;

use App\Client\ReadClientInterface;
use App\Client\WriteClientInterface;
use App\Exception\AbortException;
use App\ValueObject\Entry;
use Exception;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use function array_map;
use function array_unique;

final class Synchronizer implements SynchronizerInterface
{
    public function __construct(
        private readonly ReadClientInterface $readClient,
        private readonly WriteClientInterface $writeClient,
        private readonly DiffGeneratorInterface $diffGenerator,
    ) {}

    /**
     * @throws Exception
     */
    public function generateDataSet(Options $options, ?LoggerInterface $logger = null): DataSet
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
     * @throws Exception
     */
    public function sync(DataSet $dataSet, ?LoggerInterface $logger = null): bool
    {
        $logger = $logger ?? new NullLogger();

        try {
            if (!$dataSet->diff->hasChanges()) {
                return $this->doSync($dataSet->diff, $logger);
            }

            return true;
        } catch (AbortException $e) {
            $logger->error($e->getMessage());

            return false;
        }
    }

    /**
     * @throws Exception
     */
    private function createDataSet(Options $options, LoggerInterface $logger): DataSet
    {
        $sourceEntries = $this->readClient->listEntries($options->range, $options->filters, $logger);

        if (empty($sourceEntries)) {
            $logger->info('Nothing to synchronize.');

            return DataSet::empty();
        }

        $issuesCodes = !empty($options->issueCodes) ? $options->issueCodes : array_unique(array_map(static fn (Entry $entry): string => $entry->issue, $sourceEntries));
        $destinationEntries = $this->writeClient->listEntries($options->range, $issuesCodes, $logger);

        $diff = $this->diffGenerator->diff($sourceEntries, $destinationEntries, $options->groupMode, $options->syncMode, $options->rounding);

        return new DataSet($sourceEntries, $destinationEntries, $diff);
    }

    private function doSync(Diff $diff, LoggerInterface $logger): bool
    {
        $ok = true;

        foreach ($diff->deletes as $entry) {
            if (!$this->writeClient->deleteEntry($entry, $logger)) {
                $ok = false;
            }
        }

        foreach ($diff->updates as $entry) {
            if (!$this->writeClient->updateEntry($entry, $logger)) {
                $ok = false;
            }
        }

        foreach ($diff->inserts as $entry) {
            if (!$this->writeClient->createEntry($entry, $logger)) {
                $ok = false;
            }
        }

        return $ok;
    }
}
