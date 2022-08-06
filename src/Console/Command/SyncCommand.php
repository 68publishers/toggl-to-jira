<?php

declare(strict_types=1);

namespace App\Console\Command;

use DateTimeZone;
use DateTimeImmutable;
use App\ValueObject\Range;
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
			->addOption('start', NULL, InputOption::VALUE_OPTIONAL, 'Start date', 'yesterday')
			->addOption('end', NULL, InputOption::VALUE_OPTIONAL, 'End date', 'yesterday')
			->addOption('overwrite', NULL, InputOption::VALUE_NONE, 'Enables overwrite synchronization mode');
	}

	/**
	 * @throws \Exception
	 */
	protected function execute(InputInterface $input, OutputInterface $output): int
	{
		$logger = new ConsoleLogger($output);
		$options = new Options(
			new Range(
				(new DateTimeImmutable($input->getOption('start'), new DateTimeZone('UTC')))->setTime(0, 0),
				(new DateTimeImmutable($input->getOption('end'), new DateTimeZone('UTC')))->setTime(23, 59, 59),
			),
			$input->getOption('overwrite') ? SyncMode::OVERWRITE : SyncMode::DEFAULT
		);

		$this->synchronizer->sync($options, $logger);

		return 0;
	}
}
