<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Security\Check;

use TotalCMS\Domain\Security\Data\SecurityContext;
use TotalCMS\Domain\Security\Data\SecurityFinding;
use TotalCMS\Domain\Security\Data\Severity;
use TotalCMS\Support\Config;

class XmlRpcOnCheck implements SecurityCheck
{
	private const ID   = 'xmlrpc-on';
	private const DOCS = 'operations/security#xmlrpc-on';

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
		return 'XML-RPC publishing';
	}

	public function run(SecurityContext $context): array
	{
		if (empty($this->config->xmlrpc['enable'])) {
			return [SecurityFinding::ok(self::ID, 'XML-RPC publishing is off')];
		}

		return [SecurityFinding::found(
			self::ID,
			Severity::Info,
			'XML-RPC publishing is on',
			'The XML-RPC endpoint accepts API key logins from the internet.',
			'If you do not publish from MarsEdit, Byword or Ulysses, set `xmlrpc.enable` to false in config/tcms.php.',
			self::DOCS,
		)];
	}
}
