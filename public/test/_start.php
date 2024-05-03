<?php

require_once __DIR__ . '/../../vendor/autoload.php';
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
		padding   : 3rem;
    }
    </style>
    <link rel="stylesheet" href="{{ totalcms.api }}/assets/docs.css"/>
    <link rel="stylesheet" href="{{ totalcms.api }}/assets/forms.css"/>
    <link rel="stylesheet" media="print" onload="this.media='all'" href="{{ totalcms.api }}/assets/codemirror/codemirror.css"/>
    <link rel="stylesheet" media="print" onload="this.media='all'" href="{{ totalcms.api }}/assets/froala/froala_editor.min.css"/>
    <link rel="stylesheet" media="print" onload="this.media='all'" href="{{ totalcms.api }}/assets/froala/froala_style.min.css"/>
    <link rel="stylesheet" media="print" onload="this.media='all'" href="{{ totalcms.api }}/assets/froala/plugins/code_view.min.css"/>
    <link rel="stylesheet" media="print" onload="this.media='all'" href="{{ totalcms.api }}/assets/froala/plugins/image_manager.min.css"/>
    <link rel="stylesheet" media="print" onload="this.media='all'" href="{{ totalcms.api }}/assets/froala/plugins/image.min.css"/>
    <link rel="stylesheet" media="print" onload="this.media='all'" href="{{ totalcms.api }}/assets/froala/plugins/table.min.css"/>
    <link rel="stylesheet" media="print" onload="this.media='all'" href="{{ totalcms.api }}/assets/froala/plugins/video.min.css"/>
</head>
<body>

	<div class="container">