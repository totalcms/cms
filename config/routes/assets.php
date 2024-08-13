<?php

use Slim\App;
use TotalCMS\Action\Assets\StaticPublicAssetsAction;

return function (App $app) {
	$app->get('/assets/{asset:.*}', StaticPublicAssetsAction::class)->setName('pubic-asset');
};
