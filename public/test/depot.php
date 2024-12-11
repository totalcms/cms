<?php include __DIR__ . '/_start.php'; ?>

<h1>Total CMS Depot Form Demo</h1>

{{ cms.form.depot("mydepot") }}

<!-- {{ cms.depotDownload('mydepot', 'BrazilHeart-small.png', {path:"subfolder/another-folder"}) }} -->
<!-- {{ cms.depotDownload('mydepot', 'subfolder/another-folder/BrazilHeart-small.png') }} -->

<p>Max file upload size: <?php echo ini_get('upload_max_filesize'); ?></p>

<a href="{{ cms.depotDownload('mydepot', 'BrazilHeart-small.png') }}">↓ Download Test</a>

<?php include __DIR__ . '/_end.php'; ?>