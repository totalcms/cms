<?php

declare(strict_types=1);

/*
 * Remove every parallel test worker's sandbox (data, cache and tmp).
 * `composer run test:clean`. Do not run it while tests are running: it
 * deletes the sandboxes they are using.
 */

require __DIR__ . '/../tests/worker-paths.php';

$base = tcmsTestWorkersBase();
if (!is_dir($base)) {
	echo "Nothing to remove: {$base} does not exist.\n";
	exit(0);
}

$items = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
foreach ($items as $item) {
	$item->isDir() && !$item->isLink() ? rmdir($item->getPathname()) : unlink($item->getPathname());
}
rmdir($base);

echo "Removed {$base}\n";
