<?php

declare(strict_types=1);

namespace App\Console\Command;

use DateTimeZone;
use Psr\Log\LogLevel;
use DateTimeImmutable;
use App\ValueObject\Range;
use App\ValueObject\Rounding;
use App\ValueObject\GroupMode;
use App\Synchronization\Options;
use App\Console\Helper\DataSetDumper;
use App\Synchronization\SynchronizerInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Logger\ConsoleLogger;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ConfirmationQuestion;

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
			->addOption('start', NULL, InputOption::VALUE_REQUIRED, 'Start date. The value must be a valid datetime string (absolute or relative)', 'yesterday')
			->addOption('end', NULL, InputOption::VALUE_REQUIRED, 'End date. The value must be a valid datetime string (absolute or relative)', 'yesterday')
			->addOption('group-by-day', NULL, InputOption::VALUE_NONE, 'Enables "group by day" synchronization mode')
			->addOption('rounding', NULL, InputOption::VALUE_OPTIONAL, 'Rounds entries to up the minutes. The value must be an integer in the range [2-60]')
			->addOption('issue', NULL, InputOption::VALUE_IS_ARRAY | InputOption::VALUE_REQUIRED, 'One or more issue codes. Only entries with the codes will be processed.')
			->addOption('dry-run', NULL, InputOption::VALUE_NONE, 'Dumps only change set without persisting it in the JIRA.');
	}

	/**
	 * @throws \Exception
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
			NULL !== $rounding ? new Rounding((int) $rounding) : NULL,
			$input->getOption('issue')
		);

		$dataSet = $this->synchronizer->generateDataSet($options, $logger);
		$dumper = new DataSetDumper($output);

		$dumper->dump($dataSet);

		if ($input->getOption('dry-run') || $dataSet->diff->empty()) {
			return Command::SUCCESS;
		}

		$helper = $this->getHelper('question');
		$question = new ConfirmationQuestion('Do you want to synchronize the changes? [y/n]: ', FALSE);

		if ($input->isInteractive() && !$helper->ask($input, $output, $question)) {
			return Command::SUCCESS;
		}

		$everythingSynced = $this->synchronizer->sync($dataSet, $logger);

		$output->writeln($everythingSynced ? 'Synchronization completed.' : 'Synchronization completed with errors.');

		return $everythingSynced ? Command::SUCCESS : Command::FAILURE;
	}
}
