<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Security\Check;

use TotalCMS\Domain\Auth\Service\UserValidationService;
use TotalCMS\Domain\Index\Service\IndexReader;
use TotalCMS\Domain\Object\Service\ObjectFetcher;
use TotalCMS\Domain\Security\Data\SecurityContext;
use TotalCMS\Domain\Security\Data\SecurityFinding;
use TotalCMS\Domain\Security\Data\Severity;
use TotalCMS\Support\Config;

/** Counts only. Never names an admin or prints an email. */
class AdminAccountsCheck implements SecurityCheck
{
	private const ID        = 'admin-accounts';
	private const DOCS      = 'operations/security#admin-accounts';
	private const MAX_ADMIN = 5;

	public function __construct(
		private readonly Config $config,
		private readonly IndexReader $index,
		private readonly ObjectFetcher $objects,
	) {
	}

	public function makesRequests(): bool
	{
		return false;
	}

	public function run(SecurityContext $context): array
	{
		$collection = (string)($this->config->auth['collection'] ?? 'auth');
		$adminIds   = [];
		foreach ($this->index->fetchIndex($collection)->objects as $row) {
			if (in_array(UserValidationService::ADMINGROUP, (array)($row['groups'] ?? []), true)) {
				$adminIds[] = (string)($row['id'] ?? '');
			}
		}

		$count    = count($adminIds);
		$passkeys = 0;
		foreach ($adminIds as $id) {
			if (!empty($this->objects->fetchObject($collection, $id)->toArray()['passkeys'])) {
				$passkeys++;
			}
		}

		$detail = sprintf('%d administrator accounts, %d with a passkey.', $count, $passkeys);
		if ($count > self::MAX_ADMIN) {
			return [SecurityFinding::found(
				self::ID,
				Severity::Warning,
				'Many administrator accounts',
				$detail,
				'Give day-to-day users an access group with only the permissions they need and keep `admin` for a few people.',
				self::DOCS,
			)];
		}

		return [SecurityFinding::found(
			self::ID,
			Severity::Info,
			'Administrator accounts',
			$detail,
			'Turn on passkeys for administrators where you can.',
			self::DOCS,
		)];
	}
}
