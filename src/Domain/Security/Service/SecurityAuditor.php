<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Security\Service;

use Throwable;
use TotalCMS\Domain\Security\Check\AdminAccountsCheck;
use TotalCMS\Domain\Security\Check\ApiKeysBroadCheck;
use TotalCMS\Domain\Security\Check\DataFolderPublicCheck;
use TotalCMS\Domain\Security\Check\DebugOnCheck;
use TotalCMS\Domain\Security\Check\DevModeOnCheck;
use TotalCMS\Domain\Security\Check\DirectoryListingCheck;
use TotalCMS\Domain\Security\Check\McpAnonymousCheck;
use TotalCMS\Domain\Security\Check\NoHttpsCheck;
use TotalCMS\Domain\Security\Check\RawRequestDataCheck;
use TotalCMS\Domain\Security\Check\RegistrationGroupCheck;
use TotalCMS\Domain\Security\Check\SecurityCheck;
use TotalCMS\Domain\Security\Check\SecurityHeadersCheck;
use TotalCMS\Domain\Security\Check\SensitiveFilesPublicCheck;
use TotalCMS\Domain\Security\Check\VersionBehindCheck;
use TotalCMS\Domain\Security\Check\XmlRpcOnCheck;
use TotalCMS\Domain\Security\Data\FindingStatus;
use TotalCMS\Domain\Security\Data\SecurityContext;
use TotalCMS\Domain\Security\Data\SecurityFinding;
use TotalCMS\Domain\Security\Data\SecurityReport;
use TotalCMS\Support\Config;

class SecurityAuditor
{
	/** @var list<class-string<SecurityCheck>> */
	public const CHECKS = [
		DataFolderPublicCheck::class,
		SensitiveFilesPublicCheck::class,
		DirectoryListingCheck::class,
		DebugOnCheck::class,
		DevModeOnCheck::class,
		NoHttpsCheck::class,
		SecurityHeadersCheck::class,
		XmlRpcOnCheck::class,
		VersionBehindCheck::class,
		RegistrationGroupCheck::class,
		AdminAccountsCheck::class,
		ApiKeysBroadCheck::class,
		McpAnonymousCheck::class,
		RawRequestDataCheck::class,
	];

	/** @param list<SecurityCheck> $checks */
	public function __construct(
		private readonly Config $config,
		private readonly array $checks,
	) {
	}

	public function audit(SecurityContext $context, bool $offlineOnly = false): SecurityReport
	{
		$ignore   = array_map(strval(...), array_filter(
			(array)($this->config->security['ignore'] ?? []),
			is_scalar(...),
		));
		$findings = [];

		foreach ($this->checks as $check) {
			if ($offlineOnly && $check->makesRequests()) {
				continue;
			}

			try {
				$results = $check->run($context);
			} catch (Throwable $e) {
				$results = [SecurityFinding::skipped($check::class, $check::class, $e->getMessage())];
			}

			foreach ($results as $finding) {
				$findings[] = in_array($finding->id, $ignore, true) ? $finding->withIgnored() : $finding;
			}
		}

		usort($findings, static fn (SecurityFinding $a, SecurityFinding $b): int => self::order($a) <=> self::order($b));

		return new SecurityReport($findings, date('c'));
	}

	/** Found findings first by severity, then ok and skipped. */
	private static function order(SecurityFinding $f): int
	{
		return ($f->status === FindingStatus::Found ? 0 : 10) + $f->severity->rank();
	}
}
