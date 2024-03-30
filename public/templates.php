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
    <title>Total CMS Template Demo</title>
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
    .total-form {
        max-width : 1000px;
        margin    : 0 auto;
    }
    </style>
	<link rel="stylesheet" href="tcms-assets/forms.css">
</head>
<body>

<?php

// Optional: Send page head asap to reduce TTFB (Time to First Byte)
echo $totalcms->processBufferMacros();
$totalcms->startBuffer(); // Start output buffering again

?>
    <!-- Twig Template Testing -->

    <!-- Get Collection -->
    {% set objects = totalcms.objects("text") %}

    {% for object in objects %}
    <h1>{{ object.id }}</h1>
    {% endfor %}

    <!-- Get index of a property from a collection. Ex: list of all categories -->
    {% for id in totalcms.property("text", "id") %}
        <span class="label">{{ id|upper }}</span>
    {% endfor %}

    <!-- Get Object -->
    {% set page = totalcms.object("page", "about") %}
    <h1>{{ page.title }}</h1>
    <p>{{ page.desc }}</p>

    <!-- Get Text -->
    <h3>{{ totalcms.text("mytext") }}</h3>
    <h3>{{ totalcms.data("text", "mytext", "id") }}</h3>

    <!-- Depot -->
    {% for file in totalcms.depot("mydepot") %}
        <h1>{{ file.name }}</h1>
        <p>{{ file.uploadDate | date('c') }}</p>
    {% endfor %}

    <!-- Image -->
    <img src="{{ totalcms.image('myimage',{w:600,h:500}) }}" alt="{{ totalcms.alt('myimage') }}">

	<p>End</p>


</body>
</html>

<?php

// Get the output buffer and process twig template
echo $totalcms->processBufferMacros();

?>

<!--
    The below code should live in a class that gets called via the $totalcms->processBufferMacros() above

    A custom Twig extension should be created to handle the TotalCMS API in twig

    Twig Templates Notes
    --------------------

    - All templates are stored in the root templates folder or in tcms-data/templates
    - There should be an API to save templates to the CMS
    - There will be macros to help with common Total CMS elements
    - There will be a global variable called totalcms that contains the TotalCMS object
    - Function for loading in data from the CMS via global totalcms variable. ex: {{ totalcms.load('collection/blog') }}
 -->
