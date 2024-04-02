<?php

require_once __DIR__ . '/../vendor/autoload.php';
$totalcms = new TotalCMS\TotalCMS();
$totalcms->startBuffer(); // Start output buffering

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title>Total CMS Form Demo</title>
    <style>
    html {
        box-sizing : border-box;
        font-size  : 100%
    }

    *,
    ::after,
    ::before {
        box-sizing : inherit
    }

    body {
        font-family            : ui-sans-serif, system-ui, -system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif, "Apple Color Emoji", "Segoe UI Emoji", "Segoe UI Symbol";
        -webkit-font-smoothing : antialiased;
    }

    h2 {
        font-weight : 200;
        margin      : 3rem 0 1rem 0;
        opacity     : 0.5;
    }
    small {
        font-size : 0.7em;
    }
    .container {
        max-width : 1000px;
        margin    : 0 auto;
    }
    </style>
    <link rel="stylesheet" href="tcms-assets/forms.css"/>
    <link rel="stylesheet" media="print" onload="this.media='all'" href="tcms-assets/froala/froala_editor.min.css"/>
    <link rel="stylesheet" media="print" onload="this.media='all'" href="tcms-assets/froala/froala_style.min.css"/>
    <link rel="stylesheet" media="print" onload="this.media='all'" href="tcms-assets/froala/plugins/code_view.min.css"/>
    <link rel="stylesheet" media="print" onload="this.media='all'" href="tcms-assets/froala/plugins/image_manager.min.css"/>
    <link rel="stylesheet" media="print" onload="this.media='all'" href="tcms-assets/froala/plugins/image.min.css"/>
    <link rel="stylesheet" media="print" onload="this.media='all'" href="tcms-assets/froala/plugins/table.min.css"/>
    <link rel="stylesheet" media="print" onload="this.media='all'" href="tcms-assets/froala/plugins/video.min.css"/>
</head>
<body>

<?php
// Optional: Send page head asap to reduce TTFB (Time to First Byte)
echo $totalcms->processBufferMacros();
$totalcms->startBuffer(); // Start output buffering again
?>

	<div class="container">

		{% import "totalform.twig" as form %}

		{{ form.start("custom", { method:"post" }) }}

			{{ form.id('id', { autogen:"${text}" }) }}
			{{ form.text('text') }}
			{{ form.textarea('textarea') }}
			{{ form.checkbox('checkbox') }}
			{{ form.number('number') }}
			{{ form.toggle('toggle') }}
			{{ form.color('color') }}
			{{ form.date('date') }}
			{{ form.datetime('datetime') }}
			{{ form.time('time') }}
			{{ form.email('email') }}
			{{ form.phone('phone') }}
			{{ form.url('url') }}
			{{ form.password('password') }}

			{% set options = [
				{value:"dog",     label:"Dog"},
				{value:"cat",     label:"Cat"},
				{value:"hamster", label:"Hamster"},
				{value:"parrot",  label:"Parrot"},
				{value:"spider",  label:"Spider"},
				{value:"goldfish",label:"Goldfish"},
			] %}

			{{ form.select('select', options) }}
			{{ form.multiselect('multiselect', options) }}

		{{ form.end() }}

	</div>

	<script type="module" src="tcms-assets/admin.js"></script>
</body>
</html>

<?php

// Get the output buffer and process twig template
echo $totalcms->processBufferMacros();

?>
