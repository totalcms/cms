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

	/**
	 * candidates() exists because resolve() is request-shaped: a request
	 * arriving through a subfolder install's root catch-all rewrite carries no
	 * subpath to cross-check against, so it resolves to '' even though the
	 * install really does live under one. OAuth discovery has to answer "is
	 * this issuer path mine?" for exactly those requests.
	 *
	 * @param list<string> $expected
	 */
	#[DataProvider('candidateProvider')]
	public function testListsReachableMountPrefixesWithoutARequestPath(string $scriptName, array $expected): void
	{
		expect(BasePath::candidates($scriptName))->toBe($expected);
	}

	/** @return array<string,array{string,list<string>}> */
	public static function candidateProvider(): array
	{
		return [
			// The Stacks case: resolve() would say '' for a root-shaped
			// request, but the install is genuinely reachable at both of these.
			'stacks' => [
				'/rw_common/plugins/stacks/tcms/public/index.php',
				['/rw_common/plugins/stacks/tcms/public', '/rw_common/plugins/stacks/tcms'],
			],
			'composer subpath' => ['/cms/index.php', ['/cms']],
			// Root install has no path component at all — nothing to offer.
			'root install'        => ['/index.php', []],
			'cli-server basename' => ['index.php', []],
			'empty script name'   => ['', []],
		];
	}
}
