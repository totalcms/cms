<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Setup\Service;

use TotalCMS\Domain\Twig\Adapter\AdminTwigAdapter;
use TotalCMS\Support\PathResolver;

/**
 * Computes server-specific install hints for the setup wizard:
 * detected web server family, URL prefix to the public/ directory,
 * and ready-to-paste rewrite rules so requests for routes that don't
 * exist on disk (like /sitemap) are routed through Total CMS.
 */
class ServerConfigAdvisor
{
	public function __construct(
		private readonly AdminTwigAdapter $adminTwig,
	) {
	}

	/**
	 * Detect web server family from SERVER_SOFTWARE.
	 * Returns 'apache', 'nginx', or 'unknown'.
	 *
	 * @SuppressWarnings("PHPMD.Superglobals")
	 */
	public function detectServer(): string
	{
		$software = strtolower((string)($_SERVER['SERVER_SOFTWARE'] ?? ''));
		if ($software === '') {
			return 'unknown';
		}
		// LiteSpeed reads .htaccess so it's grouped with apache
		if (str_contains($software, 'apache') || str_contains($software, 'litespeed')) {
			return 'apache';
		}
		if (str_contains($software, 'nginx') || str_contains($software, 'openresty')) {
			return 'nginx';
		}

		return 'unknown';
	}

	/**
	 * Raw SERVER_SOFTWARE value (for display).
	 *
	 * @SuppressWarnings("PHPMD.Superglobals")
	 */
	public function serverSoftware(): string
	{
		return (string)($_SERVER['SERVER_SOFTWARE'] ?? '');
	}

	/**
	 * URL path from docroot to T3's public/ directory, e.g.
	 * '/rw_common/plugins/stacks/tcms/public', or empty string when
	 * public/ already serves as docroot.
	 *
	 * @SuppressWarnings("PHPMD.Superglobals")
	 */
	public function publicUrlPrefix(): string
	{
		$docRoot = (string)($_SERVER['DOCUMENT_ROOT'] ?? '');
		if ($docRoot === '') {
			return '';
		}

		$docRootReal = realpath($docRoot);
		$publicReal  = realpath(PathResolver::projectRoot() . '/public');
		if ($docRootReal === false || $publicReal === false) {
			return '';
		}

		// Normalize trailing slashes for the prefix check
		$docRootReal = rtrim($docRootReal, DIRECTORY_SEPARATOR);
		if ($publicReal === $docRootReal) {
			return '';
		}
		if (!str_starts_with($publicReal, $docRootReal . DIRECTORY_SEPARATOR)) {
			return '';
		}

		$relative = substr($publicReal, strlen($docRootReal));
		if (DIRECTORY_SEPARATOR !== '/') {
			$relative = str_replace(DIRECTORY_SEPARATOR, '/', $relative);
		}

		return rtrim($relative, '/');
	}

	/**
	 * URL path from docroot to the install root (parent of public/).
	 * Empty string when public/ already serves as docroot.
	 */
	public function installUrlPrefix(): string
	{
		$publicPrefix = $this->publicUrlPrefix();
		if ($publicPrefix === '') {
			return '';
		}
		if (str_ends_with($publicPrefix, '/public')) {
			return substr($publicPrefix, 0, -strlen('/public'));
		}

		return $publicPrefix;
	}

	/**
	 * Whether tcms-data sits under docroot (so it's reachable by URL
	 * and needs an explicit deny rule).
	 */
	public function tcmsDataReachable(): bool
	{
		return $this->tcmsDataUrlPath() !== '';
	}

	/**
	 * URL path to tcms-data/ relative to docroot, or empty if not reachable.
	 *
	 * @SuppressWarnings("PHPMD.Superglobals")
	 */
	public function tcmsDataUrlPath(): string
	{
		$docRoot = (string)($_SERVER['DOCUMENT_ROOT'] ?? '');
		if ($docRoot === '') {
			return '';
		}

		$docRootReal = realpath($docRoot);
		$dataReal    = realpath(PathResolver::projectRoot() . '/tcms-data');
		if ($docRootReal === false || $dataReal === false) {
			return '';
		}

		$docRootReal = rtrim($docRootReal, DIRECTORY_SEPARATOR);
		if (!str_starts_with($dataReal, $docRootReal . DIRECTORY_SEPARATOR)) {
			return '';
		}

		$relative = substr($dataReal, strlen($docRootReal));
		if (DIRECTORY_SEPARATOR !== '/') {
			$relative = str_replace(DIRECTORY_SEPARATOR, '/', $relative);
		}

		return rtrim($relative, '/') . '/';
	}

	/**
	 * Apache .htaccess rules. Place in the document root .htaccess.
	 */
	public function apacheRewrite(): string
	{
		$publicPrefix = $this->publicUrlPrefix();
		$indexUrl     = ($publicPrefix === '' ? '' : $publicPrefix) . '/index.php';
		$dataUrl      = $this->tcmsDataUrlPath();

		$lines = ['RewriteEngine On', ''];

		if ($dataUrl !== '') {
			$pattern = ltrim($dataUrl, '/');
			$lines[] = '# Block direct access to tcms-data';
			$lines[] = 'RewriteRule ^' . $pattern . ' - [F,L]';
			$lines[] = '';
		}

		$lines[] = '# Pass through real files and directories';
		$lines[] = 'RewriteCond %{REQUEST_FILENAME} -f [OR]';
		$lines[] = 'RewriteCond %{REQUEST_FILENAME} -d';
		$lines[] = 'RewriteRule ^ - [L]';
		$lines[] = '';
		$lines[] = '# Route everything else through Total CMS';
		$lines[] = 'RewriteRule ^(.*)$ ' . ltrim($indexUrl, '/') . ' [QSA,L]';

		return implode("\n", $lines);
	}

	/**
	 * Nginx server-block snippet.
	 */
	public function nginxConfig(): string
	{
		$publicPrefix = $this->publicUrlPrefix();
		$indexUrl     = ($publicPrefix === '' ? '' : $publicPrefix) . '/index.php';
		$dataUrl      = $this->tcmsDataUrlPath();

		$lines = [];

		if ($dataUrl !== '') {
			$lines[] = '# Block direct access to tcms-data';
			$lines[] = 'location ~* ' . $dataUrl . ' {';
			$lines[] = "\tdeny all;";
			$lines[] = "\treturn 403;";
			$lines[] = '}';
			$lines[] = '';
		}

		$lines[] = '# Route everything through Total CMS';
		$lines[] = 'location / {';
		$lines[] = "\ttry_files \$uri \$uri/ " . $indexUrl . '?$query_string;';
		$lines[] = '}';

		return implode("\n", $lines);
	}

	/**
	 * Cron command for processing the job queue.
	 */
	public function cronCommand(): string
	{
		return $this->adminTwig->processJobQueueCommand();
	}
}
