<?php include __DIR__ . '/_start.php'; ?>

<?php

$propFetcher = $totalcms->propertyFetcher();
$content     = 'before';
try {
	$content = $propFetcher->fetchProperty('text', 'mission', 'text');
} catch (Exception $e) {
	error_log($e->getMessage());
}
echo $content;

?>


<?php include __DIR__ . '/_end.php'; ?>