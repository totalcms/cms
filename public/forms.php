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
            {"value":"option1","label":"Option 1","selected":true},
            {"value":"option2","label":"Option 2","selected":false},
            {"value":"option3","label":"Option 3","selected":true},
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
        {{ form.rangeslider("mynum", "help-on-hover", "", "Range Slider", "This is my super help text.", true, false, 0, 10, 0.5) }}

        <!-- form.color(name, class, value, label, placeholder, help ) -->
        {{ form.color("mycolor", "help-on-hover", "", "Color", "Pick a color", "This is my super help text.", true, false) }}

        {% set options = [
            {"value":"dog","label":"Dog","selected":true},
            {"value":"cat","label":"Cat","selected":true},
            {"value":"hampster","label":"Hampster","selected":true},
            {"value":"parrot","label":"Parrot"},
            {"value":"spider","label":"Spider"},
            {"value":"goldfish","label":"Goldfish"},
        ] %}

        <!-- form.list(name, class, value, label, placeholder, help, icon, required, options, mulitple) -->
        {{ form.list("mylist", "help-on-hover", "", "List", "", "This is my super help text.", true, false, options, true) }}
        {{ form.list("mylist", "help-on-hover", "", "List", "", "This is my super help text.", true, false, options, false) }}

        <!-- form.checkbox(name, class, label, help, required) -->
        {{ form.checkbox("mycheck", "help-on-hover", "Checkbox", "This is my super help text.") }}

        <!-- form.toggle(name, class, label, help, required) -->
        {{ form.toggle("mytoggle", "help-on-hover", "Toggle", "This is my super help text.") }}

        <!-- form.radio(name, class, label, help, required, options) -->
        {{ form.radio("myradio", "help-on-hover", "Radio", "This is my super help text.", false, options) }}

        <!-- form.styledtext(name, class, value, label, placeholder, help, icon, required) -->
        {{ form.styledtext("mystyledtext", "help-on-hover", "", "Styled Text", "Add some text", "This is my super help text.", true, false) }}

        {% set duck %}
        <svg xmlns="http://www.w3.org/2000/svg" width="48" height="48" viewBox="0 0 48 48"><g><path d="M42.802 23.035C41.557 20.339 40.008 16.983 38.99 9.858C38.232 4.559 34.62 1 30 1C27.983 1 25.917 1.385 24 2.116C22.083 1.385 20.017 1 18 1C13.38 1 9.768 4.56 9.01 9.858C7.992 16.983 6.444 20.338 5.198 23.035C4.018 25.591 3 27.798 3 32C3 40.271 9.729 47 18 47H30C38.271 47 45 40.271 45 32C45 27.798 43.981 25.591 42.802 23.035Z" fill="url(#nc-goose-0_linear_318_8)"></path> <path d="M34 15C35.1046 15 36 14.1046 36 13C36 11.8954 35.1046 11 34 11C32.8954 11 32 11.8954 32 13C32 14.1046 32.8954 15 34 15Z" fill="url(#nc-goose-1_linear_318_8)"></path> <path d="M14 15C15.1046 15 16 14.1046 16 13C16 11.8954 15.1046 11 14 11C12.8954 11 12 11.8954 12 13C12 14.1046 12.8954 15 14 15Z" fill="url(#nc-goose-2_linear_318_8)"></path> <path d="M24.021 41.016C21.708 41.016 19.383 40.398 17.311 39.154L12.57 36.904C12.328 36.789 12.141 36.58 12.054 36.326C11.966 36.072 11.985 35.794 12.105 35.554L15.183 29.398C15.744 28.276 16.107 27.077 16.261 25.834L17.038 19.566C17.236 17.026 19.418 15.002 21.999 15.002C22.382 15.002 22.765 15.048 23.138 15.139C23.71 15.278 24.288 15.278 24.861 15.139C25.234 15.048 25.617 15.002 26 15.002C28.581 15.002 30.763 17.026 30.966 19.611L31.738 25.834C31.892 27.077 32.256 28.276 32.816 29.398L35.894 35.554C36.014 35.794 36.033 36.073 35.945 36.326C35.858 36.58 35.672 36.789 35.429 36.904L30.603 39.2C28.592 40.409 26.312 41.016 24.021 41.016Z" fill="url(#nc-goose-3_linear_318_8)"></path> <path d="M34.845 35.015C31.415 35.586 29.798 35.135 28.083 34.659C26.92 34.336 25.717 34.001 24.012 34.001H24.009C22.304 34.001 21.1 34.336 19.937 34.659C18.222 35.138 16.601 35.586 13.173 35.015C12.678 34.926 12.224 35.236 12.074 35.701C12.009 35.905 11.992 36.121 12.063 36.326C12.15 36.58 12.336 36.789 12.579 36.904L17.32 39.154C19.392 40.398 21.716 41.016 24.03 41.016C26.321 41.016 28.601 40.409 30.612 39.2L35.438 36.904C35.68 36.789 35.867 36.58 35.954 36.326C36.025 36.122 36.008 35.906 35.944 35.702C35.795 35.237 35.339 34.927 34.845 35.015Z" fill="url(#nc-goose-4_linear_318_8)"></path> <defs> <linearGradient id="nc-goose-0_linear_318_8" x1="24" y1="1" x2="24" y2="47" gradientUnits="userSpaceOnUse"> <stop stop-color="#E0E0E6"></stop> <stop offset="1" stop-color="#C2C3CD"></stop> </linearGradient> <linearGradient id="nc-goose-1_linear_318_8" x1="34" y1="11" x2="34" y2="15" gradientUnits="userSpaceOnUse"> <stop stop-color="#5B5E71"></stop> <stop offset="1" stop-color="#393A46"></stop> </linearGradient> <linearGradient id="nc-goose-2_linear_318_8" x1="14" y1="11" x2="14" y2="15" gradientUnits="userSpaceOnUse"> <stop stop-color="#5B5E71"></stop> <stop offset="1" stop-color="#393A46"></stop> </linearGradient> <linearGradient id="nc-goose-3_linear_318_8" x1="23.9995" y1="15.002" x2="23.9995" y2="41.016" gradientUnits="userSpaceOnUse"> <stop stop-color="#F98E5E"></stop> <stop offset="1" stop-color="#EA6524"></stop> </linearGradient> <linearGradient id="nc-goose-4_linear_318_8" x1="24.0085" y1="34.001" x2="24.0085" y2="41.016" gradientUnits="userSpaceOnUse"> <stop stop-color="#B44F18"></stop> <stop offset="1" stop-color="#EA6524"></stop> </linearGradient> </defs></g></svg>
        {% endset %}

        <!-- form.svg(name, class, value, label, placeholder, help, icon, required) -->
        {{ form.svg("mysvg", "help-on-hover", duck, "SVG", "Add some text", "This is my super help text.", true, false) }}

        <!-- form.markdown(name, class, value, label, placeholder, help, icon, required) -->
        {{ form.markdown("mymd", "help-on-hover", "", "Markdown", "Add some text", "This is my super help text.", true, false) }}
    </form>

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
            removeItemButton      : element.getAttribute('multiple') !== null ? true : false,
            duplicateItemsAllowed : false,
            addChoices            : true,
        });
    });
    </script>

    <script type="text/javascript" src="dist/codemirror/codemirror.js"></script>
    <script type="text/javascript" src="dist/codemirror/xml.js"></script>
    <script type="text/javascript" src="dist/dompurify/purify.min.js"></script>
    <script type="text/javascript" src="dist/froala/froala_editor.min.js"></script>
    <script type="text/javascript" src="dist/froala/plugins/align.min.js"></script>
    <script type="text/javascript" src="dist/froala/plugins/code_beautifier.min.js"></script>
    <script type="text/javascript" src="dist/froala/plugins/code_view.min.js"></script>
    <script type="text/javascript" src="dist/froala/plugins/draggable.min.js"></script>
    <script type="text/javascript" src="dist/froala/plugins/image.min.js"></script>
    <script type="text/javascript" src="dist/froala/plugins/image_manager.min.js"></script>
    <script type="text/javascript" src="dist/froala/plugins/link.min.js"></script>
    <script type="text/javascript" src="dist/froala/plugins/lists.min.js"></script>
    <script type="text/javascript" src="dist/froala/plugins/paragraph_format.min.js"></script>
    <script type="text/javascript" src="dist/froala/plugins/paragraph_style.min.js"></script>
    <script type="text/javascript" src="dist/froala/plugins/table.min.js"></script>
    <script type="text/javascript" src="dist/froala/plugins/video.min.js"></script>
    <script type="text/javascript" src="dist/froala/plugins/url.min.js"></script>
    <script type="text/javascript" src="dist/froala/plugins/entities.min.js"></script>
    <script>
    const styledtexts = Array.from(document.querySelectorAll('.styledtext-field textarea'));
    styledtexts.forEach(element => {
        element.editor = new FroalaEditor(element, {
            key: "zEG4iH4B11D9B5B4F4g1JWSDBCQG1ZGDf1C1d2JXDAAOZWJhE5B4E4C3F2H3C11A4C4E5==",
            attribution: false,
            heightMax: 500,
        })
    });
    </script>
    <script>
    (function () {
        const svgTags = [
            'a', 'altGlyph', 'altGlyphDef', 'altGlyphItem', 'animate', 'animateMotion', 'animateTransform',
            'circle', 'clipPath', 'cursor', 'defs', 'desc', 'discard', 'ellipse', 'feBlend', 'feColorMatrix',
            'feComponentTransfer', 'feComposite', 'feConvolveMatrix', 'feDiffuseLighting', 'feDisplacementMap',
            'feDistantLight', 'feDropShadow', 'feFlood', 'feFuncA', 'feFuncB', 'feFuncG', 'feFuncR',
            'feGaussianBlur', 'feImage', 'feMerge', 'feMergeNode', 'feMorphology', 'feOffset', 'fePointLight',
            'feSpecularLighting', 'feSpotLight', 'feTile', 'feTurbulence', 'filter', 'font-face-format',
            'font-face-name', 'font-face-src', 'font-face-uri', 'font-face', 'font', 'foreignObject', 'g',
            'glyph', 'glyphRef', 'hkern', 'image', 'line', 'linearGradient', 'marker', 'mask', 'metadata',
            'missing-glyph', 'mpath', 'path', 'pattern', 'polygon', 'polyline', 'radialGradient', 'rect', 'script',
            'set', 'stop', 'style', 'svg', 'switch', 'symbol', 'text', 'textPath', 'title', 'tref', 'tspan',
            'use', 'view', 'vkern',
        ];
        const svgAttrs = [
            'accent-height', 'accumulate', 'additive', 'alignment-baseline', 'alphabetic', 'amplitude',
            'arabic-form', 'ascent', 'attributeName', 'attributeType', 'azimuth', 'baseFrequency',
            'baseline-shift', 'baseProfile', 'bbox', 'begin', 'bias', 'by', 'calcMode', 'cap-height', 'class',
            'clip', 'clip-path', 'clip-rule', 'clipPathUnits', 'color', 'color-interpolation',
            'color-interpolation-filters', 'color-profile', 'contentScriptType', 'contentStyleType', 'cursor',
            'cx', 'cy', 'd', 'descent', 'diffuseConstant', 'direction', 'display', 'divisor', 'dominant-baseline',
            'dur', 'dx', 'dy', 'edgeMode', 'elevation', 'enable-background', 'end', 'exponent', 'fill',
            'fill-opacity', 'fill-rule', 'filter', 'filterRes', 'filterUnits', 'flood-color', 'flood-opacity',
            'font-family', 'font-size', 'font-size-adjust', 'font-stretch', 'font-style', 'font-variant',
            'font-weight', 'format', 'fr', 'from', 'fx', 'fy', 'g1', 'g2', 'glyph-name',
            'glyph-orientation-horizontal', 'glyph-orientation-vertical', 'glyphRef', 'gradientTransform',
            'gradientUnits', 'hanging', 'height', 'horiz-adv-x', 'horiz-origin-x', 'horiz-origin-y', 'href',
            'id', 'ideographic', 'image-rendering', 'in', 'in2', 'intercept', 'k', 'k1', 'k2', 'k3', 'k4',
            'kernelMatrix', 'kernelUnitLength', 'kerning', 'keyPoints', 'keySplines', 'keyTimes', 'lang',
            'lengthAdjust', 'letter-spacing', 'lighting-color', 'limitingConeAngle', 'marker-end', 'marker-mid',
            'marker-start', 'markerHeight', 'markerUnits', 'markerWidth', 'mask', 'maskContentUnits',
            'maskUnits', 'mathematical', 'max', 'media', 'method', 'min', 'mode', 'name', 'numOctaves', 'onclick',
            'opacity', 'operator', 'order', 'orient', 'orientation', 'origin', 'overflow', 'overline-position',
            'overline-thickness', 'paint-order', 'panose-1', 'path', 'pathLength', 'patternContentUnits',
            'patternTransform', 'patternUnits', 'pointer-events', 'points', 'pointsAtX', 'pointsAtY', 'pointsAtZ',
            'preserveAlpha', 'preserveAspectRatio', 'primitiveUnits', 'r', 'radius', 'refX', 'refY', 'repeatCount',
            'repeatDur', 'requiredFeatures', 'restart', 'result', 'rotate', 'rx', 'ry', 'scale', 'seed',
            'shape-rendering', 'side', 'slope', 'spacing', 'specularConstant', 'specularExponent', 'spreadMethod',
            'startOffset', 'stdDeviation', 'stemh', 'stemv', 'stitchTiles', 'stop-color', 'stop-opacity',
            'strikethrough-position', 'strikethrough-thickness', 'string', 'stroke', 'stroke-dasharray',
            'stroke-dashoffset', 'stroke-linecap', 'stroke-linejoin', 'stroke-miterlimit', 'stroke-opacity',
            'stroke-width', 'style', 'surfaceScale', 'crossorigin', 'systemLanguage', 'tabindex', 'tableValues',
            'target', 'targetX', 'targetY', 'text-anchor', 'text-decoration', 'text-rendering', 'textLength',
            'to', 'transform', 'transform-origin', 'type', 'u1', 'u2', 'underline-position', 'underline-thickness',
            'unicode', 'unicode-bidi', 'unicode-range', 'units-per-em', 'v-alphabetic', 'v-hanging',
            'v-ideographic', 'v-mathematical', 'values', 'vector-effect', 'version', 'vert-adv-y', 'vert-origin-x',
            'vert-origin-y', 'viewBox', 'viewTarget', 'visibility', 'width', 'widths', 'word-spacing',
            'writing-mode', 'x', 'x-height', 'x1', 'x2', 'xChannelSelector', 'xlink:arcrole', 'xlink:href',
            'xlink:show', 'xlink:title', 'xlink:type', 'xml:base', 'xml:lang', 'xml:space', 'xmlns', 'y', 'y1',
            'y2', 'yChannelSelector', 'z', 'zoomAndPan',
        ];
        const svgs = Array.from(document.querySelectorAll('.svg-field textarea'));
        svgs.forEach(element => {
            element.editor = new FroalaEditor(element, {
                key                  : "zEG4iH4B11D9B5B4F4g1JWSDBCQG1ZGDf1C1d2JXDAAOZWJhE5B4E4C3F2H3C11A4C4E5==",
                attribution          : false,
                heightMax            : 300,
                toolbarButtons       : [ 'html' ],
                htmlAllowedTags      : svgTags,
                htmlAllowedEmptyTags : svgTags,
                htmlAllowedAttrs     : svgAttrs,
                htmlUntouched        : true,
            }, function() {
                // element.editor.codeView.toggle()
            });
        });
    })()
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
