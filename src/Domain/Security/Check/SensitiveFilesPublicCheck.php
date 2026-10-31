<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Security\Check;

use TotalCMS\Domain\Security\Data\SecurityContext;
use TotalCMS\Domain\Security\Data\SecurityFinding;
use TotalCMS\Domain\Security\Data\Severity;
use TotalCMS\Domain\Security\Service\SiteProbe;
use TotalCMS\Support\Config;
use TotalCMS\Support\HttpResponse;

class SensitiveFilesPublicCheck implements SecurityCheck
{
	private const ID    = 'sensitive-files-public';
	private const TITLE = 'Sensitive files are reachable from the web';
	private const DOCS  = 'operations/security#sensitive-files-public';

	private readonly CanaryProbe $canary;

	public function __construct(
		private readonly Config $config,
		private readonly SiteProbe $probe,
	) {
		$this->canary = new CanaryProbe($probe);
	}

	public function makesRequests(): bool
	{
		return true;
	}

	public function id(): string
	{
		return self::ID;
	}

	public function title(): string
	{
		return self::TITLE;
	}

	public function run(SecurityContext $context): array
	{
		$folders = $this->scanFolders($context);
		$files   = $this->scanFiles($context);

		$findings = [...$folders['findings'], ...$files['findings']];
		if ($findings !== []) {
			return $findings;
		}
		if ($folders['noUrl'] || $files['noUrl']) {
			return [SecurityFinding::skipped(self::ID, self::TITLE, SiteProbe::NO_URL)];
		}
		if ($folders['noWrite']) {
			return [SecurityFinding::skipped(self::ID, self::TITLE, 'Total CMS couldn\'t write a test file into a folder it needed to check, so this wasn\'t checked.')];
		}
		if ($folders['unreachable'] || $files['unreachable']) {
			return [SecurityFinding::skipped(self::ID, self::TITLE, SiteProbe::UNREACHABLE)];
		}
		$redirect = $folders['redirect'] !== '' ? $folders['redirect'] : $files['redirect'];
		if ($redirect !== '') {
			return [SecurityFinding::skipped(self::ID, self::TITLE, $redirect)];
		}

		return [SecurityFinding::ok(
			self::ID,
			$folders['probed'] + $files['probed'] > 0
				? 'Logs, cache and project files are not reachable from the web'
				: 'No logs, cache or project files were found in the public web folder',
		)];
	}

	/** @return array{findings:list<SecurityFinding>,noUrl:bool,noWrite:bool,unreachable:bool,redirect:string,probed:int} */
	private function scanFolders(SecurityContext $context): array
	{
		$scan = ['findings' => [], 'noUrl' => false, 'noWrite' => false, 'unreachable' => false, 'redirect' => '', 'probed' => 0];

		foreach ($this->folders() as $dir) {
			[$result, $path, $reason] = $this->canary->test($context, $dir);

			switch ($result) {
				case CanaryProbe::NO_URL:
					$scan['noUrl'] = true;
					break;
				case CanaryProbe::NO_WRITE:
					$scan['noWrite'] = true;
					break;
				case CanaryProbe::UNREACHABLE:
					$scan['unreachable'] = true;
					break;
				case CanaryProbe::REDIRECTED:
					$scan['redirect'] = $reason;
					break;
				case CanaryProbe::BLOCKED:
					$scan['probed']++;
					break;
				case CanaryProbe::EXPOSED:
					$scan['probed']++;
					$folder              = CanaryProbe::folderPath($path);
					$scan['findings'][] = $this->exposed(Severity::Critical, $folder . '/', ServerRuleFix::denyFolder($context, $folder));
					break;
			}
		}

		return $scan;
	}

	/** @return array{findings:list<SecurityFinding>,noUrl:bool,noWrite:bool,unreachable:bool,redirect:string,probed:int} */
	private function scanFiles(SecurityContext $context): array
	{
		$scan = ['findings' => [], 'noUrl' => false, 'noWrite' => false, 'unreachable' => false, 'redirect' => '', 'probed' => 0];

		foreach ($this->files() as [$file, $severity, $exposedTest]) {
			if (!is_file($file) || !$this->probe->insideDocroot($file)) {
				continue;
			}
			$url = $this->probe->urlFor($context, $file);
			if ($url === null) {
				$scan['noUrl'] = true;

				continue;
			}
			$response = $this->probe->get($url);
			if ($response === null) {
				$scan['unreachable'] = true;

				continue;
			}
			if (SiteProbe::isRedirect($response)) {
				$scan['redirect'] = SiteProbe::redirectReason($response);

				continue;
			}
			$scan['probed']++;
			if ($exposedTest($response)) {
				$path                = (string)parse_url($url, PHP_URL_PATH);
				$scan['findings'][] = $this->exposed($severity, $path, ServerRuleFix::denyFile($context, $path));
			}
		}

		return $scan;
	}

	private function exposed(Severity $severity, string $urlPath, string $fix): SecurityFinding
	{
		return SecurityFinding::found(self::ID, $severity, self::TITLE, $urlPath, $fix, self::DOCS);
	}

	/** @return list<string> */
	private function folders(): array
	{
		$folders = [(string)($this->config->logger['path'] ?? ''), $this->config->cachedir, $this->config->tmpdir];

		return array_values(array_unique(array_filter($folders, static fn (string $f): bool => $f !== '')));
	}

	/** @return list<array{0:string,1:Severity,2:callable(HttpResponse):bool}> */
	private function files(): array
	{
		$root = rtrim($this->config->root, '/\\');
		if ($root === '') {
			return [];
		}

		$hasPackages = static function (HttpResponse $r): bool {
			$data = json_decode($r->body, true);

			return $r->statusCode === 200 && is_array($data) && array_key_exists('packages', $data);
		};

		return [
			[$root . '/.git/config', Severity::Critical, static fn (HttpResponse $r): bool => $r->statusCode === 200 && str_contains($r->body, '[core]')],
			[$root . '/.env', Severity::Critical, static fn (HttpResponse $r): bool => $r->statusCode === 200
				&& !str_starts_with(ltrim($r->body), '<')
				&& preg_match('/^[A-Za-z_][A-Za-z0-9_]*=/m', $r->body) === 1],
			[$root . '/composer.lock', Severity::Warning, $hasPackages],
			[$root . '/vendor/composer/installed.json', Severity::Warning, $hasPackages],
		];
	}
}
