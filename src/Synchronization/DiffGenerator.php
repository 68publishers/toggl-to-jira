<?php

declare(strict_types=1);

namespace App\Synchronization;

use App\ValueObject\Entry;
use App\ValueObject\GroupMode;
use App\ValueObject\Rounding;
use App\ValueObject\SyncMode;
use DateTimeImmutable;
use DateTimeInterface;
use Exception;
use function array_map;
use function array_unique;
use function assert;
use function count;
use function implode;
use function usort;

final class DiffGenerator implements DiffGeneratorInterface
{
    /**
     * @param array<Entry> $sourceEntries
     * @param array<Entry> $destinationEntries
     *
     * @throws Exception
     */
    public function diff(array $sourceEntries, array $destinationEntries, GroupMode $groupMode, SyncMode $syncMode, ?Rounding $rounding = null): Diff
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

        $diff = new Diff([], [], [], []);

        foreach ($sourceEntriesByDayAndIssue as $day => $sourceEntriesByIssue) {
            foreach ($sourceEntriesByIssue as $issue => $sEntries) {
                $exists =  isset($destinationEntriesByDayAndIssue[$day][$issue]);

                $diff = $diff->merge(
                    $this->diffByDayAndIssue($sEntries, $exists ? $destinationEntriesByDayAndIssue[$day][$issue] : [], $groupMode, $syncMode, $rounding),
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
                    $this->diffByDayAndIssue($exists ? $sourceEntriesByDayAndIssue[$day][$issue] : [], $dEntries, $groupMode, $syncMode, $rounding),
                );
            }
        }

        return $diff;
    }

    /**
     * @param array<Entry> $sourceEntries
     * @param array<Entry> $destinationEntries
     *
     * @throws Exception
     */
    private function diffByDayAndIssue(array $sourceEntries, array $destinationEntries, GroupMode $groupMode, SyncMode $syncMode, ?Rounding $rounding): Diff
    {
        $deletes = $updates = $intersections = [];

        usort($sourceEntries, static fn (Entry $a, Entry $b): int => $a->start <=> $b->start);

        if (GroupMode::GROUP_BY_DAY === $groupMode) {
            $sourceEntries = $this->groupSourceEntries($sourceEntries);
        }

        if (null !== $rounding) {
            $sourceEntries = $this->roundEntries($sourceEntries, $rounding);
        }

        if (SyncMode::APPEND === $syncMode) {
            return new Diff($sourceEntries, [], [], $destinationEntries);
        }

        usort($destinationEntries, static fn (Entry $a, Entry $b): int => $a->start <=> $b->start);

        foreach ($destinationEntries as $destinationEntry) {
            assert($destinationEntry instanceof Entry);

            $destinationEntryStartDateTime = $destinationEntry->start->format(DateTimeInterface::ATOM);

            foreach ($sourceEntries as $entryIndex => $sourceEntry) {
                assert($sourceEntry instanceof Entry);
                $sourceEntryStartDateTime = $sourceEntry->start->format(DateTimeInterface::ATOM);

                if ($sourceEntryStartDateTime === $destinationEntryStartDateTime) {
                    if ($sourceEntry->duration === $destinationEntry->duration) {
                        $intersections[] = $sourceEntry->withId($destinationEntry->id);
                    } else {
                        $updates[] = $sourceEntry->withId($destinationEntry->id);
                    }

                    unset($sourceEntries[$entryIndex]);

                    continue 2;
                }
            }

            $deletes[] = $destinationEntry;
        }

        return new Diff($sourceEntries, $updates, $deletes, $intersections);
    }

    /**
     * @param array<Entry> $entries
     *
     * @return array<Entry>
     */
    private function groupSourceEntries(array $entries): array
    {
        if (0 >= count($entries)) {
            return [];
        }

        $issue = null;
        $descriptions = [];
        $start = null;
        $duration = 0;

        foreach ($entries as $entry) {
            if (null === $issue) {
                $issue = $entry->issue;
                $descriptions[] = $entry->description;
                $start = $entry->start;
                $duration = $entry->duration;

                continue;
            }

            $descriptions[] = $entry->description;
            $duration += $entry->duration;
        }

        assert($start instanceof DateTimeImmutable);

        return [new Entry(null, $issue, implode("\n", array_unique($descriptions)), $start, $duration)];
    }

    /**
     * @param array<Entry> $entries
     *
     * @return array<Entry>
     * @throws Exception
     */
    private function roundEntries(array $entries, Rounding $rounding): array
    {
        return array_map(static fn (Entry $entry): Entry => $entry->withRoundedDuration($rounding), $entries);
    }
}
