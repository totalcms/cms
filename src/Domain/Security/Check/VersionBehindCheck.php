<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Security\Check;

use Throwable;
use TotalCMS\Domain\Security\Data\SecurityContext;
use TotalCMS\Domain\Security\Data\SecurityFinding;
use TotalCMS\Domain\Security\Data\Severity;
use TotalCMS\Domain\Update\Service\UpdateChecker;

class VersionBehindCheck implements SecurityCheck
{
	private const ID    = 'version-behind';
	private const TITLE = 'A newer version is available';
	private const DOCS  = 'operations/security#version-behind';

	public function __construct(private readonly UpdateChecker $updates)
	{
	}

	/** The update checker caches its lookups, but a cold cache makes a live request (up to 5 s). */
	public function makesRequests(): bool
	{
		return true;
	}

	public function run(SecurityContext $context): array
	{
		try {
			$info = $this->updates->checkForUpdate(false);
		} catch (Throwable) {
			return [SecurityFinding::skipped(self::ID, self::TITLE, 'The update check failed, so the version was not compared.')];
		}

		if (!$info->available) {
			return [SecurityFinding::ok(self::ID, 'No newer version was reported')];
		}

		return [SecurityFinding::found(
			self::ID,
			Severity::Warning,
			self::TITLE,
			sprintf('Version %s is available. Updates carry security fixes.', $info->version),
			'Update from the admin, or run `tcms update:apply`.',
			self::DOCS,
		)];
	}
}
