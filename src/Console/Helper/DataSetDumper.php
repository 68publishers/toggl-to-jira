<?php

declare(strict_types=1);

namespace App\Console\Helper;

use App\Helper\DurationFormatter;
use App\Synchronization\DataSet;
use App\ValueObject\Entry;
use DateTimeImmutable;
use DateTimeZone;
use Exception;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Helper\TableCell;
use Symfony\Component\Console\Helper\TableCellStyle;
use Symfony\Component\Console\Helper\TableSeparator;
use Symfony\Component\Console\Output\OutputInterface;
use function array_map;
use function array_merge;
use function array_sum;
use function usort;

/**
 * @phpstan-type SortedDataSet array<string, array{day: DateTimeImmutable, source?: array<Entry>, destination?: array<Entry>, inserts?: array<Entry>, updates?: array<Entry>, deletes?: array<Entry>}>
 */
final class DataSetDumper
{
    public function __construct(
        private readonly OutputInterface $output,
    ) {}

    /**
     * @throws Exception
     */
    public function dump(DataSet $dataSet): void
    {
        $sorted = $this->sortDataSet($dataSet);

        $this->renderChangeSetTable($sorted);
        $this->renderSummaryTable($sorted);
    }

    /**
     * @param  SortedDataSet $sorted
     * @throws Exception
     */
    private function renderSummaryTable(array $sorted): void
    {
        $table = new Table($this->output);

        $table->setHeaderTitle('Summary');
        $table->setHeaders(['Day', 'Original duration', 'New duration', 'Difference']);

        if (empty($sorted)) {
            $table->addRow([
                new TableCell('No data', ['colspan' => 4]),
            ]);
        }

        foreach ($sorted as $data) {
            $originalDuration = array_sum(
                array_map(
                    static fn (Entry $entry): int => $entry->duration,
                    $data['destination'] ?? [],
                ),
            );
            $newDuration = array_sum(
                array_map(
                    static fn (Entry $entry): int => $entry->duration,
                    array_merge($data['inserts'] ?? [], $data['updates'] ?? []),
                ),
            );
            $difference = $newDuration - $originalDuration;

            switch (true) {
                case 0 < $difference:
                    $pre = '+';
                    $color = 'green';

                    break;
                case 0 > $difference:
                    $pre = '-';
                    $color = 'red';

                    break;
                default:
                    $pre = '';
                    $color = 'yellow';
            }

            $table->addRow([
                $data['day']->format('Y-m-d'),
                DurationFormatter::format($originalDuration),
                DurationFormatter::format($newDuration),
                new TableCell(
                    $pre . DurationFormatter::format(abs($difference)),
                    [
                        'style' => new TableCellStyle([
                            'fg' => $color,
                        ]),
                    ],
                ),
            ]);
        }

        $table->render();
    }

    /**
     * @param SortedDataSet $sorted
     */
    private function renderChangeSetTable(array $sorted): void
    {
        $table = new Table($this->output);

        $table->setHeaderTitle('Change set');
        $table->setHeaders(['Action', 'ID', 'Entry']);

        if (empty($sorted)) {
            $table->addRow([
                new TableCell('No data', ['colspan' => 3]),
            ]);
        }

        $first = true;

        foreach ($sorted as $data) {
            $dayRows = [
                [
                    new TableCell(
                        $data['day']->format('Y-m-d'),
                        [
                            'colspan' => 3,
                            'style' => new TableCellStyle([
                                'align' => 'center',
                            ]),
                        ],
                    ),
                ],
                new TableSeparator(),
            ];

            $table->addRows(!$first ? array_merge([new TableSeparator()], $dayRows) : $dayRows);

            $first = false;

            foreach ($data['inserts'] ?? [] as $entry) {
                $table->addRow([
                    new TableCell(
                        'insert',
                        [
                            'style' => new TableCellStyle([
                                'fg' => 'green',
                            ]),
                        ],
                    ),
                    (string) $entry->id,
                    $entry->toString(60),
                ]);
            }

            foreach ($data['updates'] ?? [] as $entry) {
                $table->addRow([
                    new TableCell(
                        'update',
                        [
                            'style' => new TableCellStyle([
                                'fg' => 'yellow',
                            ]),
                        ],
                    ),
                    (string) $entry->id,
                    $entry->toString(60),
                ]);
            }

            foreach ($data['deletes'] ?? [] as $entry) {
                $table->addRow([
                    new TableCell(
                        'delete',
                        [
                            'style' => new TableCellStyle([
                                'fg' => 'red',
                            ]),
                        ],
                    ),
                    (string) $entry->id,
                    $entry->toString(60),
                ]);
            }
        }

        $table->render();
    }

    /**
     * @return SortedDataSet
     * @throws Exception
     */
    private function sortDataSet(DataSet $dataSet): array
    {
        $sortedByDay = [];
        $getDay = static function (Entry $entry) use (&$sortedByDay): string {
            $day = $entry->start->format('Y-m-d');

            if (!isset($sortedByDay[$day])) {
                $sortedByDay[$day]['day'] = new DateTimeImmutable($day . ' 00:00:00', new DateTimeZone('UTC'));
            }

            return $day;
        };

        foreach ($dataSet->sourceEntries as $entry) {
            $sortedByDay[$getDay($entry)]['source'][] = $entry;
        }

        foreach ($dataSet->destinationEntries as $entry) {
            $sortedByDay[$getDay($entry)]['destination'][] = $entry;
        }

        foreach ($dataSet->diff->inserts as $entry) {
            $sortedByDay[$getDay($entry)]['inserts'][] = $entry;
        }

        foreach ($dataSet->diff->updates as $entry) {
            $sortedByDay[$getDay($entry)]['updates'][] = $entry;
        }

        foreach ($dataSet->diff->deletes as $entry) {
            $sortedByDay[$getDay($entry)]['deletes'][] = $entry;
        }

        usort($sortedByDay, static fn (array $a, array $b): int => $a['day'] <=> $b['day']);

        return $sortedByDay;
    }
}
