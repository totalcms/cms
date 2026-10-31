<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Security\Check;

use TotalCMS\Domain\Security\Data\SecurityContext;
use TotalCMS\Domain\Security\Service\SiteProbe;

/**
 * Proves whether a folder is served over HTTP by writing a throwaway file with
 * a random token into it and fetching it back. A body equal to the token is
 * exposure; any other answer (404, 403, a catch-all page) is not.
 */
final readonly class CanaryProbe
{
	public const OUTSIDE     = 'outside';
	public const MISSING     = 'missing';
	public const NO_URL      = 'no-url';
	public const NO_WRITE    = 'no-write';
	public const UNREACHABLE = 'unreachable';
	public const EXPOSED     = 'exposed';
	public const BLOCKED     = 'blocked';

	public function __construct(private SiteProbe $probe)
	{
	}

	/** @return array{0:string,1:string} [result, url path of the canary file, or ''] */
	public function test(SecurityContext $context, string $dir): array
	{
		if (!$this->probe->insideDocroot($dir)) {
			return [self::OUTSIDE, ''];
		}
		if (!is_dir($dir)) {
			return [self::MISSING, ''];
		}

		$file = rtrim($dir, '/\\') . '/security-probe-' . bin2hex(random_bytes(8)) . '.txt';
		$url  = $this->probe->urlFor($context, $file);
		if ($url === null) {
			return [self::NO_URL, ''];
		}

		$token = bin2hex(random_bytes(16));
		$path  = (string)parse_url($url, PHP_URL_PATH);

		try {
			if (@file_put_contents($file, $token) === false) {
				return [self::NO_WRITE, $path];
			}
			$response = $this->probe->get($url);
		} finally {
			if (is_file($file)) {
				@unlink($file);
			}
		}

		if ($response === null) {
			return [self::UNREACHABLE, $path];
		}

		return [$response->statusCode === 200 && $response->body === $token ? self::EXPOSED : self::BLOCKED, $path];
	}

	/** URL path of the folder itself (the canary's directory). */
	public static function folderPath(string $canaryPath): string
	{
		return rtrim(dirname($canaryPath), '/\\');
	}
}
