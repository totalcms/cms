<?php

declare(strict_types=1);

// Runs before every `composer run bench*`: lists fixtures that are missing
// (those sizes are left out of the run) and stops the run when a whole group
// is missing, since phpbench would otherwise run those subjects with no
// fixture at all and report errors.

use Tests\Performance\Bench\BenchCase;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

$status = BenchCase::fixtureStatus();
$empty  = false;

foreach (['sizes' => 'Collection sizes', 'pages' => 'Router page counts'] as $group => $label) {
	if ($status[$group]['missing'] === []) {
		continue;
	}
	fwrite(STDERR, sprintf("%s missing — %s:\n", $label, $status[$group]['available'] === [] ? 'nothing to run' : 'skipped this run'));
	foreach ($status[$group]['missing'] as $command) {
		fwrite(STDERR, "  {$command}\n");
	}
	$empty = $empty || $status[$group]['available'] === [];
}

if ($empty) {
	fwrite(STDERR, "Build them with: composer run perf:fixtures\n");
	exit(1);
}
