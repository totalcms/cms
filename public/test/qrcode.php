<?php include __DIR__ . '/_start.php'; ?>

	<h1>Total CMS QRCode Demo</h1>

	<div style="margin-top:1rem">
		{{ qr.url("https://www.weavers.space/stacks/") | raw }}
	</div>

<?php include __DIR__ . '/_end.php'; ?>
