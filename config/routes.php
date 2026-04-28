<?php

use Slim\App;
use Slim\Routing\RouteCollectorProxy;

return function (App $app): void {
	// Auth routes first (before admin catch-all)
	(require __DIR__ . '/routes/auth.php')($app);

	// Admin UI and setup (not under /api)
	(require __DIR__ . '/routes/admin.php')($app);
	(require __DIR__ . '/routes/setup.php')($app);

	// All API routes under /api prefix
	$app->group('/api', function (RouteCollectorProxy $api): void {
		(require __DIR__ . '/routes/access-groups.php')($api);
		(require __DIR__ . '/routes/apikey.php')($api);
		(require __DIR__ . '/routes/assets.php')($api);
		(require __DIR__ . '/routes/passkeys.php')($api);
		(require __DIR__ . '/routes/cache.php')($api);
		(require __DIR__ . '/routes/emergency.php')($api);
		(require __DIR__ . '/routes/collections.php')($api);
		(require __DIR__ . '/routes/docs.php')($api);
		(require __DIR__ . '/routes/download.php')($api);
		(require __DIR__ . '/routes/stream.php')($api);
		(require __DIR__ . '/routes/imageworks.php')($api);
		(require __DIR__ . '/routes/import.php')($api);
		(require __DIR__ . '/routes/export.php')($api);
		(require __DIR__ . '/routes/report.php')($api);
		(require __DIR__ . '/routes/jobqueue.php')($api);
		(require __DIR__ . '/routes/schemas.php')($api);
		(require __DIR__ . '/routes/templates.php')($api);
		(require __DIR__ . '/routes/designer.php')($api);
		(require __DIR__ . '/routes/upload.php')($api);
		(require __DIR__ . '/routes/sitemap.php')($api);
		(require __DIR__ . '/routes/feed.php')($api);
		(require __DIR__ . '/routes/playground.php')($api);
		(require __DIR__ . '/routes/dataviews.php')($api);
		(require __DIR__ . '/routes/orphan.php')($api);
		(require __DIR__ . '/routes/ext.php')($api);
		(require __DIR__ . '/routes/action.php')($api);
	});
};
