<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Security\Check;

use TotalCMS\Domain\Cache\Service\DevModeManager;
use TotalCMS\Domain\Security\Data\SecurityContext;
use TotalCMS\Domain\Security\Data\SecurityFinding;
use TotalCMS\Domain\Security\Data\Severity;

class DevModeOnCheck implements SecurityCheck
{
	private const ID   = 'dev-mode-on';
	private const DOCS = 'operations/security#dev-mode-on';

	public function __construct(private readonly DevModeManager $devMode)
	{
	}

	public function makesRequests(): bool
	{
		return false;
	}

	public function run(SecurityContext $context): array
	{
		if ($context->isLocalHost() || !$this->devMode->isDevModeActive()) {
			return [SecurityFinding::ok(self::ID, 'Dev mode is off')];
		}

		$minutes = max(1, (int)ceil($this->devMode->getRemainingTime() / 60));

		return [SecurityFinding::found(
			self::ID,
			Severity::Info,
			'Dev mode is on',
			sprintf('Dev mode turns caching off and expires in about %d minutes.', $minutes),
			'Turn dev mode off in the admin when you finish testing.',
			self::DOCS,
		)];
	}
}
