<?php

declare(strict_types=1);

namespace App\Synchronization;

use Psr\Log\LoggerInterface;

interface SynchronizerInterface
{
    public function generateDataSet(Options $options, ?LoggerInterface $logger = null): DataSet;

    public function sync(DataSet $dataSet, ?LoggerInterface $logger = null): bool;
}
