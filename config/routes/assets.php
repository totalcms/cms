<?php

declare(strict_types=1);

use Slim\App;
use TotalCMS\Action\Assets\StaticPublicAssetsAction;

return function (App $app): void {
	$app->get('/assets/{asset:.*}', StaticPublicAssetsAction::class)->setName('pubic-asset');
};
