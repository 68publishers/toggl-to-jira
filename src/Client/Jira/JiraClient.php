<?php

declare(strict_types=1);

namespace App\Client\Jira;

use Throwable;
use DateTimeZone;
use DateTimeImmutable;
use DateTimeInterface;
use App\ValueObject\Entry;
use App\ValueObject\Range;
use Psr\Log\LoggerInterface;
use GuzzleHttp\ClientInterface;
use App\Exception\AbortException;
use JetBrains\PhpStorm\ArrayShape;
use App\Client\ReadClientInterface;
use App\Client\WriteClientInterface;

final class JiraClient implements WriteClientInterface, ReadClientInterface
{
	private readonly string $websiteUrl;

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
	 * @throws \Exception
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

				$entries[] = new Entry(
					(string) $workLog->id,
					$issueCode,
					'', // dunno how to parse that shit
					$workLogStart,
					$workLog->timeSpentSeconds
				);
			}
		}

		return $entries;
	}

	public function createEntry(Entry $entry, LoggerInterface $logger): void
	{
		try {
			$issueTitle = (string) $this->fetchIssueTitle($entry->issue, $logger);

			$this->client->post($this->websiteUrl . '/issue/' . $entry->issue . '/worklog', [
				'headers' => $this->createHeaders(),
				'query' => [
					'adjustEstimate' => 'auto',
				],
				'body' => $this->createEntryBody($issueTitle, $entry),
			]);

			$logger->info(sprintf(
				'[jira] Created a work log %s.',
				$entry
			));
		} catch (Throwable $e) {
			$logger->error(sprintf(
				'[jira] Unable to create work log %s. %s',
				$entry,
				$e->getMessage()
			));
		}
	}

	public function updateEntry(Entry $entry, LoggerInterface $logger): void
	{
		if (NULL === $entry->id) {
			$logger->error(sprintf(
				'[jira] Unable to update a work log %s. Missing ID in an entry instance.',
				$entry
			));

			return;
		}

		try {
			$issueTitle = (string) $this->fetchIssueTitle($entry->issue, $logger);

			$this->client->put($this->websiteUrl . '/issue/' . $entry->issue . '/worklog/' . $entry->id, [
				'headers' => $this->createHeaders(),
				'query' => [
					'adjustEstimate' => 'auto',
				],
				'body' => $this->createEntryBody($issueTitle, $entry),
			]);

			$logger->info(sprintf(
				'[jira] Updated a work log %s with ID %s.',
				$entry,
				$entry->id
			));
		} catch (Throwable $e) {
			$logger->error(sprintf(
				'[jira] Unable to update a work log %s with ID %s. %s',
				$entry,
				$entry->id,
				$e->getMessage()
			));
		}
	}

	public function deleteEntry(Entry $entry, LoggerInterface $logger): void
	{
		if (NULL === $entry->id) {
			$logger->error(sprintf(
				'[jira] Unable to delete a work log %s. Missing ID in an entry instance.',
				$entry
			));

			return;
		}

		try {
			$this->client->delete($this->websiteUrl . '/issue/' . $entry->issue . '/worklog/' . $entry->id, [
				'headers' => $this->createHeaders(),
			]);

			$logger->info(sprintf(
				'[jira] Deleted a work log with the ID %s.',
				$entry->id
			));
		} catch (Throwable $e) {
			$logger->error(sprintf(
				'[jira] Unable to delete a work log %s with the ID %s. Aborting all operations for the issue at the date. %s',
				$entry,
				$entry->id,
				$e->getMessage()
			));
		}
	}

	private function fetchWorkLog(string $issueCode, Range $range, LoggerInterface $logger): array
	{
		return $this->hitCache('work-log-' . $issueCode . '-' . $range->start->format(DateTimeInterface::ATOM) . '-' . $range->end->format(DateTimeInterface::ATOM), function () use ($issueCode, $range, $logger) {
			try {
				$response = $this->client->get($this->websiteUrl . '/issue/' . $issueCode . '/worklog', [
					'headers' => $this->createHeaders(),
					'query' => [
						'startedAfter' => ($range->start->getTimestamp() - 1) * 1000,
						'startedBefore' => ($range->end->getTimestamp() + 1) * 1000,
					],
				]);

				$data = json_decode($response->getBody()->getContents(), FALSE, 512, JSON_THROW_ON_ERROR);

				return ($data?->worklogs) ?? [];
			} catch (Throwable $e) {
				$logger->error(sprintf(
					'[jira] Can not fetch work logs for the issue %s. %s',
					$issueCode,
					$e->getMessage()
				));
			}

			return [];
		});
	}

	private function fetchIssueTitle(string $issueCode, LoggerInterface $logger): ?string
	{
		return $this->hitCache('issue-title-' . $issueCode, function () use ($issueCode, $logger) {
			try {
				$response = $this->client->get($this->websiteUrl . '/issue/' . $issueCode, [
					'headers' => $this->createHeaders(),
					'query' => [
						'fields' => 'summary',
					],
				]);

				$data = json_decode($response->getBody()->getContents(), FALSE, 512, JSON_THROW_ON_ERROR);

				return $data?->fields?->summary;
			} catch (Throwable $e) {
				$logger->error(sprintf(
					'[jira] Can not fetch information about the issue %s. %s',
					$issueCode,
					$e->getMessage()
				));
			}

			return NULL;
		});
	}

	/**
	 * @throws \App\Exception\AbortException
	 */
	private function fetchAccountId(): string
	{
		return $this->hitCache('account-id', function () {
			try {
				$response = $this->client->get($this->websiteUrl . '/myself', [
					'headers' => $this->createHeaders(),
				]);

				$data = json_decode($response->getBody()->getContents(), FALSE, 512, JSON_THROW_ON_ERROR);
			} catch (Throwable $e) {
				throw new AbortException(sprintf(
					'[jira] Can not fetch information about the current user. %s',
					$e->getMessage()
				));
			}

			if (!isset($data->accountId)) {
				throw new AbortException('[jira] Can not fetch information about the current user.');
			}

			return $data->accountId;
		});
	}

	/**
	 * @throws \JsonException
	 */
	private function createEntryBody(string $issueTitle, Entry $entry): string
	{
		$lines = array_filter(
			array_map(
				static fn (string $line): string => trim(
					str_starts_with($line, $issueTitle) ? substr($line, strlen($issueTitle)) : $line
				),
				explode("\n", $entry->description)
			),
			static fn (string $line): bool => !empty($line)
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
			empty($lines) ? [''] : $lines
		);

		return json_encode([
			'timeSpentSeconds' => $entry->duration,
			'started' => $entry->start->format('Y-m-d\TH:i:s.vO'),
			'comment' => [
				'type' => 'doc',
				'version' => 1,
				'content' => $content,
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

	private function hitCache(string $key, callable $cb)
	{
		if (array_key_exists($key, $this->runtimeCache)) {
			return $this->runtimeCache[$key];
		}

		return $this->runtimeCache[$key] = $cb();
	}
}
