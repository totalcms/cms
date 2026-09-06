<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Auth\Service;

use Psr\Http\Message\ServerRequestInterface;
use Slim\Routing\RouteContext;

/**
 * Operation Detector Service.
 *
 * Maps route names to CRUD operations (create, read, update, delete), plus the
 * narrower public `increment` operation for the counter routes.
 * Centralized route mapping used by DualAuthMiddleware and BaseAccessMiddleware.
 */
readonly class OperationDetector
{
	/**
	 * Routes that map to PUBLIC operations.
	 */
	private const PUBLIC_ROUTES = [
		'object-save',
		'object-clone',
		'collection-fetch-index',
		'object-fetch',
		'deck-item-fetch',
		'object-exists',
		'object-delete',
		'collection-reindex',
		'object-update',
		'object-patch',
		'property-update',
		'property-patch',
		'property-delete',
		'property-meta-update',
		'property-meta-patch',
		'deck-item-create',
		'deck-item-update',
		'deck-item-delete',
		'property-file-save',
		'property-file-save-nested',
		'property-folder-save',
		'property-clear-cache',
		'property-file-delete',
		'property-file-clear-cache',
		'property-file-move',
		'property-increment',
		'property-decrement',
		'collection-query',
		'dataview-fetch',
		'dataview-query',
	];

	/**
	 * Routes that map to CREATE operations.
	 */
	private const CREATE_ROUTES = [
		'object-save',
		'object-clone',
		'schema-save',
		'collection-save',      // Collection metadata
		'template-save',
		'admin-schema-duplicate',
		'admin-template-duplicate',
		'admin-collection-post',
		'admin-playground-post',
		'admin-mail-post',
		'collection-save',
		'import-factory',
		'import-csv',
		'import-json',
		'import-deck-csv',
		'import-deck-json',
		'import-schema',
		'playground-save',
		'admin-collection-new',
		'dataview-test',
		'dataview-test-html',
	];

	/**
	 * Routes that map to READ operations.
	 */
	private const READ_ROUTES = [
		'collection-fetch-index',
		'object-fetch',
		'deck-item-fetch',
		'object-exists',
		'collections-list',     // Collection metadata
		'collection-fetch',     // Collection metadata
		'collection-exists',    // Collection metadata
		'collection-fetch-schema', // Collection metadata
		'schema-fetch',
		'schema-list',
		'schema-exists',
		'template-list',
		'template-list-folder',
		'template-fetch',
		'template-exists',
		'admin-schema',
		'admin-template',
		'admin-collection',
		'admin-collection-cell',
		'admin-collection-cell-edit',
		'admin-docs',
		'admin-utils',
		'admin-utils-post',
		'admin-utils-logs-download',
		'admin-builder',
		'admin-builder-preview',
		'admin-playground',
		'admin-mail',
		'admin-settings',
		'admin-settings-mcp-test',
		'collections-list',
		'collection-fetch',
		'collection-exists',
		'collection-fetch-schema',
		'collection-query',
		'dataview-fetch',
		'dataview-query',
		'export-json',
		'export-csv',
		'export-deck-json',
		'export-deck-csv',
		'export-zip',
		'export-schema',
		'playground-list',
		'playground-fetch',
		'admin-collection-edit',
		'admin-dataviews',
		'export-object-zip',
		'report-fields',
		'report-csv',
		'report-json',
	];

	/**
	 * Routes that map to UPDATE operations.
	 */
	private const UPDATE_ROUTES = [
		'collection-update',    // Collection metadata
		'collection-patch',     // Collection metadata
		'collection-reindex',
		'object-update',
		'object-patch',
		'property-update',
		'property-patch',
		'property-delete',
		'property-meta-update',
		'property-meta-patch',
		'deck-item-create',
		'deck-item-update',
		'deck-item-delete',
		'property-file-save',
		'property-file-save-nested',
		'property-folder-save',
		'property-clear-cache',
		'property-file-delete',
		'property-file-clear-cache',
		'property-file-move',
		'schema-update',
		'template-update',
		'admin-settings-save-section',
		'collection-update',
		'collection-patch',
		'playground-update',
		'admin-collection-edit',
		'admin-collection-cell-patch',
		'property-increment',
		'property-decrement',
		'property-folder-rename',
		'dataview-rebuild',
		'admin-builder-reorder',
	];

	/**
	 * Routes that map to DELETE operations.
	 */
	private const DELETE_ROUTES = [
		'object-delete',
		'object-bulk-delete',
		'schema-delete',
		'collection-delete',    // Collection metadata
		'template-delete',
		'playground-delete',
	];

	/**
	 * Detect the CRUD operation type based on route name.
	 *
	 * @return string|null Operation type: 'create', 'read', 'update', 'delete', or null if not determinable
	 */
	public function detectOperation(ServerRequestInterface $request): ?string
	{
		$routeName = $this->getRouteName($request);

		if ($routeName === '') {
			return null;
		}

		return $this->getCrudOperationFromRouteName($routeName);
	}

	/**
	 * Routes that anonymous callers may use under the `increment` public
	 * operation. For a logged-in user these are ordinary updates (see
	 * detectOperation()); for the public they are a narrower grant, so a
	 * collection can allow likes and counters without allowing anonymous
	 * PUT/PATCH on the whole object.
	 */
	private const INCREMENT_ROUTES = [
		'property-increment',
		'property-decrement',
	];

	/**
	 * Detect the public operation type based on route name.
	 *
	 * @return string|null Operation type: 'create', 'read', 'update', 'delete', 'increment', or null if not determinable
	 */
	public function detectPublicOperation(ServerRequestInterface $request): ?string
	{
		$routeName = $this->getRouteName($request);

		if ($routeName === '') {
			return null;
		}

		if (in_array($routeName, self::INCREMENT_ROUTES, true)) {
			return 'increment';
		}

		// Check if route is in public routes
		if (in_array($routeName, self::PUBLIC_ROUTES, true)) {
			return $this->getCrudOperationFromRouteName($routeName);
		}

		return null;
	}

	private function getRouteName(ServerRequestInterface $request): string
	{
		$routeContext = RouteContext::fromRequest($request);
		$route        = $routeContext->getRoute();

		if (!$route instanceof \Slim\Interfaces\RouteInterface) {
			return '';
		}

		return (string)$route->getName();
	}

	private function getCrudOperationFromRouteName(string $routeName): ?string
	{
		// Check each operation type
		if (in_array($routeName, self::CREATE_ROUTES, true)) {
			return 'create';
		}

		if (in_array($routeName, self::READ_ROUTES, true)) {
			return 'read';
		}

		if (in_array($routeName, self::DELETE_ROUTES, true)) {
			return 'delete';
		}

		if (in_array($routeName, self::UPDATE_ROUTES, true)) {
			return 'update';
		}

		return null;
	}
}
