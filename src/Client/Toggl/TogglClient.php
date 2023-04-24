<?php

declare(strict_types=1);

namespace App\Client\Toggl;

use App\Client\ReadClientInterface;
use App\ValueObject\Entry;
use App\ValueObject\Range;
use DateTimeImmutable;
use DateTimeZone;
use GuzzleHttp\ClientInterface;
use Psr\Log\LoggerInterface;
use Throwable;
use function array_filter;
use function array_map;
use function assert;
use function in_array;
use function is_int;
use function is_string;
use function json_decode;
use function preg_match;
use function sprintf;
use function trim;

final class TogglClient implements ReadClientInterface
{
    private const URI = 'https://api.track.toggl.com/api/v9';

    public function __construct(
        private readonly string $apiToken,
        private readonly ClientInterface $client,
    ) {}

    public function listEntries(Range $range, array $issueCodes, LoggerInterface $logger): array
    {
        // @todo Toggl API v9 doesn't accept DateTimes with a time, currently the only date can be passed.
        $startDate = $range->start->setTime(0, 0);
        $endDate = $range->end->setTime(0, 0)->modify('+1 day');

        try {
            $response = $this->client->request('GET', self::URI . '/me/time_entries', [
                'auth' => [$this->apiToken, 'api_token'],
                'headers' => [
                    'Content-Type' => 'application/json',
                ],
                'query' => [
                    'start_date' => $startDate->format('Y-m-d'),
                    'end_date' => $endDate->format('Y-m-d'),
                ],
            ]);

            $documents = json_decode($response->getBody()->getContents(), true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable $e) {
            $logger->error(sprintf(
                '[toggl] Unable to fetch entries from Toggl. %s',
                $e->getMessage(),
            ));

            return [];
        }

        return array_filter(
            array_map(
                function (array $entry) use ($issueCodes, $logger): ?Entry {
                    assert(
                        isset($entry['id'], $entry['description'], $entry['start'], $entry['duration'])
                        && is_int($entry['id'])
                        && is_string($entry['description'])
                        && is_string($entry['start'])
                        && (!isset($entry['stop']) || is_string($entry['stop']))
                        && is_int($entry['duration']),
                    );

                    return $this->createEntry($entry, $issueCodes, $logger);
                },
                $documents,
            ),
        );
    }

    /**
     * @param array{id: int, description: string, start: string, stop?: string, duration: int} $entry
     * @param array<string>                                                                    $issueCodes
     */
    private function createEntry(array $entry, array $issueCodes, LoggerInterface $logger): ?Entry
    {
        $parsed = false !== (bool) preg_match('/^(?<ISSUE>[a-zA-Z]+-\d+)( +)?(?<DESCRIPTION>.*)?$/', $entry['description'], $m);
        $stop = $entry['stop'] ?? null;

        if (!$parsed || !isset($m['ISSUE'], $m['DESCRIPTION'])) {
            $logger->warning(sprintf(
                '[toggl] Can not synchronize entry "%s" from %s - %s. The entry description is not properly formatted.',
                $entry['description'],
                $entry['start'],
                $stop ?? '?',
            ));

            return null;
        }

        $issueCode = trim($m['ISSUE']);

        if (!empty($issueCodes) && !in_array($issueCode, $issueCodes, true)) {
            return null;
        }

        if (null === $stop || 0 >= $entry['duration']) {
            $logger->warning(sprintf(
                '[toggl] Can not synchronize entry "%s" that started at %s because it still running.',
                $entry['description'],
                $entry['start'],
            ));

            return null;
        }

        try {
            $entryEntity = new Entry(
                (string) $entry['id'],
                $issueCode,
                trim($m['DESCRIPTION']),
                (new DateTimeImmutable($entry['start']))->setTimezone(new DateTimeZone('UTC')),
                $entry['duration'],
            );

            $logger->info(sprintf(
                '[toggl] Entry %s found.',
                $entryEntity,
            ));

            return $entryEntity;
        } catch (Throwable $e) {
            $logger->error(sprintf(
                '[toggl] Failed to create en entry from "%s". %s',
                $entry['description'],
                $e->getMessage(),
            ));
        }

        return null;
    }
}
