<?php

declare(strict_types=1);

namespace App\Synchronization;

use DateTimeInterface;
use App\ValueObject\Entry;
use App\ValueObject\GroupMode;

final class DiffGenerator implements DiffGeneratorInterface
{
	public function diff(array $sourceEntries, array $destinationEntries, GroupMode $mode): Diff
	{
		$sourceEntriesByDayAndIssue = $destinationEntriesByDayAndIssue = [];

		foreach ($sourceEntries as $entry) {
			$day = $entry->start->format('Y-m-d');
			$sourceEntriesByDayAndIssue[$entry->issue][$day][] = $entry;
		}

		foreach ($destinationEntries as $entry) {
			$day = $entry->start->format('Y-m-d');
			$destinationEntriesByDayAndIssue[$entry->issue][$day][] = $entry;
		}

		$diff = new Diff([], [], []);

		foreach ($sourceEntriesByDayAndIssue as $day => $sourceEntriesByIssue) {
			foreach ($sourceEntriesByIssue as $issue => $sEntries) {
				$exists =  isset($destinationEntriesByDayAndIssue[$day][$issue]);

				$diff = $diff->merge(
					$this->diffByDayAndIssue($sEntries, $exists ? $destinationEntriesByDayAndIssue[$day][$issue] : [], $mode)
				);

				unset($sourceEntriesByDayAndIssue[$day][$issue]);

				if ($exists) {
					unset($destinationEntriesByDayAndIssue[$day][$issue]);
				}
			}
		}

		foreach ($destinationEntriesByDayAndIssue as $day => $destinationEntriesByIssue) {
			foreach ($destinationEntriesByIssue as $issue => $dEntries) {
				$exists =  isset($sourceEntriesByDayAndIssue[$day][$issue]);

				$diff = $diff->merge(
					$this->diffByDayAndIssue($exists ? $sourceEntriesByDayAndIssue[$day][$issue] : [], $dEntries, $mode)
				);
			}
		}

		return $diff;
	}

	private function diffByDayAndIssue(array $sourceEntries, array $destinationEntries, GroupMode $mode): Diff
	{
		$deletes = $updates = [];

		usort($sourceEntries, static fn (Entry $a, Entry $b): int => $a->start <=> $b->start);
		usort($destinationEntries, static fn (Entry $a, Entry $b): int => $a->start <=> $b->start);

		if (GroupMode::GROUP_BY_DAY === $mode) {
			$sourceEntries = $this->groupSourceEntries($sourceEntries);
		}

		foreach ($destinationEntries as $destinationEntry) {
			assert($destinationEntry instanceof Entry);

			$destinationEntryStartDateTime = $destinationEntry->start->format(DateTimeInterface::ATOM);

			foreach ($sourceEntries as $entryIndex => $sourceEntry) {
				assert($sourceEntry instanceof Entry);
				$sourceEntryStartDateTime = $sourceEntry->start->format(DateTimeInterface::ATOM);

				if ($sourceEntryStartDateTime === $destinationEntryStartDateTime) {
					$updates[] = $sourceEntry->withId($destinationEntry->id);
					unset($sourceEntries[$entryIndex]);

					continue 2;
				}
			}

			$deletes[] = $destinationEntry;
		}

		return new Diff($sourceEntries, $updates, $deletes);
	}

	private function groupSourceEntries(array $entries): array
	{
		if (0 >= count($entries)) {
			return [];
		}

		$issue = NULL;
		$description = '';
		$start = NULL;
		$duration = 0;

		foreach ($entries as $entry) {
			assert($entry instanceof Entry);

			if (NULL === $issue) {
				$issue = $entry->issue;
				$description = $entry->description;
				$start = $entry->start;
				$duration = $entry->duration;

				continue;
			}

			$description .= "\n" . $entry->description;
			$duration += $entry->duration;
		}

		return [new Entry(NULL, $issue, $description, $start, $duration)];
	}
}
