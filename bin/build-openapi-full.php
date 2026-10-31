#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Write resources/api/totalcms-api-full.json: every registered route as an
 * OpenAPI operation, for scanners and the authorization matrix. Run after
 * adding or changing a route; FullOpenApiSpecTest fails until you do.
 *
 *   php bin/build-openapi-full.php
 */

require_once __DIR__ . '/lib/openapi-full.php';

$app = require __DIR__ . '/../config/bootstrap.php';

$spec = buildFullOpenApiSpec($app, loadPublicOpenApiSpec());
file_put_contents(OPENAPI_FULL_FILE, encodeFullOpenApiSpec($spec));

$operations = array_sum(array_map('count', $spec['paths']));
echo sprintf("Wrote %s: %d paths, %d operations\n", basename(OPENAPI_FULL_FILE), count($spec['paths']), $operations);
