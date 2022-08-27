<?php

declare(strict_types=1);

namespace App\Client\Toggl;

use Throwable;
use DateTimeZone;
use DateTimeImmutable;
use App\ValueObject\Entry;
use App\ValueObject\Range;
use Psr\Log\LoggerInterface;
use GuzzleHttp\ClientInterface;
use App\Client\ReadClientInterface;

final class TogglClient implements ReadClientInterface
{
	private const URI = 'https://api.track.toggl.com/api/v9';

	public function __construct(
		private readonly string $apiToken,
		private readonly ClientInterface $client,
	) {
	}

	public function listEntries(Range $range, array $issueCodes, LoggerInterface $logger): array
	{
		// @todo Toggl API v9 doesn't accept DateTimes with a time, currently the only date can be passed.
		$startDate = $range->start->setTime(0, 0);
		$endDate = $range->end->setTime(0, 0)->modify('+1 day');

		try {
			$response = $this->client->get(self::URI . '/me/time_entries', [
				'auth' => [$this->apiToken, 'api_token'],
				'headers' => [
					'Content-Type' => 'application/json',
				],
				'query' => [
					'start_date' => $startDate->format('Y-m-d'),
					'end_date' => $endDate->format('Y-m-d'),
				],
			]);

			$documents = json_decode($response->getBody()->getContents(), FALSE, 512, JSON_THROW_ON_ERROR);
		} catch (Throwable $e) {
			$logger->error(sprintf(
				'[toggl] Unable to fetch entries from Toggl. %s',
				$e->getMessage()
			));

			return [];
		}

		return array_filter(
			array_map(
				function (object $entry) use ($issueCodes, $logger): ?Entry {
					return $this->createEntry($entry, $issueCodes, $logger);
				},
				$documents
			)
		);
	}

	private function createEntry(object $entry, array $issueCodes, LoggerInterface $logger): ?Entry
	{
		$parsed = FALSE !== (bool) preg_match('/^(?<ISSUE>[a-zA-Z]+-\d+)( +)?(?<DESCRIPTION>.*)?$/', $entry->description, $m);

		if (!$parsed || !isset($m['ISSUE'], $m['DESCRIPTION'])) {
			$logger->warning(sprintf(
				'[toggl] Can not synchronize entry "%s" from %s - %s. The entry description is not properly formatted.',
				$entry->description,
				$entry->start,
				$entry->stop ?? '?'
			));

			return NULL;
		}

		$issueCode = trim($m['ISSUE']);

		if (!empty($issueCodes) && !in_array($issueCode, $issueCodes, TRUE)) {
			return NULL;
		}

		if (NULL === $entry->stop || 0 >= $entry->duration) {
			$logger->warning(sprintf(
				'[toggl] Can not synchronize entry "%s" that started at %s because it still running.',
				$entry->description,
				$entry->start,
			));

			return NULL;
		}

		try {
			$entry = new Entry(
				(string) $entry->id,
				$issueCode,
				trim($m['DESCRIPTION']),
				(new DateTimeImmutable($entry->start))->setTimezone(new DateTimeZone('UTC')),
				$entry->duration
			);

			$logger->info(sprintf(
				'[toggl] Entry %s found.',
				$entry
			));

			return $entry;
		} catch (Throwable $e) {
			$logger->error(sprintf(
				'[toggl] Failed to create en entry from "%s". %s',
				$entry->description,
				$e->getMessage()
			));
		}

		return NULL;
	}
}
