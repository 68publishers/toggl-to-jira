<?php

declare(strict_types=1);

namespace App\Client\Jira;

use App\Client\WriteClientInterface;
use App\Exception\AbortException;
use App\ValueObject\Entry;
use App\ValueObject\Range;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Exception;
use GuzzleHttp\ClientInterface;
use JsonException;
use Psr\Log\LoggerInterface;
use Throwable;
use function array_filter;
use function array_key_exists;
use function array_map;
use function array_values;
use function assert;
use function base64_encode;
use function explode;
use function is_array;
use function is_string;
use function json_decode;
use function json_encode;
use function rtrim;
use function sprintf;
use function str_starts_with;
use function strlen;
use function substr;
use function trim;

final class JiraClient implements WriteClientInterface
{
    private readonly string $websiteUrl;

    /** @var array<string, mixed> */
    private array $runtimeCache = [];

    public function __construct(
        string $websiteUrl,
        private readonly string $username,
        private readonly string $apiToken,
        private readonly ClientInterface $client,
    ) {
        $this->websiteUrl = rtrim($websiteUrl, '/') . '/rest/api/3';
    }

    /**
     * @throws AbortException|Exception
     */
    public function listEntries(Range $range, array $issueCodes, LoggerInterface $logger): array
    {
        if (empty($issueCodes)) {
            throw new AbortException('[jira] Please provide an array of issue codes.');
        }

        $entries = [];
        $accountId = $this->fetchAccountId();

        foreach ($issueCodes as $issueCode) {
            $workLogs = $this->fetchWorkLog($issueCode, $range, $logger);

            foreach ($workLogs as $workLog) {
                $workLogStart = (new DateTimeImmutable($workLog->started))->setTimezone(new DateTimeZone('UTC'));

                if ($workLog->author->accountId !== $accountId || $workLogStart < $range->start || $workLogStart > $range->end) {
                    continue;
                }

                $issueTitle = (string) $this->fetchIssueTitle($issueCode, $logger);

                $entries[] = $entry = new Entry(
                    (string) $workLog->id,
                    $issueCode,
                    $issueTitle . ' {unknown description}', // dunno how to parse that shit
                    $workLogStart,
                    $workLog->timeSpentSeconds,
                );

                $logger->info(sprintf(
                    '[jira] Entry %s found.',
                    $entry,
                ));
            }
        }

        return $entries;
    }

    public function createEntry(Entry $entry, LoggerInterface $logger): bool
    {
        try {
            $issueTitle = (string) $this->fetchIssueTitle($entry->issue, $logger);

            $this->client->request('POST', $this->websiteUrl . '/issue/' . $entry->issue . '/worklog', [
                'headers' => $this->createHeaders(),
                'query' => [
                    'adjustEstimate' => 'auto',
                ],
                'body' => $this->createEntryBody($issueTitle, $entry),
            ]);

            $logger->info(sprintf(
                '[jira] Created a work log %s.',
                $entry,
            ));

            return true;
        } catch (Throwable $e) {
            $logger->error(sprintf(
                '[jira] Unable to create work log %s. %s',
                $entry,
                $e->getMessage(),
            ));

            return false;
        }
    }

    public function updateEntry(Entry $entry, LoggerInterface $logger): bool
    {
        if (null === $entry->id) {
            $logger->error(sprintf(
                '[jira] Unable to update a work log %s. Missing ID in an entry instance.',
                $entry,
            ));

            return false;
        }

        try {
            $issueTitle = (string) $this->fetchIssueTitle($entry->issue, $logger);

            $this->client->request('PUT', $this->websiteUrl . '/issue/' . $entry->issue . '/worklog/' . $entry->id, [
                'headers' => $this->createHeaders(),
                'query' => [
                    'adjustEstimate' => 'auto',
                ],
                'body' => $this->createEntryBody($issueTitle, $entry),
            ]);

            $logger->info(sprintf(
                '[jira] Updated a work log %s with ID %s.',
                $entry,
                $entry->id,
            ));

            return true;
        } catch (Throwable $e) {
            $logger->error(sprintf(
                '[jira] Unable to update a work log %s with ID %s. %s',
                $entry,
                $entry->id,
                $e->getMessage(),
            ));

            return false;
        }
    }

    public function deleteEntry(Entry $entry, LoggerInterface $logger): bool
    {
        if (null === $entry->id) {
            $logger->error(sprintf(
                '[jira] Unable to delete a work log %s. Missing ID in an entry instance.',
                $entry,
            ));

            return false;
        }

        try {
            $this->client->request('DELETE', $this->websiteUrl . '/issue/' . $entry->issue . '/worklog/' . $entry->id, [
                'headers' => $this->createHeaders(),
            ]);

            $logger->info(sprintf(
                '[jira] Deleted a work log with the ID %s.',
                $entry->id,
            ));

            return true;
        } catch (Throwable $e) {
            $logger->error(sprintf(
                '[jira] Unable to delete a work log %s with the ID %s. Aborting all operations for the issue at the date. %s',
                $entry,
                $entry->id,
                $e->getMessage(),
            ));

            return false;
        }
    }

    /**
     * @return array<int, object{id: string|int, timeSpentSeconds: int, started: string, author: object{accountId: string}}>
     */
    private function fetchWorkLog(string $issueCode, Range $range, LoggerInterface $logger): array
    {
        $workLogs = $this->hitCache('work-log-' . $issueCode . '-' . $range->start->format(DateTimeInterface::ATOM) . '-' . $range->end->format(DateTimeInterface::ATOM), function () use ($issueCode, $range, $logger) {
            try {
                $response = $this->client->request('GET', $this->websiteUrl . '/issue/' . $issueCode . '/worklog', [
                    'headers' => $this->createHeaders(),
                    'query' => [
                        'startedAfter' => ($range->start->getTimestamp() - 1) * 1000,
                        'startedBefore' => ($range->end->getTimestamp() + 1) * 1000,
                    ],
                ]);

                $data = json_decode($response->getBody()->getContents(), false, 512, JSON_THROW_ON_ERROR);

                return ($data?->worklogs) ?? [];
            } catch (Throwable $e) {
                $logger->error(sprintf(
                    '[jira] Can not fetch work logs for the issue %s. %s',
                    $issueCode,
                    $e->getMessage(),
                ));
            }

            return [];
        });

        assert(is_array($workLogs));

        return $workLogs;
    }

    private function fetchIssueTitle(string $issueCode, LoggerInterface $logger): ?string
    {
        $issueTitle = $this->hitCache('issue-title-' . $issueCode, function () use ($issueCode, $logger) {
            try {
                $response = $this->client->request('GET', $this->websiteUrl . '/issue/' . $issueCode, [
                    'headers' => $this->createHeaders(),
                    'query' => [
                        'fields' => 'summary',
                    ],
                ]);

                $data = json_decode($response->getBody()->getContents(), false, 512, JSON_THROW_ON_ERROR);

                return $data?->fields?->summary;
            } catch (Throwable $e) {
                $logger->error(sprintf(
                    '[jira] Can not fetch information about the issue %s. %s',
                    $issueCode,
                    $e->getMessage(),
                ));
            }

            return null;
        });

        assert(null === $issueTitle || is_string($issueTitle));

        return $issueTitle;
    }

    /**
     * @throws AbortException
     */
    private function fetchAccountId(): string
    {
        $accountId = $this->hitCache('account-id', function () {
            try {
                $response = $this->client->request('GET', $this->websiteUrl . '/myself', [
                    'headers' => $this->createHeaders(),
                ]);

                $data = json_decode($response->getBody()->getContents(), false, 512, JSON_THROW_ON_ERROR);
            } catch (Throwable $e) {
                throw new AbortException(sprintf(
                    '[jira] Can not fetch information about the current user. %s',
                    $e->getMessage(),
                ));
            }

            if (!isset($data->accountId)) {
                throw new AbortException('[jira] Can not fetch information about the current user.');
            }

            return $data->accountId;
        });

        assert(is_string($accountId));

        return $accountId;
    }

    /**
     * @throws JsonException
     */
    private function createEntryBody(string $issueTitle, Entry $entry): string
    {
        $lines = array_filter(
            array_map(
                static fn (string $line): string => trim(
                    str_starts_with($line, $issueTitle) ? substr($line, strlen($issueTitle)) : $line,
                ),
                explode("\n", $entry->description),
            ),
            static fn (string $line): bool => !empty($line),
        );

        $content = array_map(
            static fn (string $line): array => [
                'type' => 'paragraph',
                'content' => [
                    [
                        'text' => $line,
                        'type' => 'text',
                    ],
                ],
            ],
            empty($lines) ? [''] : $lines,
        );

        return json_encode([
            'timeSpentSeconds' => $entry->duration,
            'started' => $entry->start->format('Y-m-d\TH:i:s.vO'),
            'comment' => [
                'type' => 'doc',
                'version' => 1,
                'content' => array_values($content),
            ],
        ], JSON_THROW_ON_ERROR);
    }

    /**
     * @return array{Authorization: string, Accept: string, "Content-Type": string}
     */
    private function createHeaders(): array
    {
        return [
            'Authorization' => 'Basic ' . base64_encode($this->username . ':' . $this->apiToken),
            'Accept' => 'application/json',
            'Content-Type' => 'application/json',
        ];
    }

    private function hitCache(string $key, callable $cb): mixed
    {
        if (array_key_exists($key, $this->runtimeCache)) {
            return $this->runtimeCache[$key];
        }

        return $this->runtimeCache[$key] = $cb();
    }
}
