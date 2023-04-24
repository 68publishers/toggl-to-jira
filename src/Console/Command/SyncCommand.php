<?php

declare(strict_types=1);

namespace App\Console\Command;

use App\Console\Helper\DataSetDumper;
use App\Synchronization\Options;
use App\Synchronization\SynchronizerInterface;
use App\ValueObject\GroupMode;
use App\ValueObject\Range;
use App\ValueObject\Rounding;
use DateTimeImmutable;
use DateTimeZone;
use Exception;
use Psr\Log\LogLevel;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Logger\ConsoleLogger;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ConfirmationQuestion;
use function assert;

final class SyncCommand extends Command
{
    protected static $defaultName = 'sync';

    public function __construct(
        private readonly SynchronizerInterface $synchronizer,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setDescription('Synchronizes entries from the Toggl to the JIRA.')
            ->addOption('start', null, InputOption::VALUE_REQUIRED, 'Start date. The value must be a valid datetime string (absolute or relative)', 'yesterday')
            ->addOption('end', null, InputOption::VALUE_REQUIRED, 'End date. The value must be a valid datetime string (absolute or relative)', 'yesterday')
            ->addOption('group-by-day', null, InputOption::VALUE_NONE, 'Enables "group by day" synchronization mode')
            ->addOption('rounding', null, InputOption::VALUE_OPTIONAL, 'Rounds entries to up the minutes. The value must be an integer in the range [2-60]')
            ->addOption('issue', null, InputOption::VALUE_IS_ARRAY | InputOption::VALUE_REQUIRED, 'One or more issue codes. Only entries with the codes will be processed.')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Dumps only change set without persisting it in the JIRA.');
    }

    /**
     * @throws Exception
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $logger = new ConsoleLogger($output, [], [
            LogLevel::WARNING => 'comment',
        ]);
        $rounding = $input->getOption('rounding');
        $options = new Options(
            new Range(
                (new DateTimeImmutable($input->getOption('start'), new DateTimeZone('UTC')))->setTime(0, 0),
                (new DateTimeImmutable($input->getOption('end'), new DateTimeZone('UTC')))->setTime(23, 59, 59),
            ),
            $input->getOption('group-by-day') ? GroupMode::GROUP_BY_DAY : GroupMode::DEFAULT,
            null !== $rounding ? new Rounding((int) $rounding) : null,
            $input->getOption('issue'),
        );

        $dataSet = $this->synchronizer->generateDataSet($options, $logger);
        $dumper = new DataSetDumper($output);

        $dumper->dump($dataSet);

        if ($input->getOption('dry-run') || $dataSet->diff->empty()) {
            return Command::SUCCESS;
        }

        $helper = $this->getHelper('question');
        $question = new ConfirmationQuestion('Do you want to synchronize the changes? [y/n]: ', false);
        assert($helper instanceof QuestionHelper);

        if ($input->isInteractive() && !$helper->ask($input, $output, $question)) {
            return Command::SUCCESS;
        }

        $everythingSynced = $this->synchronizer->sync($dataSet, $logger);

        $output->writeln($everythingSynced ? 'Synchronization completed.' : 'Synchronization completed with errors.');

        return $everythingSynced ? Command::SUCCESS : Command::FAILURE;
    }
}
