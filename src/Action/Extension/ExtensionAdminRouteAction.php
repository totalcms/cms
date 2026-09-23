<?php

declare(strict_types=1);

namespace TotalCMS\Action\Extension;

use TotalCMS\Domain\Extension\Data\ExtensionRoute;

/**
 * Dispatches requests to extension-registered admin route handlers.
 *
 * Route: /admin/ext/{vendor}/{name}/{path}
 * Auth: handled by admin middleware on the route group.
 */
readonly class ExtensionAdminRouteAction extends AbstractExtensionRouteAction
{
	protected function match(string $extensionId, string $method, string $path): ?ExtensionRoute
	{
		return $this->extensionManager->matchExtensionAdminRoute($extensionId, $method, $path);
	}
}
