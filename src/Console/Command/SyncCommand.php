<?php

declare(strict_types=1);

namespace App\Console\Command;

use DateTimeZone;
use DateTimeImmutable;
use App\ValueObject\Range;
use App\ValueObject\Rounding;
use App\ValueObject\SyncMode;
use App\Synchronization\Options;
use App\Synchronization\SynchronizerInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Logger\ConsoleLogger;
use Symfony\Component\Console\Output\OutputInterface;

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
			->addOption('rounding', NULL, InputOption::VALUE_OPTIONAL, 'Rounds entries to up the minutes. The value must be an integer in the range [2-60]');
	}

	/**
	 * @throws \Exception
	 */
	protected function execute(InputInterface $input, OutputInterface $output): int
	{
		$logger = new ConsoleLogger($output);
		$rounding = $input->getOption('rounding');
		$options = new Options(
			new Range(
				(new DateTimeImmutable($input->getOption('start'), new DateTimeZone('UTC')))->setTime(0, 0),
				(new DateTimeImmutable($input->getOption('end'), new DateTimeZone('UTC')))->setTime(23, 59, 59),
			),
			$input->getOption('group-by-day') ? SyncMode::GROUP_BY_DAY : SyncMode::DEFAULT,
			NULL !== $rounding ? new Rounding((int) $rounding) : NULL
		);

		$this->synchronizer->sync($options, $logger);

		return 0;
	}
}
