<?php

declare(strict_types=1);

use Slim\Interfaces\RouteCollectorProxyInterface;
use Slim\Routing\RouteCollectorProxy;
use TotalCMS\Action\Upload;
use TotalCMS\Middleware\Auth\DualAuthMiddleware;

return function (RouteCollectorProxyInterface $app): void {
	// Upload routes accept either an active admin session OR a valid API key
	// via DualAuthMiddleware. Prior to 3.5.1 these were unauthenticated in core
	// — operators relied on upstream WAF rules, which broke silently when the
	// path moved from /tcms/upload/ to /tcms/api/upload/ in 3.5. The middleware
	// closes the gap in core; WAF becomes defence in depth rather than the
	// only line.
	$app->group('/upload', function (RouteCollectorProxy $group): void {
		$group->post('/{collection}/{id}/{property}', Upload\UploadFileAction::class)->setName('upload-post');
		$group->post('/{collection}/{id}/{property}/{path:.+}', Upload\UploadFileAction::class)->setName('upload-post-nested');
		$group->get('/{collection}/{id}/{property}', Upload\ListUploadFilesAction::class)->setName('upload-list');
		$group->get('/{collection}/{id}/{property}/{path:.+}', Upload\GetFileAction::class)->setName('upload-get');
		$group->delete('/{collection}/{id}/{property}/{path:.+}', Upload\DeleteFileAction::class)->setName('upload-delete');
	})->add(DualAuthMiddleware::class);
};
