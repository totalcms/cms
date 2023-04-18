<?php

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

    <!-- Twig Template Ideas -->

    <!-- Get Collection -->
    {% set posts = totalcms.collection("blog") %}
    <!-- Get index of a property from a collection. Ex: list of all categories -->
    {% set tags = totalcms.property("blog","tags") %}
    <!-- Get Object -->
    {% set post = totalcms.object("blog", "permalink") %}

    {% for post in posts %}
        <h1>{{ post.title }}</h1>
    {% endfor %}

    {% for tag in tags %}
        <span class="label">{{ tag|capitalize }}</span>
    {% endfor %}

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


<?php

$loader = new \Twig\Loader\FilesystemLoader(
    '/templates',
    '/tcms-data/templates',
);
$twig = new \Twig\Environment($loader, [
    'cache' => '/cache',
]);
$twig->addGlobal('totalcms', new TotalCMSTwigIntegration());

$filter = new \Twig\TwigFilter('wordcount', 'TotalCMSTwigIntegration::wordcount');
$twig->addFilter($filter);
