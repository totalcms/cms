<?php

include __DIR__ . '/_start.php';

$totalcms->outputSitemapForCollection('blog', [
	'date'       => 'updated',
	'changefreq' => 'daily',
	'priority'   => '0.8',
]);

include __DIR__ . '/_end.php';

?>