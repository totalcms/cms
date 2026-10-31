<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Security\Service;

use TotalCMS\Domain\Security\Data\SecurityContext;
use TotalCMS\Support\Config;
use TotalCMS\Support\HttpClientInterface;
use TotalCMS\Support\HttpResponse;

/** Lets security checks request the site's own origin, and map file paths to URLs. */
readonly class SiteProbe
{
	public const UNREACHABLE = 'The site couldn\'t reach itself over HTTP, so this wasn\'t checked.';
	public const NO_URL      = 'The command line doesn\'t know this site\'s address yet. Visit the admin once, or set `domain` in config/tcms.php.';

	public function __construct(
		private HttpClientInterface $http,
		private Config $config,
	) {
	}

	/**
	 * The public URL for a file, or null when the site's address is unknown or the
	 * path isn't inside the docroot. A path counts as inside when either its lexical
	 * path (links not followed) or its realpath is under the docroot, so a folder
	 * exposed through a symlink inside the docroot is still found.
	 */
	public function urlFor(SecurityContext $context, string $absolutePath): ?string
	{
		if ($context->baseUrl === '') {
			return null;
		}

		$relative = $this->relativeToDocroot($absolutePath);
		if ($relative === null) {
			return null;
		}

		$segments = array_map('rawurlencode', explode('/', str_replace('\\', '/', $relative)));

		return rtrim($context->baseUrl, '/') . '/' . implode('/', $segments);
	}

	public function insideDocroot(string $absolutePath): bool
	{
		return $this->relativeToDocroot($absolutePath) !== null;
	}

	public function get(string $url): ?HttpResponse
	{
		try {
			$response = $this->http->request('GET', $url, [
				'timeout'          => 3,
				'connect_timeout'  => 3,
				'follow_redirects' => false,
			]);
		} catch (\Throwable) {
			return null;
		}

		return $response->statusCode === 0 ? null : $response;
	}

	/** Path relative to the docroot, matching lexically or by realpath; null when outside. */
	private function relativeToDocroot(string $path): ?string
	{
		if ($this->config->docroot === '') {
			return null;
		}

		$docroots = array_values(array_unique(array_filter([
			$this->normalize($this->config->docroot),
			$this->docrootReal(),
		], static fn (?string $d): bool => $d !== null && $d !== '')));
		$paths = array_values(array_unique(array_filter([
			$this->normalize($path),
			$this->resolve($path),
		], static fn (?string $p): bool => $p !== null)));

		foreach ($paths as $candidate) {
			foreach ($docroots as $docroot) {
				if ($this->within($candidate, $docroot)) {
					return ltrim(substr($candidate, strlen($docroot)), '/\\');
				}
			}
		}

		return null;
	}

	/** Collapse ./ and ../ and duplicate slashes without following symlinks. */
	private function normalize(string $path): string
	{
		$parts = [];
		foreach (explode('/', str_replace('\\', '/', $path)) as $part) {
			if ($part === '' || $part === '.') {
				continue;
			}
			if ($part === '..') {
				array_pop($parts);

				continue;
			}
			$parts[] = $part;
		}

		return '/' . implode('/', $parts);
	}

	private function docrootReal(): ?string
	{
		if ($this->config->docroot === '') {
			return null;
		}
		$real = realpath($this->config->docroot);

		return $real === false ? null : rtrim($real, '/\\');
	}

	/** realpath, falling back to the parent's realpath plus basename for a file that doesn't exist yet. */
	private function resolve(string $path): ?string
	{
		$real = realpath($path);
		if ($real !== false) {
			return $real;
		}
		$parent = realpath(dirname($path));

		return $parent === false ? null : rtrim($parent, '/\\') . '/' . basename($path);
	}

	private function within(string $real, string $docroot): bool
	{
		return $real === $docroot || str_starts_with($real, $docroot . '/');
	}
}
