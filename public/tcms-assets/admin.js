var __create = Object.create;
var __defProp = Object.defineProperty;
var __getOwnPropDesc = Object.getOwnPropertyDescriptor;
var __getOwnPropNames = Object.getOwnPropertyNames;
var __getProtoOf = Object.getPrototypeOf;
var __hasOwnProp = Object.prototype.hasOwnProperty;
var __name = (target, value) => __defProp(target, "name", { value, configurable: true });
var __commonJS = (cb, mod) => function __require() {
  return mod || (0, cb[__getOwnPropNames(cb)[0]])((mod = { exports: {} }).exports, mod), mod.exports;
};
var __copyProps = (to, from, except, desc) => {
  if (from && typeof from === "object" || typeof from === "function") {
    for (let key of __getOwnPropNames(from))
      if (!__hasOwnProp.call(to, key) && key !== except)
        __defProp(to, key, { get: () => from[key], enumerable: !(desc = __getOwnPropDesc(from, key)) || desc.enumerable });
  }
  return to;
};
var __toESM = (mod, isNodeMode, target) => (target = mod != null ? __create(__getProtoOf(mod)) : {}, __copyProps(
  // If the importer is in node compatibility mode or this is not an ESM
  // file that has been converted to a CommonJS file using a Babel-
  // compatible transform (i.e. "__esModule" has not been set), then set
  // "default" to the CommonJS "module.exports" for node compatibility.
  isNodeMode || !mod || !mod.__esModule ? __defProp(target, "default", { value: mod, enumerable: true }) : target,
  mod
));

// javascript/totalform/checkbox.js
var require_checkbox = __commonJS({
  "javascript/totalform/checkbox.js"() {
    "use strict";
    var Checkbox2 = class extends TotalField {
      setValue(value) {
        this.input.checked = value === true || value === "true" || value === 1;
        this.changed();
      }
      getValue() {
        return this.input.checked;
      }
      schema() {
        return {
          "type": "boolean",
          "fieldset": "checkbox"
        };
      }
    };
    __name(Checkbox2, "Checkbox");
  }
});

// javascript/totalform/markdown.js
var require_markdown = __commonJS({
  "javascript/totalform/markdown.js"() {
    "use strict";
    var MarkdownField2 = class extends TotalField {
      constructor(container, options) {
        super(container, options);
        this.options = Object.assign({}, this.defaultConfig(), window.totalcms.getConfig("styledtext"), this.options);
      }
      schema() {
        return {
          "type": "markdown",
          "input": "textarea"
        };
      }
    };
    __name(MarkdownField2, "MarkdownField");
  }
});

// javascript/totalform/svg.js
var require_svg = __commonJS({
  "javascript/totalform/svg.js"() {
    "use strict";
    var SVGField3 = class extends TotalField {
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
        $(this.input).froalaEditor({
          toolbarButtons: ["html"],
          htmlAllowedTags: [
            "a",
            "altGlyph",
            "altGlyphDef",
            "altGlyphItem",
            "animate",
            "animateColor",
            "animateMotion",
            "animateTransform",
            "circle",
            "clipPath",
            "color-profile",
            "cursor",
            "defs",
            "desc",
            "discard",
            "ellipse",
            "feBlend",
            "feColorMatrix",
            "feComponentTransfer",
            "feComposite",
            "feConvolveMatrix",
            "feDiffuseLighting",
            "feDisplacementMap",
            "feDistantLight",
            "feDropShadow",
            "feFlood",
            "feFuncA",
            "feFuncB",
            "feFuncG",
            "feFuncR",
            "feGaussianBlur",
            "feImage",
            "feMerge",
            "feMergeNode",
            "feMorphology",
            "feOffset",
            "fePointLight",
            "feSpecularLighting",
            "feSpotLight",
            "feTile",
            "feTurbulence",
            "filter",
            "font",
            "font-face",
            "font-face-format",
            "font-face-name",
            "font-face-src",
            "font-face-uri",
            "foreignObject",
            "g",
            "glyph",
            "glyphRef",
            "hatch",
            "hatchpath",
            "hkern",
            "image",
            "line",
            "linearGradient",
            "marker",
            "mask",
            "mesh",
            "meshgradient",
            "meshpatch",
            "meshrow",
            "metadata",
            "missing-glyph",
            "mpath",
            "path",
            "pattern",
            "polygon",
            "polyline",
            "radialGradient",
            "rect",
            "script",
            "set",
            "solidcolor",
            "stop",
            "style",
            "svg",
            "switch",
            "symbol",
            "text",
            "textPath",
            "title",
            "tref",
            "tspan",
            "unknown",
            "use",
            "view",
            "vkern"
          ],
          htmlAllowedAttrs: [
            "accent-height",
            "accumulate",
            "additive",
            "alignment-baseline",
            "allowReorder",
            "alphabetic",
            "amplitude",
            "arabic-form",
            "ascent",
            "attributeName",
            "attributeType",
            "autoReverse",
            "azimuth",
            "baseFrequency",
            "baseline-shift",
            "baseProfile",
            "bbox",
            "begin",
            "bias",
            "by",
            "calcMode",
            "cap-height",
            "class",
            "clip",
            "clipPathUnits",
            "clip-path",
            "clip-rule",
            "color",
            "color-interpolation",
            "color-interpolation-filters",
            "color-profile",
            "color-rendering",
            "contentScriptType",
            "contentStyleType",
            "cursor",
            "cx",
            "cy",
            "d",
            "decelerate",
            "descent",
            "diffuseConstant",
            "direction",
            "display",
            "divisor",
            "dominant-baseline",
            "dur",
            "dx",
            "dy",
            "edgeMode",
            "elevation",
            "enable-background",
            "end",
            "exponent",
            "externalResourcesRequired",
            "fill",
            "fill-opacity",
            "fill-rule",
            "filter",
            "filterRes",
            "filterUnits",
            "flood-color",
            "flood-opacity",
            "font-family",
            "font-size",
            "font-size-adjust",
            "font-stretch",
            "font-style",
            "font-variant",
            "font-weight",
            "format",
            "from",
            "fr",
            "fx",
            "fy",
            "g1",
            "g2",
            "glyph-name",
            "glyph-orientation-horizontal",
            "glyph-orientation-vertical",
            "glyphRef",
            "gradientTransform",
            "gradientUnits",
            "hanging",
            "height",
            "href",
            "hreflang",
            "horiz-adv-x",
            "horiz-origin-x",
            "id",
            "ideographic",
            "image-rendering",
            "in",
            "in2",
            "intercept",
            "k",
            "k1",
            "k2",
            "k3",
            "k4",
            "kernelMatrix",
            "kernelUnitLength",
            "kerning",
            "keyPoints",
            "keySplines",
            "keyTimes",
            "lang",
            "lengthAdjust",
            "letter-spacing",
            "lighting-color",
            "limitingConeAngle",
            "local",
            "marker-end",
            "marker-mid",
            "marker-start",
            "markerHeight",
            "markerUnits",
            "markerWidth",
            "mask",
            "maskContentUnits",
            "maskUnits",
            "mathematical",
            "max",
            "media",
            "method",
            "min",
            "mode",
            "name",
            "numOctaves",
            "offset",
            "opacity",
            "operator",
            "order",
            "orient",
            "orientation",
            "origin",
            "overflow",
            "overline-position",
            "overline-thickness",
            "panose-1",
            "paint-order",
            "path",
            "pathLength",
            "patternContentUnits",
            "patternTransform",
            "patternUnits",
            "ping",
            "pointer-events",
            "points",
            "pointsAtX",
            "pointsAtY",
            "pointsAtZ",
            "preserveAlpha",
            "preserveAspectRatio",
            "primitiveUnits",
            "r",
            "radius",
            "referrerPolicy",
            "refX",
            "refY",
            "rel",
            "rendering-intent",
            "repeatCount",
            "repeatDur",
            "requiredExtensions",
            "requiredFeatures",
            "restart",
            "result",
            "rotate",
            "rx",
            "ry",
            "scale",
            "seed",
            "shape-rendering",
            "slope",
            "spacing",
            "specularConstant",
            "specularExponent",
            "speed",
            "spreadMethod",
            "startOffset",
            "stdDeviation",
            "stemh",
            "stemv",
            "stitchTiles",
            "stop-color",
            "stop-opacity",
            "strikethrough-position",
            "strikethrough-thickness",
            "string",
            "stroke",
            "stroke-dasharray",
            "stroke-dashoffset",
            "stroke-linecap",
            "stroke-linejoin",
            "stroke-miterlimit",
            "stroke-opacity",
            "stroke-width",
            "style",
            "surfaceScale",
            "systemLanguage",
            "tabindex",
            "tableValues",
            "target",
            "targetX",
            "targetY",
            "text-anchor",
            "text-decoration",
            "text-rendering",
            "textLength",
            "to",
            "transform",
            "type",
            "u1",
            "u2",
            "underline-position",
            "underline-thickness",
            "unicode",
            "unicode-bidi",
            "unicode-range",
            "units-per-em",
            "v-alphabetic",
            "v-hanging",
            "v-ideographic",
            "v-mathematical",
            "values",
            "vector-effect",
            "version",
            "vert-adv-y",
            "vert-origin-x",
            "vert-origin-y",
            "viewBox",
            "viewTarget",
            "visibility",
            "width",
            "widths",
            "word-spacing",
            "writing-mode",
            "x",
            "x-height",
            "x1",
            "x2",
            "xChannelSelector",
            "y",
            "y1",
            "y2",
            "yChannelSelector",
            "z",
            "zoomAndPan"
          ],
          htmlRemoveTags: ["xml"],
          charCounterCount: false,
          htmlUntouched: true,
          placeholderText: this.input.getAttribute("placeholder"),
          heightMax: 1e3,
          height: this.input.dataset.height
        });
        if (this.input.value.length === 0) {
          $(this.input).froalaEditor("codeView.toggle");
        }
      }
      schema() {
        return {
          "type": "string",
          "fieldset": "svg"
        };
      }
    };
    __name(SVGField3, "SVGField");
  }
});

// javascript/totalform/select.js
var require_select = __commonJS({
  "javascript/totalform/select.js"() {
    "use strict";
    var SelectField3 = class extends TotalField {
      constructor(container, options) {
        super(...arguments);
        this.select = container.querySelector("select");
        this.templates = Array.from(this.select.querySelectorAll("template"));
        if (this.templates)
          this.processTemplates();
      }
      sort() {
        const tmpAry = new Array();
        for (let i = 0; i < this.select.options.length; i++) {
          tmpAry[i] = new Array();
          tmpAry[i][0] = this.select.options[i].text;
          tmpAry[i][1] = this.select.options[i].value;
        }
        tmpAry.sort();
        while (this.select.options.length > 0) {
          this.select.options[0] = null;
        }
        for (let i = 0; i < tmpAry.length; i++) {
          const op = new Option(tmpAry[i][0], tmpAry[i][1]);
          this.select.options[i] = op;
        }
        return;
      }
      processTemplates() {
        this.templates.forEach((template) => {
          const collection = template.dataset.collection;
          if (!collection) {
            console.warn("No collection defined for select template");
            return;
          }
          this.api.fetchAPI(`/collections/${collection}`).then((data) => {
            data.map((object) => this.api.processTemplate(object, template.innerHTML, this.select));
            this.sort();
          });
        });
      }
      setValue(value) {
        this.input.value = value;
        const options = Array.from(this.input.getElementsByTagName("option"));
        for (const option of options) {
          if (option.value.trim() === value.trim()) {
            option.selected = true;
            break;
          }
        }
        this.changed();
      }
      schema() {
        return {
          "type": "string",
          "fieldset": "select"
        };
      }
    };
    __name(SelectField3, "SelectField");
  }
});

// javascript/totalform/multiselect.js
var require_multiselect = __commonJS({
  "javascript/totalform/multiselect.js"() {
    "use strict";
    var MultiSelectField2 = class extends SelectField {
      constructor(container, options) {
        super(...arguments);
      }
      getValue() {
        const data = [];
        const options = this.input.querySelectorAll("option");
        for (const option of options) {
          if (option.selected)
            data.push(option.value);
        }
        return data;
      }
      setValue(value) {
        if (typeof value !== "object") {
          console.error(`Unable to set value for multiselect: ${this.input.id}`);
        }
        const options = Array.from(this.input.getElementsByTagName("option"));
        for (const option of options) {
          if (value.indexOf(option.value) >= 0) {
            option.selected = true;
          }
        }
        this.changed();
      }
      schema() {
        return {
          "type": "array",
          "fieldset": "multiselect"
        };
      }
    };
    __name(MultiSelectField2, "MultiSelectField");
  }
});

// javascript/totalform/number.js
var require_number = __commonJS({
  "javascript/totalform/number.js"() {
    "use strict";
    var NumberField3 = class extends TotalField {
      getValue() {
        return parseFloat(this.input.value);
      }
      schema() {
        return {
          "type": "number",
          "fieldset": "number"
        };
      }
    };
    __name(NumberField3, "NumberField");
  }
});

// javascript/totalform/identifier.js
var require_identifier = __commonJS({
  "javascript/totalform/identifier.js"() {
    "use strict";
    var Identifier2 = class extends TotalField {
      constructor(container, options) {
        super(container, options);
        const defaults = {
          autogen: "title"
        };
        this.options = Object.assign({}, this.options, defaults, options);
        this.titleNode = this.form.find(`[name=${this.options.autogen}]`);
        if (this.input && this.titleNode) {
          this.onChangeEvents();
        } else {
          console.error("Unable to find permalink and title fields");
        }
        this.id = this.permalinkValue();
      }
      onFieldChange(field, callback) {
        field.addEventListener("change", (event) => {
          if (!this.input.classList.contains("locked")) {
            this.input.value = callback();
            this.checkPermalink();
          }
        });
      }
      onChangeEvents() {
        if (this.input.classList.contains("mustache")) {
          const fields = this.form.findAll("input,textarea,select");
          fields.forEach((field) => this.onFieldChange(field, () => this.templateTitle()));
        } else {
          this.onFieldChange(this.titleNode, () => this.urlifyTitle(this.titleNode.value));
        }
        this.input.addEventListener("change", (event) => {
          this.input.classList.add("locked");
          this.checkPermalink();
        });
      }
      templateTitle() {
        const title = Mustache.render(this.input.dataset.template, this.form.generateData());
        return this.urlifyTitle(title);
      }
      urlifyTitle(title) {
        return slugify(title).toLowerCase();
      }
      permalinkExists() {
        this.log.info("Permalink Exists");
        this.input.classList.remove("saving", "success");
        this.input.classList.add("error");
      }
      permalinkAvailable() {
        this.log.info("Permalink Available");
        this.input.classList.remove("saving", "error");
        this.input.classList.add("success");
      }
      processCheck(response) {
        this.log.debug("Permalink Check", response);
        if (response === true) {
          this.permalinkExists();
        } else {
          this.permalinkAvailable();
        }
        const event = new Event("permalinkChange");
        this.input.dispatchEvent(event);
      }
      permalinkValue() {
        let permalinkValue = this.input.value;
        if (permalinkValue.length === 0) {
          console.warn("Permalink cannot be empty");
          return false;
        }
        if (this.input.dataset.suffix) {
          permalinkValue = `${permalinkValue}-${this.input.dataset.suffix}`;
        }
        if (this.input.value !== permalinkValue) {
          this.input.value = permalinkValue;
        }
        this.id = permalinkValue;
        return this.id;
      }
      checkPermalink() {
        this.permalinkValue();
        this.api.fetchAPI(`/collections/${this.form.collection}/${this.id}/exists`).then((response) => this.processCheck(response));
      }
    };
    __name(Identifier2, "Identifier");
  }
});

// javascript/totalform/rangeslider.js
var require_rangeslider = __commonJS({
  "javascript/totalform/rangeslider.js"() {
    "use strict";
    var RangeSlider3 = class extends NumberField {
      constructor(container, options) {
        super(container, options);
        const defaults = {
          precision: null,
          unit: null,
          unitPrepend: false
        };
        this.options = Object.assign({}, this.options, defaults, options);
        this.initRangeSlider();
      }
      createHandleValue() {
        const handleValue = document.createElement("div");
        handleValue.classList.add("rangeslider__handle__value");
        handleValue.innerHTML = this.valueWithUnit(this.input.value);
        const handle = this.container.getElementsByClassName("rangeslider__handle")[0];
        handle.appendChild(handleValue);
      }
      createRangeLabels() {
        let rangeLabels = this.input.getAttribute("labels");
        if (!rangeLabels)
          return;
        rangeLabels = rangeLabels.replace(/\s+/g, "").split(",");
        if (rangeLabels.length === 0)
          return;
        const labelsContainer = document.createElement("div");
        labelsContainer.classList.add("rangeslider__labels");
        $(rangeLabels).each(function(index, value) {
          const label = document.createElement("span");
          label.classList.add("rangeslider__labels__label");
          label.innerHTML = value;
          labelsContainer.append(label);
        });
        const rangeslider = this.container.getElementsByClassName("rangeslider")[0];
        rangeslider.appendChild(labelsContainer);
      }
      buildCustomUI() {
        this.createHandleValue();
        this.createRangeLabels();
      }
      valueWithUnit(value) {
        if (this.options.precision) {
          value = Number.parseFloat(value).toFixed(this.options.precision);
        }
        if (this.options.unit) {
          value = this.options.unitPrepend ? `${this.options.unit}${value}` : `${value}${this.options.unit}`;
        }
        return value;
      }
      updateHandleValue(position, value) {
        const handle = this.container.getElementsByClassName("rangeslider__handle__value")[0];
        handle.innerHTML = this.valueWithUnit(value);
      }
      setValue(value) {
        $(this.input).val(value).change();
      }
      initRangeSlider() {
        $(this.input).rangeslider({
          polyfill: false,
          rangeClass: "rangeslider",
          disabledClass: "rangeslider--disabled",
          horizontalClass: "rangeslider--horizontal",
          fillClass: "rangeslider__fill",
          handleClass: "rangeslider__handle",
          onInit: () => {
            this.buildCustomUI();
          },
          onSlide: (position, value) => {
            this.updateHandleValue(position, value);
          },
          onSlideEnd: (position, value) => {
          }
        });
        this.input.addEventListener("change", (event) => {
          this.input.rangeslider("update", true);
        });
      }
    };
    __name(RangeSlider3, "RangeSlider");
  }
});

// javascript/totalform/colorpicker.js
var require_colorpicker = __commonJS({
  "javascript/totalform/colorpicker.js"() {
    "use strict";
    $.fn.spectrum.load = false;
    var ColorPicker2 = class extends TotalField {
      constructor(container, options) {
        super(container, options);
        const defaults = {
          preferredFormat: "rgb",
          color: "#fff",
          allowEmpty: false,
          flat: false,
          showInput: true,
          showInitial: true,
          showAlpha: true,
          showButtons: false,
          clickoutFiresChange: true,
          appendTo: this.container,
          showPalette: true,
          showPaletteOnly: false,
          togglePaletteOnly: false,
          localStorageKey: "colorpicker",
          showSelectionPalette: true,
          maxSelectionSize: 9,
          togglePaletteMoreText: " ",
          togglePaletteLessText: " ",
          palette: [
            ["red", "green", "yellow", "blue", "violet", "black", "white"]
          ]
          // cancelText: "",
          // chooseText: "",
          // containerClassName: string,
          // replacerClassName: string,
          // selectionPalette: [string]
          // disabled: bool,
        };
        if (options.palette && typeof options.palette === "string") {
          options.palette = [options.palette.trim().split(/\s*,\s*/)];
        }
        this.options = Object.assign({}, this.options, defaults, options);
        $(this.input).spectrum(this.options);
        this.previewColor = document.querySelectorAll(`[data-pickercolor=${this.input.name}]`);
        $(this.input).on("dragstart.spectrum dragstop.spectrum change.spectrum", (e, color) => {
          this.previewColor.forEach((el) => el.style.backgroundColor = color.toRgbString());
        });
      }
      setValue(color) {
        this.setRgb(...color.rgb, color.alpha);
      }
      setColor(color) {
        this.input.value = color;
        $(this.input).spectrum("set", color);
        this.previewColor.forEach((el) => el.style.backgroundColor = color);
      }
      setHex(color) {
        const hex = `#${color}`.replace("##", "#");
        this.setColor(hex);
      }
      setRgb(red, green, blue, alpha = 1) {
        const rgba = `rgba(${red},${green},${blue},${alpha})`;
        this.setColor(rgba);
      }
      setHsl(h, s, l, alpha = 1) {
        const hsla = `hsla(${h},${s}%,${l}%,${alpha})`;
        this.setColor(hsla);
      }
      schema() {
        return {
          "type": "object",
          "fieldset": "color"
        };
      }
    };
    __name(ColorPicker2, "ColorPicker");
  }
});

// javascript/totalform/datepicker.js
var require_datepicker = __commonJS({
  "javascript/totalform/datepicker.js"() {
    "use strict";
    var DatePicker3 = class extends TotalField {
      constructor(container, options) {
        super(container, options);
        const defaults = {
          // decade=4, year=3, month=2, day=1, hour=0
          startView: 2,
          minView: 2,
          maxView: 4,
          // formats: dd, mm, yyyy, hh, ii
          format: "mm/dd/yyyy",
          startDate: null,
          endDate: null,
          initialDate: null,
          daysOfWeekDisabled: [],
          datesDisabled: []
        };
        this.options = Object.assign({}, this.options, defaults, options);
        this.momentFormat = this.toMomentFormat(this.options.format);
        this.convertDaysOfWeek();
        this.convertDisabledDates();
        this.convertDates();
        this.initDatePicker();
      }
      toMomentFormat(format) {
        format = format || this.options.format;
        return format.replace(/d/g, "D").replace(/m/g, "M").replace(/y/g, "Y").replace(/h/g, "H").replace(/i/g, "s");
      }
      stringToDate(string) {
        if (typeof string !== "string") {
          return null;
        }
        string = string.trim();
        if (string === "today") {
          return new Date();
        }
        const matchAdd = /today\+(\d+)(\w+)/;
        if (string.match(matchAdd)) {
          const [match, num, unit] = string.match(matchAdd);
          return moment().add(Number.parseInt(num), unit).toDate();
        }
        const matchSubtract = /today-(\d+)(\w+)/;
        if (string.match(matchSubtract)) {
          const [match, num, unit] = string.match(matchSubtract);
          return moment().subtract(Number.parseInt(num), unit).toDate();
        }
        const date = moment(string, this.momentFormat);
        return date.isValid() ? date.toDate() : null;
      }
      convertDaysOfWeek() {
        if (typeof this.options.daysOfWeekDisabled === "string") {
          this.options.daysOfWeekDisabled = this.stringToArray(this.options.daysOfWeekDisabled);
        }
      }
      convertDisabledDates() {
        if (typeof this.options.datesDisabled === "string") {
          this.options.datesDisabled = this.stringToArray(this.options.datesDisabled);
        }
        const now = moment();
        this.options.datesDisabled = this.options.datesDisabled.map((date) => {
          if (date.match(/^\d+[/.-]\d+$/)) {
            const match = moment(date, "MM/DD");
            if (match.isBefore(now)) {
              match.add(1, "year");
            }
            return match.toDate();
          }
          return this.stringToDate(date);
        });
      }
      convertDates() {
        this.options.initialDate = this.stringToDate(this.options.initialDate);
        this.options.startDate = this.stringToDate(this.options.startDate);
        this.options.endDate = this.stringToDate(this.options.endDate);
      }
      toTimestamp() {
        return moment(this.input.value, this.momentFormat).utc().format();
      }
      getValue() {
        return this.toTimestamp();
      }
      setValue(newtime) {
        if (newtime.match(/^\d+$/))
          newtime = moment(newtime * 1e3).utc().format();
        this.input.dataset.timestamp = newtime;
        this.input.value = moment(newtime).format(this.toMomentFormat());
      }
      schema() {
        return {
          "type": "string",
          "fieldset": "date"
        };
      }
      initDatePicker() {
        $(this.input).fdatepicker({
          initialDate: this.options.initialDate,
          language: this.options.locale,
          startView: this.options.startView,
          minView: this.options.minView,
          maxView: this.options.maxView,
          format: this.options.format,
          startDate: this.options.startDate,
          endDate: this.options.endDate,
          leftArrow: '<?xml version="1.0"?><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 320 512"><path d="M153.1 247.5l117.8-116c4.7-4.7 12.3-4.7 17 0l7.1 7.1c4.7 4.7 4.7 12.3 0 17L192.7 256l102.2 100.4c4.7 4.7 4.7 12.3 0 17l-7.1 7.1c-4.7 4.7-12.3 4.7-17 0L153 264.5c-4.6-4.7-4.6-12.3.1-17zm-128 17l117.8 116c4.7 4.7 12.3 4.7 17 0l7.1-7.1c4.7-4.7 4.7-12.3 0-17L64.7 256l102.2-100.4c4.7-4.7 4.7-12.3 0-17l-7.1-7.1c-4.7-4.7-12.3-4.7-17 0L25 247.5c-4.6 4.7-4.6 12.3.1 17z"></path></svg>',
          rightArrow: '<?xml version="1.0"?><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 320 512"><path d="M166.9 264.5l-117.8 116c-4.7 4.7-12.3 4.7-17 0l-7.1-7.1c-4.7-4.7-4.7-12.3 0-17L127.3 256 25.1 155.6c-4.7-4.7-4.7-12.3 0-17l7.1-7.1c4.7-4.7 12.3-4.7 17 0l117.8 116c4.6 4.7 4.6 12.3-.1 17zm128-17l-117.8-116c-4.7-4.7-12.3-4.7-17 0l-7.1 7.1c-4.7 4.7-4.7 12.3 0 17L255.3 256 153.1 356.4c-4.7 4.7-4.7 12.3 0 17l7.1 7.1c4.7 4.7 12.3 4.7 17 0l117.8-116c4.6-4.7 4.6-12.3-.1-17z"></path></svg>',
          closeButton: false,
          keyboardNavigation: true,
          daysOfWeekDisabled: this.options.daysOfWeekDisabled,
          datesDisabled: this.options.datesDisabled
        }).on("input change changeDate", (el) => {
          this.input.dataset.timestamp = this.toTimestamp();
        });
      }
    };
    __name(DatePicker3, "DatePicker");
  }
});

// javascript/totalform/droplet.js
var require_droplet = __commonJS({
  "javascript/totalform/droplet.js"() {
    "use strict";
    var Droplet3 = class extends TotalField {
      constructor(container, options) {
        super(container, options);
        this.name = this.container.dataset.name || "file";
        const defaults = {
          autoProcessQueue: false,
          previewsContainer: this.container.getElementsByClassName("total-preview").item(0),
          previewTemplate: "",
          acceptedFiles: "image/*",
          paramName: this.name,
          requestHeaders: {},
          type: "file",
          form: null,
          gallery: false
        };
        const globals = typeof window.totalcms === "object" ? window.totalcms.options : {};
        this.options = Object.assign({}, globals, defaults, options);
        this.options.gallery = this.options.type === "gallery" || this.options.type === "depot";
        if (this.options.requestHeaders["Content-Type"]) {
          delete this.options.requestHeaders["Content-Type"];
        }
        this.log.debug(this.constructor.name + " Options", this.options);
        if (this.container.dataset.rules) {
          const rules = JSON.parse(this.container.dataset.rules);
          this.testSet = new DropletTestSet(rules);
        }
        this.form = this.options.form;
        if (!this.form)
          console.error("Droplet: No form defined");
        this.setupDropzone();
      }
      schema() {
        return {
          type: "object",
          fieldset: this.options.type
        };
      }
      getValue() {
        return this.input.value.length > 0 ? JSON.parse(this.input.value) : {};
      }
      setValue(image) {
        if (image === null || !image.filename) {
          console.warn("Image object not valid", image);
          return;
        }
        this.input.value = JSON.stringify(image);
        const imageWorks = new ImageWorks({
          collection: this.form.collection,
          id: this.form.id,
          property: this.name,
          file: image.filename,
          date: image.uploadDate
        });
        const rules = JSON.parse(this.container.dataset.imageworks);
        const imageQuery = imageWorks.buildQuery(rules);
        const preview = this.container.querySelectorAll(".total-preview").item(0);
        this.log.debug("image query", imageQuery);
        this.api.fetchCachedAPI("/templates/admin/image").then((json) => {
          this.api.processTemplate({ "image": imageQuery }, json.template, preview);
        });
      }
      apiUrl() {
        const components = [this.options.uri, "collections", this.form.collection, this.form.id, this.name];
        this.log.debug("Droplet API Components:", components);
        return components.join("/");
      }
      autoProcessQueue() {
        if (this.dropzone) {
          this.log.info("Enabled autoProcessQueue");
          this.dropzone.options.autoProcessQueue = true;
        } else {
          this.log.warn("Unable to enable autoProcessQueue");
        }
      }
      updateUri() {
        if (this.dropzone) {
          this.log.info("Updated dropzone URI");
          this.dropzone.options.url = this.apiUrl();
        } else {
          this.log.warn("Unable to update dropzone URI");
        }
      }
      newDropzone() {
        const disableFunction = /* @__PURE__ */ __name(function() {
        }, "disableFunction");
        return new Dropzone(this.container, {
          url: this.apiUrl(),
          method: "post",
          headers: this.options.requestHeaders,
          parallelUploads: 1,
          paramName: this.options.paramName,
          autoProcessQueue: this.form.form.classList.contains("edit-form"),
          thumbnailWidth: null,
          thumbnailHeight: null,
          previewsContainer: this.options.previewsContainer,
          previewTemplate: this.previewTemplate,
          clickable: [
            this.container.getElementsByClassName("dz-clickable").item(0)
            // this.container.getElementsByTagName("img").item(0)
          ],
          forceFallback: false,
          addedfile: disableFunction,
          acceptedFiles: this.options.acceptedFiles,
          accept: this.accept
        });
      }
      setupDropzone() {
        this.api.fetchCachedAPI("/templates/admin/image").then((json) => {
          this.previewTemplate = json.template;
          this.dropzone = this.newDropzone();
          this.dropzone.on("addedfile", (file) => this.event_addedfile(file));
          this.dropzone.on("thumbnail", (file, data) => this.event_thumbnail(file, data));
          this.dropzone.on("uploadprogress", (file, progress, bytes) => this.event_uploadprogress(file, progress, bytes));
          this.dropzone.on("error", (file, message) => this.event_error(file, message));
          this.dropzone.on("sending", (file, xhr, formData) => this.event_sending(file, xhr, formData));
          this.dropzone.on("success", (file, xhr, formData) => this.event_success(file, xhr, formData));
          this.dropzone.on("dragenter", (event) => this.event_dragenter(event));
          this.dropzone.on("dragleave", (event) => this.event_dragleave(event));
          this.dropzone.on("drop", (event) => this.event_drop(event));
          this.container.addEventListener("processing", () => {
            this.dropzone.options.autoProcessQueue = true;
          });
        });
      }
      onQueueComplete(callback) {
        this.dropzone.on("success", () => {
          if (typeof callback === "function")
            callback();
        });
      }
      pendingFiles() {
        const files = this.dropzone.getQueuedFiles().concat(this.dropzone.getUploadingFiles());
        this.log.debug("Pending Files", files);
        return files;
      }
      isComplete() {
        return this.pendingFiles().length === 0;
      }
      processQueue() {
        this.dropzone.processQueue();
      }
      accept(file, done) {
        file.acceptFile = done;
        file.rejectFile = function(msg) {
          done(msg);
        };
      }
      //-----------------------------------------------------------------------
      // File Event Methods
      //-----------------------------------------------------------------------
      // Called just before the file is sent
      event_sending(file, xhr, formData) {
        formData.append("filesize", file.size);
      }
      // When a file is added to the list
      event_addedfile(file) {
        file.previewElement = window.Dropzone.createElement(this.dropzone.options.previewTemplate.trim());
        file.previewTemplate = file.previewElement;
        if (!this.options.gallery) {
          this.dropzone.previewsContainer.innerHTML = "";
        }
        this.dropzone.previewsContainer.appendChild(file.previewElement);
        if (!this.dropzone.options.autoProcessQueue) {
          this.container.classList.add("unsaved");
          this.form.unsaved();
        }
      }
      // When the thumbnail has been generated. Receives the dataUrl as second parameter.
      event_thumbnail(file, data) {
        file.previewElement.classList.remove("dz-file-preview");
        const thumbs = file.previewElement.querySelectorAll("[data-dz-thumbnail]");
        for (const thumb of thumbs) {
          thumb.alt = file.name;
          thumb.src = data;
        }
        if (this.testSet) {
          this.testSet.processRules(file);
          if (!this.testSet.pass) {
            console.error(this.testSet.errors);
            file.rejectFile(this.testSet.errors);
          }
        }
        file.acceptFile();
        return setTimeout(function() {
          return function() {
            return file.previewElement.classList.add("dz-image-preview");
          };
        }(this), 1);
      }
      // Gets called periodically whenever the file upload progress changes
      event_uploadprogress(file, progress, bytes) {
        const results = [];
        if (file.previewElement) {
          const nodes = file.previewElement.querySelectorAll("[data-dz-uploadprogress]");
          for (const node of nodes) {
            if (node.nodeName === "PROGRESS") {
              results.push(node.value = progress);
            } else if (node.classList.contains("dz-upload-progress-label")) {
              if (progress == 100) {
                results.push(node.innerHTML = "Processing...");
              } else {
                results.push(node.innerHTML = Math.round(progress) + "%");
              }
            } else {
              results.push(node.style.width = progress + "%");
            }
          }
        }
        return results;
      }
      // When an error has occurred
      event_error(file, message) {
        if (typeof message === "object")
          message = message.message;
        file.previewElement.classList.remove("saving");
        file.previewElement.classList.add("error", "dz-error");
        this.form.error(message);
      }
      // The file has been uploaded successfully
      event_success(file, response) {
        if (typeof response === "object") {
          if (file.previewElement) {
            if (typeof response.data === "string") {
              file.previewElement.dataset.filename = this.basename(response.data);
            }
            file.previewElement.classList.remove("dz-processing");
            file.previewElement.classList.add("dz-success");
          }
        } else {
          this.event_error(file, "Unknown error: " + response);
        }
      }
      //-----------------------------------------------------------------------
      // Mouse Event Methods
      //-----------------------------------------------------------------------
      // The user dragged a file onto the Dropzone
      event_dragenter(event) {
        this.log.debug("event_dragenter", event);
        const classesToRemove = ["dz-processing", "dz-success", "dz-complete"];
        const preview = this.container.getElementsByClassName("dz-preview").item(0);
        if (preview) {
          preview.classList.remove(...classesToRemove);
        }
        return this.container.classList.add("dz-drag-hover");
      }
      // The user dragged a file out of the Dropzone
      event_dragleave(event) {
        this.log.debug("event_dragleave", event);
        return this.container.classList.remove("dz-drag-hover");
      }
      // The user dropped something onto the dropzone
      event_drop(event) {
        this.log.debug("event_drop", event);
        return this.container.classList.remove("dz-drag-hover");
      }
    };
    __name(Droplet3, "Droplet");
  }
});

// javascript/totalform/droplet-array.js
var require_droplet_array = __commonJS({
  "javascript/totalform/droplet-array.js"() {
    "use strict";
    var ArrayDroplet2 = class extends Droplet {
      constructor(container, options) {
        super(...arguments);
        this.options.gallery = true;
      }
      schema() {
        return {
          type: "array",
          fieldset: this.options.type
        };
      }
      getValue() {
        return this.input.value.length > 0 ? JSON.parse(this.input.value) : [];
      }
      setValue(gallery) {
        if (gallery === null || gallery.length === 0) {
          console.warn("No gallery images found", gallery);
          return;
        }
        this.input.value = JSON.stringify(gallery);
        const rules = JSON.parse(this.container.dataset.imageworks);
        const preview = this.container.querySelectorAll(".total-preview").item(0);
        rules.fit = "crop";
        for (const image of gallery) {
          const imageWorks = new ImageWorks({
            collection: this.form.collection,
            id: this.form.id,
            property: this.name,
            file: image.filename,
            date: image.uploadDate
          });
          const imageQuery = imageWorks.buildQuery(rules);
          this.log.debug("image query", imageQuery);
          this.api.fetchCachedAPI("/templates/admin/image").then((json) => {
            this.api.processTemplate({ "image": imageQuery }, json.template, preview);
          });
        }
      }
    };
    __name(ArrayDroplet2, "ArrayDroplet");
  }
});

// javascript/totalform/listcomplete.js
var require_listcomplete = __commonJS({
  "javascript/totalform/listcomplete.js"() {
    "use strict";
    var ListComplete2 = class extends TotalField {
      constructor(container, options) {
        super(container, options);
        this.datalist = this.container.getElementsByTagName("datalist")[0];
        const defaults = {
          multiple: true,
          minChars: 0,
          maxItems: 15
        };
        this.options = Object.assign({}, this.options, defaults, options);
        this.prefillData();
        this.initAwesomplete();
      }
      schema() {
        return {
          "type": "array",
          "fieldset": "list"
        };
      }
      getValue() {
        return this.input.value.split(/\s*,\s*/).filter((x) => x.length > 0).filter((x, i, a) => a.indexOf(x) == i);
      }
      setValue(newValue) {
        this.input.value = typeof newValue === "object" ? newValue.join(", ") : newValue;
      }
      prefillData() {
        const prefill = this.datalist.dataset.prefill;
        if (prefill) {
          this.appendData(prefill.split(/\s*,\s*/));
        }
      }
      appendData(data) {
        for (const item of data) {
          const option = document.createElement("option");
          option.innerHTML = item;
          this.datalist.appendChild(option);
        }
        if (this.awesomplete) {
          this.awesomplete.evaluate();
        }
      }
      enableplete() {
        if (this.awesomplete.ul.childNodes.length === 0) {
          this.awesomplete.evaluate();
        } else if (this.awesomplete.ul.hasAttribute("hidden")) {
          this.awesomplete.open();
        } else {
          this.awesomplete.close();
        }
      }
      multipleFilter(text, input) {
        return Awesomplete.FILTER_CONTAINS(text, input.match(/[^,]*$/)[0]);
      }
      multipleReplace(text) {
        var before = this.input.value.match(/^.+,\s*|/)[0];
        this.input.value = before + text + ", ";
      }
      initAwesomplete() {
        const multiple = this.input.dataset.multiple;
        this.awesomplete = new Awesomplete(this.input, {
          filter: this.options.multiple === true ? this.multipleFilter : Awesomplete.FILTER_CONTAINS,
          replace: this.options.multiple === true ? this.multipleReplace : Awesomplete.REPLACE,
          minChars: this.options.minChars,
          maxItems: this.options.maxItems
        });
        this.input.addEventListener("dblclick", () => this.enableplete());
      }
    };
    __name(ListComplete2, "ListComplete");
  }
});

// javascript/totalform/deck.js
var require_deck = __commonJS({
  "javascript/totalform/deck.js"() {
    "use strict";
    var Deck2 = class extends TotalField {
      constructor(container, options) {
        super(container, options);
        this.template = this.container.getElementsByTagName("template").item(0);
        this.plus = this.container.getElementsByClassName("plus").item(0);
        this.deck = this.container.getElementsByClassName("thedeck").item(0);
        this.initDeck();
      }
      initDeck() {
        this.plus.addEventListener("click", (event) => this.newCard(), false);
        this.newCard();
      }
      insertAfter(newNode, referenceNode) {
        referenceNode.parentNode.insertBefore(newNode, referenceNode.nextSibling);
      }
      rebuildCounters() {
        const counters = Array.from(this.container.getElementsByClassName("counter")).reverse();
        let index = 1;
        for (const counter of counters) {
          counter.innerHTML = index++;
        }
      }
      initSmartFields(card) {
        this.initSVGBoxes(card);
        this.initRangeSliders(card);
        this.initDatePickers(card);
        this.sortCards();
      }
      initSVGBoxes(card) {
        const nodes = card.getElementsByClassName("svg-box");
        for (const node of nodes) {
          const svg = new SVGField(node, JSON.parse(node.dataset.options || "{}"));
        }
      }
      initDatePickers(card) {
        const nodes = card.getElementsByClassName("date-box");
        for (const node of nodes) {
          const picker = new DatePicker(node, JSON.parse(node.dataset.options || "{}"));
        }
      }
      initRangeSliders(card) {
        const nodes = card.getElementsByClassName("range-slider");
        for (const node of nodes) {
          const slider = new RangeSlider(node, JSON.parse(node.dataset.options || "{}"));
        }
      }
      getValue() {
        const deckData = [];
        const cards = Array.from(this.deck.getElementsByClassName("card"));
        for (const card of cards) {
          const fields = Array.from(card.querySelectorAll("fieldset>input,fieldset>textarea,fieldset>select"));
          const cardData = {};
          for (const field of fields) {
            cardData[field.name] = field.value;
          }
          deckData.push(cardData);
        }
        return deckData;
      }
      sortCards() {
        Sortable.create(this.deck, {
          handle: ".move-card",
          draggable: ".card",
          animation: 500,
          onEnd: (event) => this.rebuildCounters()
        });
      }
      newCard(addDataCallback) {
        const clone = document.importNode(this.template.content, true);
        const card = clone.querySelector(".card");
        const del = clone.querySelector(".delete");
        del.addEventListener("click", (event) => {
          card.parentNode.removeChild(card);
          this.rebuildCounters();
        }, false);
        const fields = Array.from(clone.querySelectorAll("fieldset"));
        fields.forEach((field) => field.classList.add("deck-field"));
        if (typeof addDataCallback === "function") {
          addDataCallback(clone);
          this.deck.appendChild(clone);
        } else {
          this.insertAfter(clone, this.plus);
        }
        this.rebuildCounters();
        this.initSmartFields(card);
      }
      schema() {
        return {
          "type": "array",
          "fieldset": "deck"
        };
      }
      setValue(cardData) {
        if (typeof cardData === "object") {
          cardData.forEach((data) => this.newCard((card) => {
            for (const name in data) {
              const input = card.querySelector(`input[name=${name}],textarea[name=${name}],select[name=${name}]`);
              if (input)
                input.value = data[name];
            }
            card.querySelector(".card").classList.remove("new");
          }));
          this.deck.querySelectorAll(".card.new").forEach((card) => card.parentNode.removeChild(card));
        }
      }
    };
    __name(Deck2, "Deck");
  }
});

// javascript/totalform/styledtext.js
var require_styledtext = __commonJS({
  "javascript/totalform/styledtext.js"() {
    "use strict";
    $.FroalaEditor.DEFAULTS.key = "AODOd2HLEBFZOTGHW==";
    $.FroalaEditor.SHORTCUTS_MAP = {
      69: { cmd: "show" },
      66: { cmd: "bold" },
      73: { cmd: "italic" },
      85: { cmd: "underline" },
      221: { cmd: "indent" },
      219: { cmd: "outdent" },
      90: { cmd: "undo" },
      "-90": { cmd: "redo" }
    };
    var StyledTextField2 = class extends TotalField {
      constructor(container, options) {
        super(container, options);
        this.options = Object.assign({}, this.defaultConfig(), window.totalcms.getConfig("styledtext"), this.options);
        this.initFroala();
      }
      initFroala() {
        $(this.input).froalaEditor(this.options).on("froalaEditor.charCounter.exceeded", (e, editor) => this.charCountExceeded()).on("froalaEditor.image.beforeUpload", (e, editor, images) => this.updateUploadURLs(editor)).on("froalaEditor.file.beforeUpload", (e, editor, files) => this.updateUploadURLs(editor)).on("froalaEditor.video.beforeUpload", (e, editor, videos) => this.updateUploadURLs(editor));
      }
      setValue(value) {
        this.input.value = value;
        $(this.input).froalaEditor("html.set", value);
      }
      getValue() {
        return $(this.input).froalaEditor("html.get");
      }
      uploadAPI(type) {
        if (!this.form)
          return null;
        const collection = this.form.collection;
        const id = this.form.id;
        const field = this.input.name;
        return this.api.buildUrlQuery(`/upload/${collection}/${id}/${field}/${type}`);
      }
      updateUploadURLs(editor) {
        if (!this.form.id) {
          console.warn("Unable to upload. Could not find object ID.");
          return false;
        }
        editor.opts.imageUploadURL = this.uploadAPI("image");
        editor.opts.fileUploadURL = this.uploadAPI("file");
        editor.opts.videoUploadURL = this.uploadAPI("video");
      }
      charCountExceeded() {
        $(this.input).closest("fieldset").find(".fr-counter").addClass("exceeded");
      }
      defaultConfig() {
        const toolbar = [
          "bold",
          "italic",
          "|",
          "insertLink",
          "insertImage"
        ];
        const colors = [
          "#61BD6D",
          "#1ABC9C",
          "#54ACD2",
          "#2C82C9",
          "#9365B8",
          "#475577",
          "#CCCCCC",
          "#41A85F",
          "#00A885",
          "#3D8EB9",
          "#2969B0",
          "#553982",
          "#28324E",
          "#000000",
          "#F7DA64",
          "#FBA026",
          "#EB6B56",
          "#E25041",
          "#A38F84",
          "#EFEFEF",
          "#FFFFFF",
          "#FAC51C",
          "#F37934",
          "#D14841",
          "#B8312F",
          "#7C706B",
          "#D1D5D8",
          "REMOVE"
        ];
        const fontSizes = [
          "8",
          "9",
          "10",
          "11",
          "12",
          "14",
          "18",
          "24",
          "30",
          "36",
          "48",
          "60",
          "72",
          "96"
        ];
        const videoEditButtons = [
          "videoReplace",
          "videoRemove",
          "|",
          "videoDisplay",
          "videoAlign"
        ];
        const quickInsertTags = [
          "image",
          "video",
          "table",
          "ul",
          "ol",
          "hr"
        ];
        const imageStyles = {
          "fr-rounded": "Rounded",
          "fr-bordered": "Bordered",
          "fr-shadow": "Shadow",
          "fr-full-width": "Full Width"
        };
        const codeMirrorOptions = {
          indentWithTabs: true,
          lineNumbers: true,
          lineWrapping: true,
          readOnly: false,
          mode: "text/html",
          tabMode: "indent",
          tabSize: 2
        };
        const paragraphFormat = {
          N: "Normal",
          H1: "Heading 1",
          H2: "Heading 2",
          H3: "Heading 3",
          H4: "Heading 4",
          PRE: "Code"
        };
        const megabyte = 1024 * 1024;
        const height = this.input.dataset.height > 0 ? this.input.dataset.height : null;
        return {
          keepFormatOnDelete: true,
          charCounterCount: false,
          charCounterMax: this.input.dataset.maxcount,
          colorsText: colors,
          colorsBackground: colors,
          language: this.api.options.locale,
          linkAutoPrefix: "https://",
          toolbarInline: false,
          tooltips: true,
          shortcutsHint: false,
          fontSize: fontSizes,
          videoEditButtons,
          videoMaxSize: megabyte * 1024,
          fileMaxSize: megabyte * 1024,
          imageMaxSize: megabyte * 5,
          imageUploadParam: "image",
          fileUploadParam: "file",
          videoUploadParam: "video",
          // These URLs will need to be customized per instance since
          // The API URL will need the collection, id and property fields
          fileUploadURL: this.uploadAPI("file"),
          videoUploadURL: this.uploadAPI("video"),
          imageUploadURL: this.uploadAPI("image"),
          imageManagerLoadURL: this.uploadAPI("image"),
          imageManagerDeleteURL: this.uploadAPI("image"),
          imageUploadParams: { w: 2500, h: 1e3, fit: "max" },
          // videoUploadParams        : {},
          // fileUploadParams         : {},
          // imageManagerDeleteParams : {},
          // imageManagerLoadMethod   : 'GET',
          // imageUploadMethod        : 'POST',
          // imageManagerDeleteMethod : 'DELETE',
          imageDefaultWidth: 0,
          imageResizeWithPercent: true,
          imageRoundPercent: true,
          imageStyles,
          codeMirror: true,
          codeMirrorOptions,
          alwaysVisible: false,
          saveInterval: 0,
          pastePlain: true,
          placeholderText: this.input.getAttribute("placeholder"),
          // requestHeaders         : {},
          toolbarButtons: toolbar,
          toolbarButtonsMD: toolbar,
          toolbarButtonsSM: toolbar,
          toolbarButtonsXS: toolbar,
          toolbarSticky: false,
          quickInsertButtons: false,
          quickInsertTags,
          paragraphFormat,
          enter: $.FroalaEditor.ENTER_P,
          htmlRemoveTags: ["script"],
          heightMax: 1e3,
          height
        };
      }
      schema() {
        return {
          "type": "string",
          "fieldset": "styledtext"
        };
      }
    };
    __name(StyledTextField2, "StyledTextField");
  }
});

// javascript/totalform/schema.js
var require_schema = __commonJS({
  "javascript/totalform/schema.js"() {
    "use strict";
  }
});

// javascript/totalform/totalfield.js
var TotalField2 = class {
  constructor(container, options) {
    this.container = container;
    this.input = this.container.querySelector("input,textarea,select");
    const defaults = {
      form: null
    };
    this.options = Object.assign({}, defaults, options);
    this.form = this.options.form;
    delete this.options.form;
    if (this.form) {
      this.log = this.form.log;
      this.api = this.form.api;
    }
  }
  getValue() {
    return this.input.value;
  }
  setValue(value) {
    this.input.value = value;
    if (this.input.classList.contains("styledtext")) {
      this.input.froalaEditor("html.set", value);
    }
    this.changed();
  }
  changed() {
    this.container.dispatchEvent(new Event("change"));
  }
  schema() {
    return {
      "type": "text",
      "fieldset": "text"
    };
  }
};
__name(TotalField2, "TotalField");

// javascript/totalform/totalform.js
var import_checkbox = __toESM(require_checkbox());
var import_markdown = __toESM(require_markdown());
var import_svg = __toESM(require_svg());
var import_select = __toESM(require_select());
var import_multiselect = __toESM(require_multiselect());
var import_number = __toESM(require_number());
var import_identifier = __toESM(require_identifier());
var import_rangeslider = __toESM(require_rangeslider());
var import_colorpicker = __toESM(require_colorpicker());
var import_datepicker = __toESM(require_datepicker());
var import_droplet = __toESM(require_droplet());
var import_droplet_array = __toESM(require_droplet_array());
var import_listcomplete = __toESM(require_listcomplete());
var import_deck = __toESM(require_deck());
var import_styledtext = __toESM(require_styledtext());
var import_schema = __toESM(require_schema());
var TotalForm = class {
  // Constructors
  constructor(formRef, options = {}) {
    if (!formRef) {
      return false;
    }
    this.form = this.setForm(formRef);
    if (!this.form) {
      console.error("form not found");
      return false;
    }
    this.baseapi = this.form.action;
    this.method = this.form.dataset.method || "POST";
    this.id = this.api.getUrlParameter("id");
    this.processingStart = Date.now();
    this.processingLimit = 1500;
    this.states = ["success", "error", "processing", "clear"];
    this.fields = this.findAll(".form-field").filter((field) => !this.insideDeck(field));
    this.droplets = this.fields.filter((field) => field.classList.contains("droplet"));
    this.fieldObjects = this.processFields();
    this.addTemplates();
    this.saveListener();
    this.registerButtons();
  }
  //-------------------------
  // Utility Methods
  //-------------------------
  // Find the first instance of a selector within the form
  find(selector) {
    return this.form.querySelector(selector);
  }
  // Find the all instance of a selector within the form
  findAll(selector) {
    return Array.from(this.form.querySelectorAll(selector));
  }
  // Filter for determining if inside of a Deck field
  insideDeck(node) {
    return node.parentNode.closest("fieldset.deck-box") ? true : false;
  }
  // Check to see if the object is a HTML node.
  isDomNode(node) {
    return typeof node === "object" && "nodeType" in node && node.nodeType === 1;
  }
  // Set the form via a DOM element or selector string
  setForm(formRef) {
    switch (typeof formRef) {
      case "string":
        return document.getElementById(formRef);
      case "object":
        if (this.isDomNode(formRef)) {
          return formRef;
        }
        break;
    }
    return null;
  }
  //-------------------------
  // Init Form
  //-------------------------
  processFields() {
    const data = {};
    this.fields.forEach((field) => {
      const object = this.generateFieldObject(field);
      if (object === null)
        return;
      data[field.dataset.name] = object;
      field.addEventListener("change", (event) => {
        this.unsaved();
      });
    });
    return data;
  }
  registerButton(buttonClass, callback) {
    const allButtons = Array.from(document.getElementsByClassName(buttonClass));
    const buttons = allButtons.filter((button) => {
      const form = button.closest("form");
      if (form)
        return form === this.form;
      return true;
    });
    buttons.forEach((button) => {
      button.addEventListener("click", (event) => {
        event.preventDefault();
        if (typeof callback === "function")
          callback(button);
        return false;
      });
    });
  }
  registerButtons() {
    this.registerButton("cms-save", () => this.save());
    this.registerButton("cms-delete", () => this.delete());
  }
  generateFieldObject(field) {
    const options = JSON.parse(field.dataset.options || "{}");
    options.form = this;
    switch (field.dataset.type) {
      case "text":
      case "video":
        return new TotalField2(field, options);
      case "styledtext":
        return new import_styledtext.default(field, options);
      case "markdown":
        return new import_markdown.default(field, options);
      case "svg":
        return new import_svg.default(field, options);
      case "select":
        return new import_select.default(field, options);
      case "multiselect":
        return new import_multiselect.default(field, options);
      case "number":
        return new import_number.default(field, options);
      case "checkbox":
      case "toggle":
        return new import_checkbox.default(field, options);
      case "id":
        return this.initIdentifier(field, options);
      case "range":
        return new import_rangeslider.default(field, options);
      case "color":
        return new import_colorpicker.default(field, options);
      case "date":
        return new import_datepicker.default(field, options);
      case "deck":
        return new import_deck.default(field, options);
      case "image":
      case "file":
        return this.initDroplet(field, options);
      case "gallery":
      case "depot":
        return this.initArrayDroplet(field, options);
      case "list":
        return new import_listcomplete.default(field, options);
      default:
        console.warn("Unknown fieldset", fieldset);
        return null;
    }
  }
  initIdentifier(field, options) {
    this.id = new import_identifier.default(field, options);
    field.addEventListener("change", (event) => this.updateIdentifier());
    return this.id;
  }
  initArrayDroplet(field, options) {
    options.type = field.dataset.type;
    const droplet = new import_droplet_array.default(field, options);
    droplet.updateUri();
    return droplet;
  }
  initDroplet(field, options) {
    options.type = field.dataset.type;
    const droplet = new import_droplet.default(field, options);
    droplet.updateUri();
    return droplet;
  }
  //-------------------------
  // Populate Form functions
  //-------------------------
  getServerObject() {
    this.api.fetchAPI(`${this.baseapi}/${this.id}`).then((object) => this.populateForm(object));
  }
  populateForm(object) {
    this.id = object.id;
    for (const property in object) {
      const field = this.fieldObjects[property];
      if (!field) {
        console.warn(`Unable to find form field for object property: ${property}`);
        continue;
      }
      field.setValue(object[property]);
    }
    this.editMode();
  }
  //-------------------------
  // Submit functions
  //-------------------------
  saveListener() {
    this.form.addEventListener("submit", (event) => {
      event.preventDefault();
      this.save();
    });
    document.addEventListener("keydown", (event) => {
      if (this.isUnsaved()) {
        if (event.key === "s" && (event.ctrlKey || event.metaKey)) {
          event.preventDefault();
          this.save();
        }
      }
    });
  }
  save() {
    this.updateIdentifier();
    this.processing();
    this.api.postAPI(this.baseapi, this.generateData()).then((response) => this.afterSave(response)).catch((error) => this.error(error));
  }
  delete() {
    if (!this.isEditMode())
      return;
    if (window.confirm("Are you sure that you want to delete this? This cannot be undone.")) {
      this.updateIdentifier();
      this.processing();
      this.options.editAction = "redirect";
      this.options.editLink = location.origin + location.pathname;
      this.api.postAPI(`/collections/${this.collection}/${this.id}`, {}, "DELETE").then((response) => this.afterSave(response)).catch((error) => this.error(error));
    }
  }
  submit() {
    this.save();
  }
  updateIdentifier() {
    this.id = this.id.id;
  }
  // onSubmit(callback) {
  //     this.form.addEventListener("submit", event => {
  //         event.preventDefault();
  //         if (typeof callback === "function") callback();
  //     });
  // }
  afterSave(response) {
    if (!response)
      return;
    if (this.droplets.length > 0) {
      this.saveDroplets(() => this.afterSaveAction(response));
    } else {
      this.afterSaveAction(response);
    }
  }
  afterSaveAction(response) {
    this.success();
    const waitUntilSaved = /* @__PURE__ */ __name(() => {
      if (!this.saving()) {
        return this.isEditMode() ? this.runEditAction() : this.runNewAction();
      }
      window.setTimeout(waitUntilSaved, 100);
    }, "waitUntilSaved");
    waitUntilSaved();
  }
  runAction(action, url) {
    switch (action) {
      case "refresh":
        location.reload(true);
        break;
      case "redirect-object":
        document.location = url + this.id;
        break;
      case "redirect":
        document.location = url;
        break;
      case "back":
        if (window.history.length > 1) {
          document.location = document.referrer;
        }
        break;
    }
  }
  runNewAction() {
  }
  runEditAction() {
  }
  //-------------------------
  // Form Templates
  //-------------------------
  addTemplates() {
    this.templateSaveIndicator();
  }
  templateSaveIndicator() {
    const indicator = document.getElementById("form-save-indicator");
    if (indicator) {
      this.indicator = indicator;
    } else {
      this.api.fetchCachedAPI("/templates/admin/form-save").then((json) => {
        const body = document.getElementsByTagName("body")[0];
        this.api.processTemplate({}, json.template, body);
        this.indicator = document.getElementById("form-save-indicator");
        this.indicator.addEventListener("click", () => this.indicator.classList = "");
      });
    }
  }
  //-------------------------
  // Form States
  //-------------------------
  isUnsaved() {
    return this.form.classList.contains("unsaved");
  }
  unsaved() {
    return this.form.classList.add("unsaved");
  }
  isEditMode() {
    return this.form.classList.contains("edit-form");
  }
  editMode() {
    this.form.classList.add("edit-form");
    for (const name in this.fieldObjects) {
      const field = this.fieldObjects[name];
      if (field.dropzone)
        field.autoProcessQueue();
    }
  }
  saving() {
    const current = this.states.filter((state) => this.form.classList.contains(state));
    return current.length > 0;
  }
  changeState(newState) {
    const remove = this.states.filter((e) => e !== newState);
    const elements = [this.indicator, this.form];
    for (const element of elements) {
      if (newState)
        element.classList.add(newState);
      element.classList.remove(...remove);
    }
  }
  delayProcessing(callback) {
    const processingTime = Date.now() - this.processingStart;
    const delay = this.processingLimit - processingTime;
    window.setTimeout(() => {
      if (typeof callback === "function")
        callback();
    }, delay);
  }
  error(error) {
    console.error("Form Error: " + error);
    this.delayProcessing(() => {
      this.changeState("error");
    });
  }
  clear() {
    this.changeState("clear");
    window.setTimeout(() => {
      this.changeState();
    }, 1e3);
  }
  success() {
    this.delayProcessing(() => {
      this.changeState("success");
      this.form.classList.remove("unsaved");
      for (const field of this.fields) {
        field.classList.remove("unsaved");
      }
      window.setTimeout(() => {
        this.clear();
      }, 2e3);
    });
  }
  processing() {
    this.processingStart = Date.now();
    this.changeState("clear");
    window.setTimeout(() => {
      this.changeState("processing");
    }, 100);
  }
  //-------------------------
  // Droplet Interactions
  //-------------------------
  // The droplet URL requires the ID but that can change
  // This ensures that the URL is updated when it changes
  updateDropletUri() {
    for (const name in this.fieldObjects) {
      const field = this.fieldObjects[name];
      if (field.dropzone)
        field.updateUri();
    }
  }
  // We only want to process the droplet queue after the inital
  // post request to create the object has been saved
  saveDroplets(callback) {
    let dropletCount = 0;
    for (const name in this.fieldObjects) {
      if (this.fieldObjects[name].dropzone)
        dropletCount++;
    }
    const dropletComplete = /* @__PURE__ */ __name((callback2) => {
      dropletCount--;
      if (dropletCount === 0) {
        if (typeof callback2 === "function")
          callback2();
      }
    }, "dropletComplete");
    for (const name in this.fieldObjects) {
      const field = this.fieldObjects[name];
      if (!field.dropzone)
        continue;
      if (field.isComplete()) {
        dropletComplete(callback);
        continue;
      }
      field.updateUri();
      field.onQueueComplete(() => dropletComplete(callback));
      field.processQueue();
    }
  }
  //-------------------------
  // Generating Form Data
  //-------------------------
  generateData() {
    const data = {};
    for (const name in this.fieldObjects) {
      const value = this.fieldObjects[name].getValue();
      if (value !== null)
        data[name] = value;
    }
    return data;
  }
};
__name(TotalForm, "TotalForm");

// javascript/admin.js
var forms = Array.from(document.querySelectorAll("form.dynamics-form"));
for (const form of forms) {
  const totalform = new TotalForm(form);
}
//! TODO This would be nice if it worked on the fieldset level like TotalForm does
//! convert to native JS... lazy asshole
//# sourceMappingURL=admin.js.map
