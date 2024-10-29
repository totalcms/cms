<?php include __DIR__ . '/_start.php'; ?>

<h1>Total CMS Date Form Demo</h1>

{{ cms.form.date('mydate') }}
{{ cms.form.datetime('mydatetime') }}

<h1>{{ cms.date("mydate") | date("m/d/Y") }}</h1>


<?php include __DIR__ . '/_end.php'; ?>