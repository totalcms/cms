<?php include __DIR__ . '/_start.php'; ?>

<h1>Total CMS File Form Demo</h1>

{{ cms.form.file("myfile") }}

<p>Max file upload size: <?php echo ini_get('upload_max_filesize'); ?></p>

<a href="/download/file/myfile/file">↓ Download Test</a>

<?php include __DIR__ . '/_end.php'; ?>
