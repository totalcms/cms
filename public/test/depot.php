<?php include __DIR__ . '/_start.php'; ?>

<h1>Total CMS Depot Form Demo</h1>

{{ cms.form.depot("mydepot") }}

<p>Max file upload size: <?php echo ini_get('upload_max_filesize'); ?></p>

<a href="/download/depot/mydepot/depot/">↓ Download Test</a>

<?php include __DIR__ . '/_end.php'; ?>
