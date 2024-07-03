<?php include __DIR__ . '/_start.php'; ?>

<h1>Total CMS Toggle Form Demo</h1>

{{ cms.form.checkbox('mycheck') }}
{{ cms.form.toggle('mytoggle') }}

<?php include __DIR__ . '/_end.php'; ?>