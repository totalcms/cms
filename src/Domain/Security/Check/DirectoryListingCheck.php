<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Security\Check;

use TotalCMS\Domain\Security\Data\SecurityContext;
use TotalCMS\Domain\Security\Data\SecurityFinding;
use TotalCMS\Domain\Security\Data\Severity;
use TotalCMS\Domain\Security\Service\SiteProbe;
use TotalCMS\Support\Config;

class DirectoryListingCheck implements SecurityCheck
{
	private const ID    = 'directory-listing';
	private const TITLE = 'Directory listing is on';
	private const DOCS  = 'operations/security#directory-listing';

	public function __construct(
		private readonly Config $config,
		private readonly SiteProbe $probe,
	) {
	}

	public function makesRequests(): bool
	{
		return true;
	}

	public function run(SecurityContext $context): array
	{
		$name   = trim((string)($this->config->builder['assetsPath'] ?? ''), '/\\');
		$assets = rtrim($this->config->docroot, '/\\') . '/' . ($name !== '' ? $name : 'assets');
		if ($this->config->docroot === '' || !is_dir($assets) || !$this->probe->insideDocroot($assets)) {
			return [SecurityFinding::ok(self::ID, 'There is no assets folder to check')];
		}

		$url = $this->probe->urlFor($context, $assets);
		if ($url === null) {
			return [SecurityFinding::skipped(self::ID, self::TITLE, SiteProbe::NO_URL)];
		}

		$response = $this->probe->get($url . '/');
		if ($response === null) {
			return [SecurityFinding::skipped(self::ID, self::TITLE, SiteProbe::UNREACHABLE)];
		}

		if (str_contains($response->body, '<title>Index of')) {
			return [SecurityFinding::found(
				self::ID,
				Severity::Warning,
				self::TITLE,
				(string)parse_url($url, PHP_URL_PATH) . '/ lists its files to visitors.',
				ServerRuleFix::noListing($context),
				self::DOCS,
			)];
		}

		return [SecurityFinding::ok(self::ID, 'Directory listing is off')];
	}
}
