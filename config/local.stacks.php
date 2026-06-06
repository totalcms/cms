<?php

declare(strict_types=1);

// Stacks Publish Environment
// echo "Stacks Publish Environment\n";

$settings['env'] = 'stacks';

$settings['session']['name'] = null;

$settings['api'] = '/site-assets/stacks/tcms3/tcms';
if (str_contains(__DIR__, 'rw_common')) {
	$settings['api'] = '/rw_common/plugins/stacks/tcms';
}

if (php_sapi_name() === 'cli-server') {
	// Preview mode for CLI server
	$settings['api'] = '/site-assets/stacks/tcms3/tcms/public/index.php';
	if (str_contains(__DIR__, 'rw_common')) {
		$settings['api'] = '/rw_common/plugins/stacks/tcms/public/index.php';
	}
}
