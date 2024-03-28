//-----------------------------------------------
// Total CMS SVG Field
//-----------------------------------------------
class SVGField extends TotalField {

    constructor(container, options) {
        super(container, options);
        this.initHipSvg();
    }

    setValue(value) {
        this.input.value = value;
        $(this.input).froalaEditor("html.set", value);

        if ($(this.input).froalaEditor("codeView.isActive")) {
            $(this.input).froalaEditor("codeView.toggle");
        }
    }

    getValue(value) {
        return $(this.input).froalaEditor("html.get");
    }

    initHipSvg() {
        // jQuery sad panda
        $(this.input).froalaEditor({
            toolbarButtons   : ["html"],
            htmlAllowedTags  : [
                "a","altGlyph","altGlyphDef","altGlyphItem","animate","animateColor",
                "animateMotion","animateTransform","circle","clipPath","color-profile",
                "cursor","defs","desc","discard","ellipse","feBlend","feColorMatrix",
                "feComponentTransfer","feComposite","feConvolveMatrix","feDiffuseLighting",
                "feDisplacementMap","feDistantLight","feDropShadow","feFlood","feFuncA",
                "feFuncB","feFuncG","feFuncR","feGaussianBlur","feImage","feMerge",
                "feMergeNode","feMorphology","feOffset","fePointLight","feSpecularLighting",
                "feSpotLight","feTile","feTurbulence","filter","font","font-face",
                "font-face-format","font-face-name","font-face-src","font-face-uri",
                "foreignObject","g","glyph","glyphRef","hatch","hatchpath","hkern","image",
                "line","linearGradient","marker","mask","mesh","meshgradient","meshpatch",
                "meshrow","metadata","missing-glyph","mpath","path","pattern","polygon",
                "polyline","radialGradient","rect","script","set","solidcolor","stop","style",
                "svg","switch","symbol","text","textPath","title","tref","tspan","unknown",
                "use","view","vkern"
            ],
            htmlAllowedAttrs : [
                "accent-height","accumulate","additive","alignment-baseline","allowReorder",
                "alphabetic","amplitude","arabic-form","ascent","attributeName","attributeType",
                "autoReverse","azimuth","baseFrequency","baseline-shift","baseProfile","bbox",
                "begin","bias","by","calcMode","cap-height","class","clip","clipPathUnits",
                "clip-path","clip-rule","color","color-interpolation","color-interpolation-filters",
                "color-profile","color-rendering","contentScriptType","contentStyleType","cursor",
                "cx","cy","d","decelerate","descent","diffuseConstant","direction","display",
                "divisor","dominant-baseline","dur","dx","dy","edgeMode","elevation",
                "enable-background","end","exponent","externalResourcesRequired","fill","fill-opacity",
                "fill-rule","filter","filterRes","filterUnits","flood-color","flood-opacity",
                "font-family","font-size","font-size-adjust","font-stretch","font-style","font-variant",
                "font-weight","format","from","fr","fx","fy","g1","g2","glyph-name",
                "glyph-orientation-horizontal","glyph-orientation-vertical","glyphRef","gradientTransform",
                "gradientUnits","hanging","height","href","hreflang","horiz-adv-x","horiz-origin-x","id",
                "ideographic","image-rendering","in","in2","intercept","k","k1","k2","k3","k4",
                "kernelMatrix","kernelUnitLength","kerning","keyPoints","keySplines","keyTimes",
                "lang","lengthAdjust","letter-spacing","lighting-color","limitingConeAngle","local",
                "marker-end","marker-mid","marker-start","markerHeight","markerUnits","markerWidth",
                "mask","maskContentUnits","maskUnits","mathematical","max","media","method","min","mode",
                "name","numOctaves","offset","opacity","operator","order","orient","orientation","origin",
                "overflow","overline-position","overline-thickness","panose-1","paint-order","path",
                "pathLength","patternContentUnits","patternTransform","patternUnits","ping","pointer-events",
                "points","pointsAtX","pointsAtY","pointsAtZ","preserveAlpha","preserveAspectRatio",
                "primitiveUnits","r","radius","referrerPolicy","refX","refY","rel","rendering-intent",
                "repeatCount","repeatDur","requiredExtensions","requiredFeatures","restart","result",
                "rotate","rx","ry","scale","seed","shape-rendering","slope","spacing","specularConstant",
                "specularExponent","speed","spreadMethod","startOffset","stdDeviation","stemh","stemv",
                "stitchTiles","stop-color","stop-opacity","strikethrough-position","strikethrough-thickness",
                "string","stroke","stroke-dasharray","stroke-dashoffset","stroke-linecap","stroke-linejoin",
                "stroke-miterlimit","stroke-opacity","stroke-width","style","surfaceScale","systemLanguage",
                "tabindex","tableValues","target","targetX","targetY","text-anchor","text-decoration",
                "text-rendering","textLength","to","transform","type","u1","u2","underline-position",
                "underline-thickness","unicode","unicode-bidi","unicode-range","units-per-em","v-alphabetic",
                "v-hanging","v-ideographic","v-mathematical","values","vector-effect","version","vert-adv-y",
                "vert-origin-x","vert-origin-y","viewBox","viewTarget","visibility","width","widths",
                "word-spacing","writing-mode","x","x-height","x1","x2","xChannelSelector","y","y1","y2",
                "yChannelSelector","z","zoomAndPan"
            ],
            htmlRemoveTags   : ["xml"],
            charCounterCount : false,
            htmlUntouched    : true,
            placeholderText  : this.input.getAttribute("placeholder"),
            heightMax        : 1000,
            height           : this.input.dataset.height
        });
        if (this.input.value.length === 0) {
            $(this.input).froalaEditor("codeView.toggle");
        }
    }

    schema() {
        return {
            "type":"string",
            "fieldset":"svg"
        };
    }
}

