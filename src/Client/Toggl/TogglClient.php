<?php

declare(strict_types=1);

namespace App\Client\Toggl;

use App\Client\ReadClientInterface;
use App\Exception\AbortException;
use App\ValueObject\Entry;
use App\ValueObject\Filter;
use App\ValueObject\Range;
use DateTimeImmutable;
use DateTimeZone;
use GuzzleHttp\ClientInterface;
use Nette\Utils\Strings;
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
    public const FILTER_NAME_ISSUE_CODE = 'issueCode';
    public const FILTER_NAME_WORKSPACE_ID = 'workspaceId';
    public const FILTER_NAME_WORKSPACE_NAME = 'workspaceName';
    public const FILTER_NAME_PROJECT_ID = 'projectId';
    public const FILTER_NAME_PROJECT_NAME = 'projectName';

    public const SUPPORTED_FILTERS = [
        self::FILTER_NAME_ISSUE_CODE,
        self::FILTER_NAME_WORKSPACE_ID,
        self::FILTER_NAME_WORKSPACE_NAME,
        self::FILTER_NAME_PROJECT_ID,
        self::FILTER_NAME_PROJECT_NAME,
    ];

    private const URI = 'https://api.track.toggl.com/api/v9';

    public function __construct(
        private readonly string $apiToken,
        private readonly ClientInterface $client,
    ) {}

    public function listEntries(Range $range, array $filters, LoggerInterface $logger): array
    {
        $filters = $this->prepareFilters($filters);

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
                function (array $entry) use ($filters, $logger): ?Entry {
                    assert(
                        isset($entry['id'], $entry['description'], $entry['start'], $entry['duration'])
                        && is_int($entry['id'])
                        && is_string($entry['description'])
                        && is_string($entry['start'])
                        && (!isset($entry['stop']) || is_string($entry['stop']))
                        && is_int($entry['duration'])
                        && (null === $entry['project_id'] || is_int($entry['project_id']))
                        && is_int($entry['workspace_id']),
                    );

                    return $this->createEntry($entry, $filters, $logger);
                },
                $documents,
            ),
        );
    }

    /**
     * @param array{id: int, description: string, start: string, stop?: string, duration: int, project_id: int|null, workspace_id: int} $entry
     * @param array<string, array<int, Filter>>                                                                                         $filters
     */
    private function createEntry(array $entry, array $filters, LoggerInterface $logger): ?Entry
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

        foreach ($filters as $listOfFilters) {
            foreach ($listOfFilters as $filter) {
                $isValid = match ($filter->name) {
                    self::FILTER_NAME_ISSUE_CODE => $issueCode === $filter->value,
                    self::FILTER_NAME_WORKSPACE_ID => $entry['workspace_id'] === $filter->value,
                    self::FILTER_NAME_PROJECT_ID => $entry['project_id'] === $filter->value,
                    default => true,
                };

                if ($isValid) {
                    continue 2;
                }
            }

            $logger->info(sprintf(
                '[toggl] Entry "%s" has been filtered.',
                $entry['description'],
            ));

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

    /**
     * @param array<int, Filter> $filters
     *
     * @return array<string, array<int, Filter>>
     * @throws AbortException
     */
    private function prepareFilters(array $filters): array
    {
        $filtersByName = [];

        foreach ($filters as $filter) {
            if (!in_array($filter->name, self::SUPPORTED_FILTERS, true)) {
                throw new AbortException(sprintf(
                    '[toggl] Filter "%s" is not supported',
                    $filter,
                ));
            }

            if (!isset($filtersByName[$filter->name])) {
                $filtersByName[$filter->name] = [];
            }

            $filtersByName[$filter->name][] = $filter;
        }

        $outputFiltersByName = [];

        foreach ($filtersByName as $filterName => $filters) {
            $filters = match ($filterName) {
                self::FILTER_NAME_WORKSPACE_ID, self::FILTER_NAME_PROJECT_ID => $this->prepareNumericFilters($filters),
                self::FILTER_NAME_WORKSPACE_NAME => $this->prepareWorkspaceNameFilters($filters),
                self::FILTER_NAME_PROJECT_NAME => $this->prepareProjectNameFilters($filters),
                default => $filters,
            };

            foreach ($filters as $filter) {
                if (!isset($outputFiltersByName[$filter->name])) {
                    $outputFiltersByName[$filter->name] = [];
                }

                $outputFiltersByName[$filter->name][] = $filter;
            }
        }

        return $outputFiltersByName;
    }

    /**
     * @param array<int, Filter> $filters
     *
     * @return array<int, Filter>
     */
    private function prepareNumericFilters(array $filters): array
    {
        return array_map(
            static fn (Filter $filter): Filter => $filter->withCastedValue('int'),
            $filters,
        );
    }

    /**
     * @param array<int, Filter> $filters
     *
     * @return array<int, Filter>
     * @throws AbortException
     */
    private function prepareWorkspaceNameFilters(array $filters): array
    {
        try {
            $response = $this->client->request('GET', self::URI . '/me/workspaces', [
                'auth' => [$this->apiToken, 'api_token'],
                'headers' => [
                    'Content-Type' => 'application/json',
                ],
            ]);

            $workspaces = json_decode($response->getBody()->getContents(), true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable $e) {
            throw new AbortException(sprintf(
                '[toggl] Unable to fetch workspaces from Toggl. %s',
                $e->getMessage(),
            ), 0, $e);
        }

        return array_map(
            static function (Filter $filter) use ($workspaces): Filter {
                foreach ($workspaces as $workspace) {
                    if (Strings::lower($workspace['name']) === Strings::lower($filter->value)) {
                        return new Filter(
                            name: self::FILTER_NAME_WORKSPACE_ID,
                            value: $workspace['id'],
                        );
                    }
                }

                throw new AbortException(sprintf(
                    '[toggl] Unable to evaluate filter "%s". The workspace not found.',
                    $filter,
                ));
            },
            $filters,
        );
    }

    /**
     * @param array<int, Filter> $filters
     *
     * @return array<int, Filter>
     * @throws AbortException
     */
    private function prepareProjectNameFilters(array $filters): array
    {
        try {
            $response = $this->client->request('GET', self::URI . '/me/projects', [
                'auth' => [$this->apiToken, 'api_token'],
                'headers' => [
                    'Content-Type' => 'application/json',
                ],
            ]);

            $projects = json_decode($response->getBody()->getContents(), true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable $e) {
            throw new AbortException(sprintf(
                '[toggl] Unable to fetch projects from Toggl. %s',
                $e->getMessage(),
            ), 0, $e);
        }

        return array_map(
            static function (Filter $filter) use ($projects): Filter {
                foreach ($projects as $project) {
                    if (Strings::lower($project['name']) === Strings::lower($filter->value)) {
                        return new Filter(
                            name: self::FILTER_NAME_PROJECT_ID,
                            value: $project['id'],
                        );
                    }
                }

                throw new AbortException(sprintf(
                    '[toggl] Unable to evaluate filter "%s". The project not found.',
                    $filter,
                ));
            },
            $filters,
        );
    }
}
