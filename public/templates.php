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
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Total CMS Template Demo</title>
</head>
<body>

<?php

// Optional: Send page head asap to reduce TTFB (Time to First Byte)
echo $totalcms->processBufferMacros();
$totalcms->startBuffer(); // Start output buffering again

?>
    <!-- Twig Template Testing -->

    <!-- Get Collection -->
    {% set posts = totalcms.collection("text") %}

    {% for post in posts %}
        <h1>{{ post.id }}</h1>
    {% endfor %}

    <!-- Get index of a property from a collection. Ex: list of all categories -->
    {% for tag in totalcms.property("text", "id") %}
        <span class="label">{{ tag|upper }}</span>
    {% endfor %}

    <!-- Get Object -->
    {% set post = totalcms.object("text", "mytext") %}
    <h1>{{ post.id }}</h1>
    <p>{{ post.text }}</p>

    <!-- Get Text -->
    <h3>{{ totalcms.text("mytext") }}</h3>
    <h3>{{ totalcms.data("text", "mytext", "id") }}</h3>

    <!-- Macros for forms -->
    {% import "macros/forms.twig" as form %}

    <form class="totalform" action="{{ totalcms.api }}/collection/blog">

        {{ form.slug('id') }}
        {{ form.input('title') }}
        {{ form.textarea('summary') }}
        {{ form.styledtext('content') }}
        {{ form.image('image') }}
        {{ form.gallery('gallery') }}
        {{ form.list('tags') }}
        {{ form.select('author') }}

    </form>

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
