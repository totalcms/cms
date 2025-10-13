import TotalField from "./totalfield.js";

import FroalaEditor from "froala-editor";
import "froala-editor/js/plugins/code_beautifier.min.js";
import "froala-editor/js/plugins/code_view.min.js";

//-----------------------------------------------
// Total CMS SVG Field
//-----------------------------------------------
export default class SVGField extends TotalField {

    constructor(container, options) {
        super(container, options);
        this.initFroala();
    }

    setValue(value) {
        this.input.value = value;
		this.froala.html.set(value);
		this.changed();
    }

    getValue() {
        // Check if Froala is initialized before trying to get the value
        if (this.froala && this.froala.html) {
            // If in code view, toggle out and back in to sync content
            if (this.froala.codeView && this.froala.codeView.isActive()) {
                // Froala automatically syncs when toggling
                this.froala.codeView.toggle();
                this.froala.codeView.toggle();
            }
            return this.froala.html.get();
        }
        // Fall back to input value if Froala is not ready
        return this.input.value;
    }

    validate() {
        // Sync the value from CodeMirror/Froala to the textarea before validation
        if (this.froala && this.froala.html) {
            // If in code view, toggle out and back in to sync content
            if (this.froala.codeView && this.froala.codeView.isActive()) {
                // Froala automatically syncs content when toggling code view
                this.froala.codeView.toggle();
                this.froala.codeView.toggle();
            }
            // Update the underlying textarea with Froala's content
            this.input.value = this.froala.html.get();
        }

        // Call parent validate method
        return super.validate();
    }

    initFroala() {
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
        const codeMirrorOptions = {
            indentWithTabs : true,
            lineNumbers    : true,
            lineWrapping   : true,
            readOnly       : false,
            autofocus      : false,
            mode           : "text/html",
            tabMode        : "indent",
            tabSize        : 2
        };
		this.froala = new FroalaEditor(this.input, {
			key                  : "zEG4iH4B11D9B5B4F4g1JWSDBCQG1ZGDf1C1d2JXDAAOZWJhE5B4E4C3F2H3C11A4C4E5==",
			attribution          : false,
			heightMin            : 200,
			heightMax            : 500,
			toolbarButtons       : ["html"],
			htmlRemoveTags       : ["xml"],
			htmlAllowedTags      : svgTags,
			htmlAllowedEmptyTags : svgTags,
			htmlAllowedAttrs     : svgAttrs,
			charCounterCount     : false,
			htmlUntouched        : true,
            placeholderText      : "Enter Code View to add SVG content",
			codeMirror           : window.CodeMirror,
			codeMirrorOptions    : codeMirrorOptions,
			quickInsertEnabled   : false,
			wordCounterCount     : false,
			events               : {
				'initialized': function() {
                    // Go into code editor if there is no SVG set
					if (this.html.get().length === 0) {
                        // TODO: Figure out how to enable CodeView without stealing focus
                        // this.codeView.toggle();
					}
				},
				'codeView.update': function() {
					// Ensure CodeMirror is properly refreshed when entering code view
					setTimeout(() => {
						if (this.codeView.isActive()) {
							const codeMirror = this.codeView.get();
							if (codeMirror && codeMirror.refresh) {
								codeMirror.refresh();
								// Make sure CodeMirror is not read-only
								codeMirror.setOption('readOnly', false);
							}
						}
					}, 100);
				},
				'contentChanged': () => this.changed(),
			}
        });
    }

    schema() {
        return {
            "type"  : "string",
            "field" : "svg"
        };
    }
}

