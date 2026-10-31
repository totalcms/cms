<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Security\Check;

use TotalCMS\Domain\Security\Data\SecurityContext;
use TotalCMS\Domain\Security\Data\SecurityFinding;
use TotalCMS\Domain\Security\Data\Severity;
use TotalCMS\Domain\Security\Service\SiteProbe;

class SecurityHeadersCheck implements SecurityCheck
{
	private const ID      = 'security-headers';
	private const HSTS_ID = 'security-headers-hsts';
	private const TITLE   = 'Security headers';
	private const DOCS    = 'operations/security#security-headers';

	/** Lowercase header name => display name. */
	private const REQUIRED = [
		'x-frame-options'        => 'X-Frame-Options',
		'x-content-type-options' => 'X-Content-Type-Options',
		'referrer-policy'        => 'Referrer-Policy',
	];

	private const VALUES = [
		'X-Frame-Options'        => 'SAMEORIGIN',
		'X-Content-Type-Options' => 'nosniff',
		'Referrer-Policy'        => 'strict-origin-when-cross-origin',
	];

	public function __construct(private readonly SiteProbe $probe)
	{
	}

	public function makesRequests(): bool
	{
		return true;
	}

	public function run(SecurityContext $context): array
	{
		if ($context->baseUrl === '') {
			return [SecurityFinding::skipped(self::ID, self::TITLE, SiteProbe::NO_URL)];
		}

		$response = $this->probe->get(rtrim($context->baseUrl, '/') . '/');
		if ($response === null) {
			return [SecurityFinding::skipped(self::ID, self::TITLE, SiteProbe::UNREACHABLE)];
		}

		$missing = [];
		foreach (self::REQUIRED as $key => $name) {
			if (!isset($response->headers[$key])) {
				$missing[] = $name;
			}
		}

		$findings = [];
		if ($missing !== []) {
			$findings[] = SecurityFinding::found(
				self::ID,
				Severity::Info,
				'Security headers are missing',
				'The home page does not send: ' . implode(', ', $missing) . '.',
				$this->headerFix($context, $missing),
				self::DOCS,
			);
		}

		if ($context->https && !isset($response->headers['strict-transport-security'])) {
			$findings[] = SecurityFinding::found(
				self::HSTS_ID,
				Severity::Info,
				'HSTS is not enabled',
				'The home page does not send Strict-Transport-Security.',
				$this->hstsFix($context),
				self::DOCS,
			);
		}

		return $findings !== [] ? $findings : [SecurityFinding::ok(self::ID, 'Security headers are set')];
	}

	/** @param list<string> $missing */
	private function headerFix(SecurityContext $context, array $missing): string
	{
		$lines = [];
		foreach ($missing as $name) {
			$lines[] = $context->isApache()
				? sprintf('Header always set %s "%s"', $name, self::VALUES[$name])
				: sprintf('add_header %s "%s" always;', $name, self::VALUES[$name]);
		}

		$where = $context->isApache() ? 'to your .htaccess file' : 'to your Nginx server block and reload';

		return sprintf("Add these lines %s:\n\n%s", $where, implode("\n", $lines));
	}

	private function hstsFix(SecurityContext $context): string
	{
		$caution = 'HSTS tells browsers to refuse plain HTTP for this site. Browsers remember it for the full max-age, so make sure every page and subdomain you use works over HTTPS first. Start with a short max-age, such as 300, and raise it once you are sure.';

		$line = $context->isApache()
			? 'Header always set Strict-Transport-Security "max-age=300"'
			: 'add_header Strict-Transport-Security "max-age=300" always;';

		return $caution . "\n\n" . $line;
	}
}
