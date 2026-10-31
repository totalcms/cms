<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Security\Check;

use TotalCMS\Domain\Security\Data\SecurityContext;
use TotalCMS\Domain\Security\Data\SecurityFinding;
use TotalCMS\Domain\Security\Data\Severity;
use TotalCMS\Domain\Security\Service\SiteProbe;

class NoHttpsCheck implements SecurityCheck
{
	private const ID    = 'no-https';
	private const TITLE = 'The site is not served over HTTPS';
	private const DOCS  = 'operations/security#no-https';

	public function makesRequests(): bool
	{
		return false;
	}

	public function run(SecurityContext $context): array
	{
		if ($context->baseUrl === '') {
			return [SecurityFinding::skipped(self::ID, self::TITLE, SiteProbe::NO_URL)];
		}

		if ($context->https || $context->isLocalHost()) {
			return [SecurityFinding::ok(self::ID, 'The site is served over HTTPS')];
		}

		return [SecurityFinding::found(
			self::ID,
			Severity::Warning,
			self::TITLE,
			'Logins and form data travel unencrypted.',
			'Install a certificate (most hosts offer a free one) and redirect HTTP to HTTPS.',
			self::DOCS,
		)];
	}
}
