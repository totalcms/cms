<?php

declare(strict_types=1);

use Slim\Interfaces\RouteCollectorProxyInterface;
use TotalCMS\Action\Seo\IndexNowKeyAction;

return function (RouteCollectorProxyInterface $app): void {
	// The IndexNow key file lives at the site root by protocol — engines
	// fetch https://{host}/{key}.txt to verify a submission. The pattern is
	// the key's own alphabet and length, and the action 404s unless the path
	// is the configured key, so no other root .txt is ever caught by this.
	$app->get('/{key:[a-z0-9]{8,128}}.txt', IndexNowKeyAction::class)->setName('indexnow-key');
};
