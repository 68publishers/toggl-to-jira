<?php

declare(strict_types=1);

namespace App\Client\Jira;

use Throwable;
use DateTimeZone;
use DateTimeImmutable;
use DateTimeInterface;
use App\ValueObject\Entry;
use Psr\Log\LoggerInterface;
use App\ValueObject\SyncMode;
use GuzzleHttp\ClientInterface;
use JetBrains\PhpStorm\ArrayShape;
use App\Client\WriteClientInterface;

final class JiraClient implements WriteClientInterface
{
	private readonly string $websiteUrl;

	public function __construct(
		string $websiteUrl,
		private readonly string $username,
		private readonly string $apiToken,
		private readonly ClientInterface $client,
	) {
		$this->websiteUrl = rtrim($websiteUrl, '/') . '/rest/api/3';
	}

	/**
	 * @throws \Exception
	 */
	public function writeEntries(array $entries, SyncMode $syncMode, LoggerInterface $logger): void
	{
		if (empty($entries)) {
			$logger->info('[jira] Nothing to import.');

			return;
		}

		$accountId = $this->fetchAccountId($logger);

		if (NULL === $accountId) {
			return;
		}

		$issues = array_fill_keys(array_unique(array_map(static fn (Entry $entry): string => $entry->issue, $entries)), NULL);

		foreach (array_keys($issues) as $issueCode) {
			$issues[$issueCode] = $this->fetchIssue($issueCode, $logger);
		}

		$entriesPerIssueAndDay = [];

		foreach ($entries as $entry) {
			assert($entry instanceof Entry);

			if (!isset($issues[$entry->issue])) {
				$logger->error(sprintf(
					'[jira] Can not import an entry %s because of missing information about the issue "%s".',
					$entry,
					$entry->issue
				));

				continue;
			}
			
			$entriesPerIssueAndDay[$entry->issue][$entry->start->format('Y-m-d')][] = $entry;
		}

		foreach ($entriesPerIssueAndDay as $issueCode => $entriesPerDay) {
			foreach ($entriesPerDay as $day => $ent) {
				$this->importEntriesPerDay($ent, $issueCode, $issues[$issueCode], $day, $accountId, $syncMode, $logger);
			}
		}
	}

	/**
	 * @throws \Exception
	 */
	private function importEntriesPerDay(array $entries, string $issueCode, object $issue, string $startDateString, string $accountId, SyncMode $syncMode, LoggerInterface $logger): void
	{
		$deletes = $updates = [];
		$remainingEntries = $entries;
		$workLogs = $issue->worklog->worklogs;

		foreach ($workLogs as $workLog) {
			$workLogStart = (new DateTimeImmutable($workLog->started))->setTimezone(new DateTimeZone('UTC'));
			$workLogStartDate = $workLogStart->format('Y-m-d');

			if ($workLogStartDate !== $startDateString || $workLog->author->accountId !== $accountId) {
				continue;
			}

			if ($syncMode === SyncMode::OVERWRITE) {
				$deletes[$workLog->id] = TRUE;

				continue;
			}

			$workLogStartDateTime = $workLogStart->format(DateTimeInterface::ATOM);

			foreach ($remainingEntries as $entryIndex => $remainingEntry) {
				assert($remainingEntry instanceof Entry);
				$entryStartDateTime = $remainingEntry->start->format(DateTimeInterface::ATOM);

				if ($entryStartDateTime === $workLogStartDateTime) {
					$updates[$workLog->id] = $remainingEntry;
					unset($remainingEntries[$entryIndex]);

					continue 2;
				}
			}
		}

		foreach (array_keys($deletes) as $workLogId) {
			try {
				$this->client->delete($this->websiteUrl . '/issue/' . $issueCode . '/worklog/' . $workLogId, [
					'headers' => $this->createHeaders(),
				]);

				$logger->info(sprintf(
					'[jira] Deleted a work log with the ID %s.',
					$workLogId
				));
			} catch (Throwable $e) {
				$logger->error(sprintf(
					'[jira] Unable to delete a work log with the ID %s for the issue %s at %s. Aborting all operations for the issue at the date. %s',
					$workLogId,
					$issueCode,
					$startDateString,
					$e->getMessage()
				));

				return;
			}
		}

		foreach ($updates as $workLogId => $entry) {
			try {
				$this->client->put($this->websiteUrl . '/issue/' . $issueCode . '/worklog/' . $workLogId, [
					'headers' => $this->createHeaders(),
					'query' => [
						'adjustEstimate' => 'auto',
					],
					'body' => $this->createEntryBody($issue->summary, $entry),
				]);

				$logger->info(sprintf(
					'[jira] Updated a work log %s an entry %s.',
					$workLogId,
					$entry
				));
			} catch (Throwable $e) {
				$logger->error(sprintf(
					'[jira] Unable to update a work log %s with an entry %s. %s',
					$workLogId,
					$entry,
					$e->getMessage()
				));

				return;
			}
		}

		foreach ($remainingEntries as $entry) {
			assert($entry instanceof Entry);

			try {
				$this->client->post($this->websiteUrl . '/issue/' . $issueCode . '/worklog', [
					'headers' => $this->createHeaders(),
					'query' => [
						'adjustEstimate' => 'auto',
					],
					'body' => $this->createEntryBody($issue->summary, $entry),
				]);

				$logger->info(sprintf(
					'[jira] Created a work log for an entry %s.',
					$entry
				));
			} catch (Throwable $e) {
				$logger->error(sprintf(
					'[jira] Unable to create work log for an entry %s. %s',
					$entry,
					$e->getMessage()
				));

				return;
			}
		}
	}

	private function fetchIssue(string $issueCode, LoggerInterface $logger): ?object
	{
		try {
			$response = $this->client->get($this->websiteUrl . '/issue/' . $issueCode, [
				'headers' => $this->createHeaders(),
				'query' => [
					'fields' => 'summary,worklog',
				],
			]);

			$data = json_decode($response->getBody()->getContents(), FALSE, 512, JSON_THROW_ON_ERROR);

			return $data->fields ?? NULL;
		} catch (Throwable $e) {
			$logger->error(sprintf(
				'[jira] Can not fetch information about the issue %s. %s',
				$issueCode,
				$e->getMessage()
			));
		}

		return NULL;
	}

	private function fetchAccountId(LoggerInterface $logger): ?string
	{
		try {
			$response = $this->client->get($this->websiteUrl . '/myself', [
				'headers' => $this->createHeaders(),
			]);

			$data = json_decode($response->getBody()->getContents(), FALSE, 512, JSON_THROW_ON_ERROR);
		} catch (Throwable $e) {
			$logger->error(sprintf(
				'[jira] Can not fetch information about the current user. %s',
				$e->getMessage()
			));
		}

		return isset($data, $data->accountId) ? $data->accountId : NULL;
	}

	/**
	 * @throws \JsonException
	 */
	private function createEntryBody(string $issueTitle, Entry $entry): string
	{
		$comment = trim(
			str_starts_with($entry->description, $issueTitle) ? substr($entry->description, strlen($issueTitle)) : $entry->description
		);

		return json_encode([
			'timeSpentSeconds' => $entry->duration,
			'started' => $entry->start->format('Y-m-d\TH:i:s.vO'),
			'comment' => [
				'type' => 'doc',
				'version' => 1,
				'content' => [
					[
						'type' => 'paragraph',
						'content' => [
							[
								'text' => $comment,
								'type' => 'text',
							],
						],
					],
				],
			],
		], JSON_THROW_ON_ERROR);
	}

	#[ArrayShape(['Authorization' => "string", 'Accept' => "string", 'Content-Type' => "string"])] private function createHeaders(): array
	{
		return [
			'Authorization' => 'Basic ' . base64_encode($this->username . ':' . $this->apiToken),
			'Accept' => 'application/json',
			'Content-Type' => 'application/json',
		];
	}
}
