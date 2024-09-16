<?php

include __DIR__ . '/_start.php';

$totalcms->restrictPageAccess(['test']);

?>

<h1>Total CMS Auth Test</h1>

<?php if ($totalcms->userHasAccess(['admin'])) : ?>
	<p>Hello Admin</p>
<?php else : ?>
	<p>You are not an Admin</p>
<?php endif; ?>

<a href="{{ cms.logout }}">Logout</a>

<?php include __DIR__ . '/_end.php'; ?>