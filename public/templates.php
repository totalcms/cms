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
    .container {
        max-width : 1000px;
        margin    : 0 auto;
    }
    </style>
    <link href="dist/forms.css" rel="stylesheet"></link>
    <link rel="stylesheet" href="dist/froala/froala_editor.min.css">
    <link rel="stylesheet" href="dist/froala/froala_style.min.css">
    <link rel="stylesheet" href="dist/froala/plugins/code_view.min.css">
    <link rel="stylesheet" href="dist/froala/plugins/image_manager.min.css">
    <link rel="stylesheet" href="dist/froala/plugins/image.min.css">
    <link rel="stylesheet" href="dist/froala/plugins/table.min.css">
    <link rel="stylesheet" href="dist/froala/plugins/video.min.css">
</head>
<body>

<?php

// Optional: Send page head asap to reduce TTFB (Time to First Byte)
echo $totalcms->processBufferMacros();
$totalcms->startBuffer(); // Start output buffering again

?>
    <!-- Twig Template Testing -->

    <!-- Macros for forms -->
    {% import "form-macros.twig" as form %}

    <form class="total-form" action="{{ totalcms.api }}/collection/blog">

        <!-- form.input(name, type, input, class, value, label, placeholder, help, icon, required ) -->
        {{ form.input("mytext", "text", "text", "help-on-hover", "", "Text Input", "Text Placeholder", "This is my super help text.", true ) }}

        <!-- form.textarea(name, type, class, value, label, placeholder, help, icon, required, rows ) -->
        {{ form.textarea("mytext2", "text", "help-on-hover", "", "Textarea", "Enter some text", "This is my super help text.", true, 10 ) }}

        {% set options = [
            {"value":"option1","label":"Option 1","selected":false},
            {"value":"option2","label":"Option 2","selected":false},
            {"value":"option3","label":"Option 3","selected":false},
        ] %}

        <!-- form.select(name, type, class, value, label, placeholder, help, icon, required, options, mulitple, rows ) -->
        {{ form.select("myselect", "select", "help-on-hover", "", "Select", "Placeholder Option", "This is my super help text.", true, false, options, false, 10 ) }}
        {{ form.select("myselect", "select", "help-on-hover", "", "Select Multiple", "Placeholder Option", "This is my super help text.", true, false, options, true, 5 ) }}

        <!-- form.date(name, class, value, label, placeholder, help, icon, required ) -->
        {{ form.date("mydate", "help-on-hover", "", "Date", "Text Placeholder", "This is my super help text.", true ) }}

        <!-- form.datetime(name, class, value, label, placeholder, help, icon, required ) -->
        {{ form.datetime("mydate", "help-on-hover", "", "Date & Time", "Text Placeholder", "This is my super help text.", true) }}

        <!-- form.time(name, class, value, label, placeholder, help, icon, required ) -->
        {{ form.time("mydate", "help-on-hover", "", "Time", "Text Placeholder", "This is my super help text.", true) }}

        <!-- form.id(name, class, value, label, placeholder, help, icon, required ) -->
        {{ form.id("id", "help-on-hover") }}

        <!-- form.url(name, class, value, label, placeholder, help, icon, required ) -->
        {{ form.url("myurl", "help-on-hover", "", "URL", "Text Placeholder", "This is my super help text.", true) }}

        <!-- form.tel(name, class, value, label, placeholder, help, icon, required ) -->
        {{ form.tel("mytel", "help-on-hover", "", "Telephone", "Text Placeholder", "This is my super help text.", true) }}

        <!-- form.text(name, class, value, label, placeholder, help, icon, required ) -->
        {{ form.text("mytext", "help-on-hover", "", "Text", "Placeholder", "This is my super help text.", true) }}

        <!-- form.number(name, class, value, label, placeholder, help, icon, required, min, max, step ) -->
        {{ form.number("mynum", "help-on-hover", "", "Number", "Enter a number", "This is my super help text.", true, false, 0, 10, 0.5) }}

        <!-- form.rangeslider(name, class, value, label, placeholder, help, required, min, max, step ) -->
        {{ form.rangeslider("mynum", "help-on-hover", "", "Number", "Enter a number", "This is my super help text.", true, false, 0, 10, 0.5) }}

        <!-- form.color(name, class, value, label, placeholder, help ) -->
        {{ form.color("mycolor", "help-on-hover", "", "Color", "Pick a color", "This is my super help text.", true, false) }}

        {% set options = [
            {"value":"dog","label":"Dog","selected":true},
            {"value":"cat","label":"Cat","selected":true},
            {"value":"hampster","label":"Hampster","selected":true},
            {"value":"parrot","label":"Parrot","selected":false},
            {"value":"spider","label":"Spider","selected":false},
            {"value":"goldfish","label":"Goldfish","selected":false},
        ] %}

        <!-- form.list(name, class, value, label, placeholder, help, icon, required, options, mulitple) -->
        {{ form.list("mylist", "help-on-hover", "", "List", "", "This is my super help text.", true, false, options, true) }}

    </form>

    <!-- Get Collection -->
    {% set objects = totalcms.collection("text") %}

    {% for object in objects %}
    <h1>{{ object.id }}</h1>
    {% endfor %}

    <!-- Get index of a property from a collection. Ex: list of all categories -->
    {% for id in totalcms.property("text", "id") %}
        <span class="label">{{ id|upper }}</span>
    {% endfor %}

    <!-- Get Object -->
    {% set object = totalcms.object("text", "mytext") %}
    <h1>{{ object.id }}</h1>
    <p>{{ object.text }}</p>

    <!-- Get Text -->
    <h3>{{ totalcms.text("mytext") }}</h3>
    <h3>{{ totalcms.data("text", "mytext", "id") }}</h3>

    <!-- Depot -->
    {% for file in totalcms.depot("mydepot") %}
        <h1>{{ file.name }}</h1>
        <p>{{ file.uploadDate | date('c') }}</p>
    {% endfor %}

    <!-- Image -->
    <img src="{{ totalcms.image('myimage', 'w=200&h=400&fit=focalpoint', 'jpg') }}" alt="{{ totalcms.alt('myimage') }}">

    <p>End</p>

    <script>
    const selects = Array.from(document.querySelectorAll('.select-field select:not([multiple])'));
    const emptySelect = select => {
        select.value ? select.classList.remove('empty') : select.classList.add('empty');
    };
    selects.forEach(select => {
        emptySelect(select);
        select.addEventListener('change', e => emptySelect(e.target) );
    });
    </script>

    <script src="dist/choices/choices.js"></script>
    <script>
    const elements = Array.from(document.querySelectorAll('.list-field select'));
    elements.forEach(element => {
        element.choices = new Choices(element, {
            allowHTML             : true,
            removeItemButton      : true,
            duplicateItemsAllowed : false,
            addChoices            : true,
        });
    });
    </script>


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
