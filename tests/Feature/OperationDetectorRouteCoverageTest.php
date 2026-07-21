<?php

declare(strict_types=1);

use Slim\App;
use TotalCMS\Domain\Auth\Service\OperationDetector;

/**
 * Guardrail for the OperationDetector route map.
 *
 * Routes guarded by an operation-detecting access middleware (CollectionAccessMiddleware
 * and its siblings, via BaseAccessMiddleware) need their route name mapped to a CRUD
 * operation in OperationDetector. If a name is missing, BaseAccessMiddleware::process()
 * cannot resolve an operation and 403s any NON-super-admin user who has the right access
 * groups (super-admins bypass before the operation check, so it stays hidden in testing).
 * This is the bug class behind the nested card/deck upload 403.
 *
 * This boots the real app, enumerates every named route, and asserts each is EITHER mapped
 * in OperationDetector OR listed in EXEMPT below. EXEMPT = routes that do NOT pass through
 * an operation-detecting middleware (auth, oauth, public assets/feeds, downloads, streams,
 * setup wizard, cache, job queue, MCP, emergency, super-admin-only admin actions, and
 * DualAuth/edition-gated import/export/sync/mailer routes).
 *
 * When this fails for a NEW route: if it is behind CollectionAccessMiddleware /
 * SchemaAccessMiddleware / CollectionMetaAccessMiddleware / DataViewsAccessMiddleware /
 * MailerAccessMiddleware / PlaygroundAccessMiddleware / BuilderAccessMiddleware /
 * UtilsAccessMiddleware / DocsAccessMiddleware, map its name in OperationDetector;
 * otherwise add it to EXEMPT here.
 */
test('every named route is mapped in OperationDetector or explicitly exempt', function (): void {
	/** @var App<Psr\Container\ContainerInterface> $app */
	$app = bootstrap();

	$routeNames = [];
	foreach ($app->getRouteCollector()->getRoutes() as $route) {
		$name = (string)$route->getName();
		if ($name !== '') {
			$routeNames[$name] = true;
		}
	}

	// Union of every *_ROUTES list OperationDetector knows about.
	$mapped = [];
	foreach ((new ReflectionClass(OperationDetector::class))->getConstants() as $constName => $values) {
		if (str_ends_with($constName, '_ROUTES') && is_array($values)) {
			$mapped = array_merge($mapped, $values);
		}
	}

	// Routes that legitimately do NOT need operation detection (no op-detecting middleware).
	$exempt = [
		'access-group-delete',
		'access-group-edit',
		'access-group-save',
		'action-bulk-mailer',
		'action-bulk-mailer-objects',
		'action-bulk-mailer-preview',
		'action-send-email',
		'actpub-feed',
		'admin-404',
		'admin-automations',
		'admin-automations-enable',
		'admin-automations-post',
		'admin-automations-run',
		'admin-ext-route',
		'admin-extension-review',
		'admin-extension-settings',
		'admin-extension-settings-save',
		'admin-extension-toggle',
		'admin-extensions',
		'admin-impersonate-start',
		'admin-impersonate-stop',
		'admin-index',
		'admin-profile',
		'admin-sync',
		'admin-update-apply',
		'admin-utils-access-groups',
		'admin-utils-api-keys',
		'all-collection-image-cache-delete',
		'api-docs',
		'apikey-create',
		'apikey-delete',
		'applenews-feed',
		'automation-webhook',
		'cache-delete',
		'cache-devmode-disable',
		'cache-devmode-enable',
		'cache-devmode-status',
		'clear-queue',
		'clear-queue-collection',
		'collection-image-cache-delete',
		'denied',
		'designer-template-update',
		'download-file',
		'download-file-depot',
		'download-file-depot-password',
		'download-file-password',
		'download-upload',
		'emergency-cache-clear',
		'emergency-license-cache-clear',
		'export-jumpstart',
		'export-jumpstart-demo',
		'filelinks',
		'forgot-password',
		'gallery-image-fetch',
		'gallery-image-fetch-dynamic',
		'gallery-image-fetch-short',
		'image-fetch',
		'image-fetch-short',
		'imageworks',
		'import-alloy',
		'import-alloy-analyze',
		'import-jumpstart',
		'import-rss',
		'import-rss-analyze',
		'import-totalcms-one',
		'import-wordpress',
		'import-wordpress-analyze',
		'json-feed',
		'livereload-events',
		'login',
		'logout',
		'mcp',
		'mcp-discovery',
		'oauth-client-create',
		'oauth-client-delete',
		'oauth-grant-revoke',
		'oauth.approve',
		'oauth.authorize',
		'oauth.discovery',
		'oauth.jwks',
		'oauth.protected-resource',
		'oauth.register',
		'oauth.revoke',
		'oauth.token',
		'orphan-cleanup',
		'orphan-scan',
		'post-collection-image-cache-delete',
		'public-asset',
		'queue-jobs-html',
		'queue-stats',
		'queue-stats-collection',
		'queue-stats-collection-html',
		'queue-stats-html',
		'register',
		'resend-verification',
		'reset-password',
		'rss-feed',
		'setup-account',
		'setup-account-submit',
		'setup-complete',
		'setup-data-path',
		'setup-data-path-submit',
		'setup-environment',
		'setup-error-monitoring',
		'setup-error-monitoring-submit',
		'setup-license',
		'setup-server-config',
		'setup-welcome',
		'setup-welcome-submit',
		'sitemap-factory',
		'sitemap-index',
		'sitemap-index-xml',
		'sitemap-pages',
		'stream-file',
		'stream-file-depot',
		'stream-upload',
		'sync-import',
		'upload-delete',
		'upload-get',
		'upload-list',
		'upload-post',
		'upload-post-nested',
		'uploads-image-fetch',
		'verify-email',
		'watermark-cache-delete',
	];

	$unaccounted = array_values(array_diff(array_keys($routeNames), $mapped, $exempt));

	expect($unaccounted)->toBe([], 'Route(s) neither mapped in OperationDetector nor exempt: '
		. implode(', ', $unaccounted)
		. '. If a route is guarded by an operation-detecting access middleware, map its name in '
		. 'OperationDetector; otherwise add it to EXEMPT in this test.');
});
