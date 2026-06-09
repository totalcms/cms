<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use TotalCMS\Support\BasePath;

final class BasePathTest extends TestCase
{
	/**
	 * The whole point of this helper: link-building (config->api) and Slim
	 * routing (BasePathMiddleware) must resolve the mount prefix identically.
	 */
	#[DataProvider('layoutProvider')]
	public function testResolvesMountPrefixPerLayout(string $scriptName, string $requestPath, string $expected): void
	{
		expect(BasePath::resolve($scriptName, $requestPath))->toBe($expected);
	}

	/** @return array<string,array{string,string,string}> */
	public static function layoutProvider(): array
	{
		return [
			'root install (public/ is docroot)' => ['/index.php', '/admin/login', ''],
			'composer subpath'                  => ['/cms/index.php', '/cms/admin/login', '/cms'],
			// Stacks/Symfony: docroot rewrite hides public/ from the URL, but
			// SCRIPT_NAME still points inside it — must strip /public.
			'stacks canonical (no /public in URL)' => [
				'/rw_common/plugins/stacks/tcms/public/index.php',
				'/rw_common/plugins/stacks/tcms/admin/login',
				'/rw_common/plugins/stacks/tcms',
			],
			// If someone hits the /public URL directly, that IS the real prefix.
			'stacks accessed via /public explicitly' => [
				'/rw_common/plugins/stacks/tcms/public/index.php',
				'/rw_common/plugins/stacks/tcms/public/admin/login',
				'/rw_common/plugins/stacks/tcms/public',
			],
			'cli-server basename' => ['index.php', '/anything', ''],
			'empty script name'   => ['', '', ''],
			// Request path that matches neither candidate falls back to root.
			'unmatched request path' => ['/cms/index.php', '/totally/different', ''],
		];
	}
}
