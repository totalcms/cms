<?php

declare(strict_types=1);

namespace TestVendor\RawBody;

use TotalCMS\Domain\Extension\ExtensionContext;
use TotalCMS\Domain\Extension\ExtensionInterface;
use TotalCMS\Domain\Webhook\RawBody;

/**
 * One public route that answers with the exact bytes it was sent, as a
 * webhook handler must be able to read them to check a signature.
 */
class Extension implements ExtensionInterface
{
	public function register(ExtensionContext $context): void
	{
		$context->addPublicRoutes(function ($group): void {
			$group->post('/echo', function ($request, $response) {
				$response->getBody()->write(RawBody::of($request));

				return $response;
			});
		});
	}

	public function boot(ExtensionContext $context): void
	{
	}
}
