<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Security\Check;

use TotalCMS\Domain\Security\Data\SecurityContext;
use TotalCMS\Domain\Security\Data\SecurityFinding;
use TotalCMS\Domain\Security\Data\Severity;
use TotalCMS\Domain\Security\Service\SiteProbe;
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

	public function id(): string
	{
		return self::ID;
	}

	public function title(): string
	{
		return 'Debug mode';
	}

	public function run(SecurityContext $context): array
	{
		if (!$this->config->debug) {
			return [SecurityFinding::ok(self::ID, 'Debug mode is off')];
		}
		// Whether debug on is a problem depends on the host being live or local.
		if ($context->hostUnknown()) {
			return [SecurityFinding::skipped(self::ID, $this->title(), SiteProbe::NO_URL)];
		}
		if ($context->isLocalHost()) {
			return [SecurityFinding::ok(self::ID, 'Debug mode is on, which is fine for a local site')];
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
