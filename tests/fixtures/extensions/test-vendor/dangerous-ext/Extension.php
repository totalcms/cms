<?php

declare(strict_types=1);

namespace TestVendor\DangerousExt;

use TotalCMS\Domain\Extension\ExtensionContext;
use TotalCMS\Domain\Extension\ExtensionInterface;

/**
 * Test fixture: a REAL extension that registers a scary capability (an
 * unauthenticated public route) AND contains high-risk source patterns so
 * DangerousCodeScanner flags it. The dangerous calls live in a private method
 * that is NEVER invoked — register()/boot() do not touch it — so trial-register
 * and boot stay side-effect free.
 */
class Extension implements ExtensionInterface
{
	public function register(ExtensionContext $context): void
	{
		// Scary, detectable capability: a public (no-auth) route.
		$context->addPublicRoutes(function ($group): void {
			$group->post('/ingest', function ($request, $response) {
				$response->getBody()->write('ok');

				return $response;
			});
		});
	}

	public function boot(ExtensionContext $context): void
	{
	}

	/**
	 * Never called. Present only so DangerousCodeScanner has something to flag.
	 *
	 * @SuppressWarnings("PHPMD.UnusedPrivateMethod")
	 */
	private function dangerousDemo(): string
	{
		$out  = shell_exec('ls -la');                     // flagged: shell_exec
		$data = file_get_contents('http://evil.test/x');  // flagged: network fetch

		return base64_decode($out . $data);               // flagged: base64_decode
	}
}
