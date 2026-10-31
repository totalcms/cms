<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Security\Check;

use TotalCMS\Domain\Security\Data\SecurityContext;
use TotalCMS\Domain\Security\Data\SecurityFinding;
use TotalCMS\Domain\Security\Data\Severity;
use TotalCMS\Domain\Security\Service\SiteProbe;
use TotalCMS\Support\Config;

class DataFolderPublicCheck implements SecurityCheck
{
	private const ID    = 'data-folder-public';
	private const TITLE = 'Data folder is reachable from the web';
	private const DOCS  = 'operations/security#data-folder-public';

	private readonly CanaryProbe $canary;

	public function __construct(
		private readonly Config $config,
		SiteProbe $probe,
	) {
		$this->canary = new CanaryProbe($probe);
	}

	public function makesRequests(): bool
	{
		return true;
	}

	public function run(SecurityContext $context): array
	{
		[$result, $path] = $this->canary->test($context, $this->config->datadir);

		return [match ($result) {
			CanaryProbe::OUTSIDE     => SecurityFinding::ok(self::ID, 'The data folder is outside the public web folder'),
			CanaryProbe::MISSING     => SecurityFinding::ok(self::ID, 'The data folder doesn\'t exist yet, so there is nothing to check'),
			CanaryProbe::NO_URL      => SecurityFinding::skipped(self::ID, self::TITLE, SiteProbe::NO_URL),
			CanaryProbe::NO_WRITE    => SecurityFinding::skipped(self::ID, self::TITLE, 'Total CMS couldn\'t write a test file into the data folder, so this wasn\'t checked.'),
			CanaryProbe::UNREACHABLE => SecurityFinding::skipped(self::ID, self::TITLE, SiteProbe::UNREACHABLE),
			CanaryProbe::EXPOSED     => SecurityFinding::found(
				self::ID,
				Severity::Critical,
				self::TITLE,
				'A test file in the data folder could be downloaded from ' . CanaryProbe::folderPath($path) . '/. Anyone can read your content, users and settings.',
				ServerRuleFix::denyFolder($context, CanaryProbe::folderPath($path)),
				self::DOCS,
			),
			default => SecurityFinding::found(
				self::ID,
				$context->isApache() ? Severity::Info : Severity::Warning,
				'Data folder is inside the public web folder',
				'The data folder is inside the docroot and is protected only by a server rule. If that rule stops working it becomes public.',
				'Move the data folder above the public web folder and update `datadir` in config/tcms.php. ' . ServerRuleFix::denyFolder($context, CanaryProbe::folderPath($path)),
				self::DOCS,
			),
		}];
	}
}
