<?php

use Slim\App;
use Slim\Routing\RouteCollectorProxy;

return function (App $app): void {
	// Admin UI, auth, and setup (auth must load before admin catch-all)
	(require __DIR__ . '/routes/admin/auth.php')($app);
	(require __DIR__ . '/routes/admin/admin.php')($app);
	(require __DIR__ . '/routes/admin/setup.php')($app);

	// Public crawler-facing routes (live at conventional paths)
	(require __DIR__ . '/routes/public/sitemap.php')($app);
	(require __DIR__ . '/routes/public/feed.php')($app);

	// All API routes under /api prefix
	$app->group('/api', function (RouteCollectorProxy $api): void {
		(require __DIR__ . '/routes/api/access-groups.php')($api);
		(require __DIR__ . '/routes/api/apikey.php')($api);
		(require __DIR__ . '/routes/api/assets.php')($api);
		(require __DIR__ . '/routes/api/passkeys.php')($api);
		(require __DIR__ . '/routes/api/cache.php')($api);
		(require __DIR__ . '/routes/api/emergency.php')($api);
		(require __DIR__ . '/routes/api/collections.php')($api);
		(require __DIR__ . '/routes/api/docs.php')($api);
		(require __DIR__ . '/routes/api/download.php')($api);
		(require __DIR__ . '/routes/api/stream.php')($api);
		(require __DIR__ . '/routes/api/imageworks.php')($api);
		(require __DIR__ . '/routes/api/import.php')($api);
		(require __DIR__ . '/routes/api/export.php')($api);
		(require __DIR__ . '/routes/api/report.php')($api);
		(require __DIR__ . '/routes/api/jobqueue.php')($api);
		(require __DIR__ . '/routes/api/schemas.php')($api);
		(require __DIR__ . '/routes/api/templates.php')($api);
		(require __DIR__ . '/routes/api/designer.php')($api);
		(require __DIR__ . '/routes/api/upload.php')($api);
		(require __DIR__ . '/routes/api/playground.php')($api);
		(require __DIR__ . '/routes/api/dataviews.php')($api);
		(require __DIR__ . '/routes/api/orphan.php')($api);
		(require __DIR__ . '/routes/api/ext.php')($api);
		(require __DIR__ . '/routes/api/action.php')($api);
	});
};
