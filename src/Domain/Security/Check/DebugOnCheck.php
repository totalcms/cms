<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Security\Check;

use TotalCMS\Domain\Security\Data\SecurityContext;
use TotalCMS\Domain\Security\Data\SecurityFinding;
use TotalCMS\Domain\Security\Data\Severity;
use TotalCMS\Support\Config;

class DebugOnCheck implements SecurityCheck
{
	private const ID    = 'debug-on';
	private const DOCS  = 'operations/security#debug-on';

	public function __construct(private readonly Config $config)
	{
	}

	public function makesRequests(): bool
	{
		return false;
	}

	public function run(SecurityContext $context): array
	{
		if (!$this->config->debug || $context->isLocalHost()) {
			return [SecurityFinding::ok(self::ID, 'Debug mode is off')];
		}

		return [SecurityFinding::found(
			self::ID,
			Severity::Warning,
			'Debug mode is on',
			'The `debug` setting is on for a live site. Error pages can show file paths and code to visitors.',
			'Set `debug` to false in config/tcms.php.',
			self::DOCS,
		)];
	}
}
