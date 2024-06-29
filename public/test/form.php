<?php include __DIR__ . '/_start.php'; ?>

<h1>Total CMS Blog Form Demo</h1>

{% set form  = cms.objectFormBuilder({collection: 'blog'}) %}

{{ form.addField() }}
{{ form.build() }}

<?php include __DIR__ . '/_end.php'; ?>