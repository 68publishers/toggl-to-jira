<?php

declare(strict_types=1);

namespace App\Client;

use App\Exception\AbortException;
use App\ValueObject\Entry;
use App\ValueObject\Filter;
use App\ValueObject\Range;
use Psr\Log\LoggerInterface;

interface ReadClientInterface
{
    /**
     * @param array<int, Filter> $filters
     *
     * @return array<Entry>
     * @throws AbortException
     */
    public function listEntries(Range $range, array $filters, LoggerInterface $logger): array;
}
