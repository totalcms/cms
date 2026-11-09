<?php

declare(strict_types=1);

namespace TotalCMS\CLI\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\StreamableInputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use TotalCMS\Domain\Auth\Service\FirstLoginChecker;
use TotalCMS\Domain\Setup\Service\SetupStateManager;

/**
 * Creates the first admin account and marks setup finished, so an install
 * made by a tool (Total CMS Studio) opens ready to sign in instead of in the
 * setup wizard.
 *
 * The password is read from stdin and never accepted as an option, so it
 * does not show up in the process list or shell history.
 */
class SetupAdminCommand extends BaseCommand
{
	private const MIN_PASSWORD = 8;

	protected function configure(): void
	{
		parent::configure();
		$this
			->setName('setup:admin')
			->setDescription('Create the first admin account and finish setup')
			->addOption('email', null, InputOption::VALUE_REQUIRED, 'Email address for the admin account')
			->addOption('name', null, InputOption::VALUE_REQUIRED, 'Display name for the admin account', 'Admin')
			->addOption('password-stdin', null, InputOption::VALUE_NONE, 'Read the password from the first line of stdin');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int
	{
		$email = trim((string)$input->getOption('email'));
		$name  = trim((string)$input->getOption('name'));
		$name  = $name !== '' ? $name : 'Admin';

		if (!$input->getOption('password-stdin')) {
			return $this->outputError($input, $output, 'Pass the password on stdin with --password-stdin.');
		}
		if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
			return $this->outputError($input, $output, "That isn't a valid email address.");
		}

		$password = $this->readPassword($input);
		if (strlen($password) < self::MIN_PASSWORD) {
			return $this->outputError($input, $output, 'The password must be at least ' . self::MIN_PASSWORD . ' characters.');
		}

		$container = $this->totalcms->container();
		try {
			$container->get(FirstLoginChecker::class)->createFirstUser($email, $password, $name);
		} catch (\DomainException) {
			return $this->outputError($input, $output, 'This site already has an admin account. Sign in at /admin instead.');
		}
		$container->get(SetupStateManager::class)->completeAll();

		if ($this->isJson($input)) {
			$output->writeln((string)json_encode(['created' => true, 'email' => $email, 'name' => $name]));
		} else {
			$output->writeln("Created the admin account for {$email}. Setup is finished.");
		}

		return Command::SUCCESS;
	}

	/** The first line of stdin, without its line ending. */
	private function readPassword(InputInterface $input): string
	{
		$stream = $input instanceof StreamableInputInterface ? $input->getStream() : null;
		if (!is_resource($stream)) {
			$stream = defined('STDIN') ? STDIN : null;
		}
		$line = is_resource($stream) ? fgets($stream) : false;

		return $line === false ? '' : rtrim($line, "\r\n");
	}
}
