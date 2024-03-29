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
    <link href="tcms-assets/forms.css" rel="stylesheet"></link>
    <link rel="stylesheet" href="tcms-assets/froala/froala_editor.min.css">
    <link rel="stylesheet" href="tcms-assets/froala/froala_style.min.css">
    <link rel="stylesheet" href="tcms-assets/froala/plugins/code_view.min.css">
    <link rel="stylesheet" href="tcms-assets/froala/plugins/image_manager.min.css">
    <link rel="stylesheet" href="tcms-assets/froala/plugins/image.min.css">
    <link rel="stylesheet" href="tcms-assets/froala/plugins/table.min.css">
    <link rel="stylesheet" href="tcms-assets/froala/plugins/video.min.css">
</head>
<body>

<?php
// Optional: Send page head asap to reduce TTFB (Time to First Byte)
echo $totalcms->processBufferMacros();
$totalcms->startBuffer(); // Start output buffering again
?>

	<div class="container">

	{% import "form-macros.twig" as form %}

	{{ form.textareaForm('mytext') }}

	</div>

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

	<script src="tcms-assets/choices/choices.js"></script>
    <script>
    const elements = Array.from(document.querySelectorAll('.list-field select'));
	elements.forEach(element => {
		const choices = new Choices(element, {
			allowHTML             : true,
			removeItemButton      : true,
			duplicateItemsAllowed : false,
			addChoices            : true,
		});
	});
    </script>

	<script type="text/javascript" src="tcms-assets/codemirror/codemirror.js"></script>
    <script type="text/javascript" src="tcms-assets/codemirror/xml.js"></script>
    <script type="text/javascript" src="tcms-assets/dompurify/purify.min.js"></script>
    <script type="text/javascript" src="tcms-assets/froala/froala_editor.min.js"></script>
    <script type="text/javascript" src="tcms-assets/froala/plugins/align.min.js"></script>
    <script type="text/javascript" src="tcms-assets/froala/plugins/code_beautifier.min.js"></script>
    <script type="text/javascript" src="tcms-assets/froala/plugins/code_view.min.js"></script>
    <script type="text/javascript" src="tcms-assets/froala/plugins/draggable.min.js"></script>
    <script type="text/javascript" src="tcms-assets/froala/plugins/image.min.js"></script>
    <script type="text/javascript" src="tcms-assets/froala/plugins/image_manager.min.js"></script>
    <script type="text/javascript" src="tcms-assets/froala/plugins/link.min.js"></script>
    <script type="text/javascript" src="tcms-assets/froala/plugins/lists.min.js"></script>
    <script type="text/javascript" src="tcms-assets/froala/plugins/paragraph_format.min.js"></script>
    <script type="text/javascript" src="tcms-assets/froala/plugins/paragraph_style.min.js"></script>
    <script type="text/javascript" src="tcms-assets/froala/plugins/table.min.js"></script>
    <script type="text/javascript" src="tcms-assets/froala/plugins/video.min.js"></script>
    <script type="text/javascript" src="tcms-assets/froala/plugins/url.min.js"></script>
    <script type="text/javascript" src="tcms-assets/froala/plugins/entities.min.js"></script>
    <script>
    (function () {
		const styledfields = Array.from(document.querySelectorAll('.styledtext-field textarea'));
		styledfields.forEach(field => {
			const editorInstance = new FroalaEditor('.styledtext-field textarea', {
				key: "zEG4iH4B11D9B5B4F4g1JWSDBCQG1ZGDf1C1d2JXDAAOZWJhE5B4E4C3F2H3C11A4C4E5==",
				attribution: false,
				heightMax: 500,
	        })
        })
    })()
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
		const svgFields = Array.from(document.querySelectorAll('.svg-field textarea'));
		svgFields.forEach(field => {
			const editor = new FroalaEditor(field, {
				key                  : "zEG4iH4B11D9B5B4F4g1JWSDBCQG1ZGDf1C1d2JXDAAOZWJhE5B4E4C3F2H3C11A4C4E5==",
				attribution          : false,
				heightMax            : 300,
				toolbarButtons       : [ 'html' ],
				htmlAllowedTags      : svgTags,
				htmlAllowedEmptyTags : svgTags,
				htmlAllowedAttrs     : svgAttrs,
				htmlUntouched        : true,
			}, function() {
				// editor.codeView.toggle()
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

<!--
https://sortablejs.github.io/Sortable/

https://fusejs.io Search
https://codepen.io/mblode/pen/VwGxaO Cool Radio Buttons
https://felixg.io/products/datedropper-javascript
https://github.com/wbotelhos/raty

https://hslpicker.com/
https://css.land/lch/
https://mdn.github.io/css-examples/tools/color-picker/ - Take this and make it look like Chrome Picker
-->