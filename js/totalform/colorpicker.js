// Stop specturm from loading on its own
$.fn.spectrum.load = false;

//-----------------------------------------------
// Total CMS Color Field
//-----------------------------------------------
class ColorPicker extends Fieldset {

    constructor(container, options) {
        super(container, options);

        // Define option defaults
        const defaults = {
            preferredFormat       : "rgb",
            color                 : "#fff",
            allowEmpty            : false,
            flat                  : false,
            showInput             : true,
            showInitial           : true,
            showAlpha             : true,
            showButtons           : false,
            clickoutFiresChange   : true,
            appendTo              : this.container,
            showPalette           : true,
            showPaletteOnly       : false,
            togglePaletteOnly     : false,
            localStorageKey       : "colorpicker",
            showSelectionPalette  : true,
            maxSelectionSize      : 9,
            togglePaletteMoreText : " ",
            togglePaletteLessText : " ",
            palette               : [
                ["red", "green", "yellow", "blue", "violet", "black", "white"]
            ],
            // cancelText: "",
            // chooseText: "",
            // containerClassName: string,
            // replacerClassName: string,
            // selectionPalette: [string]
            // disabled: bool,
        };

        // If palette is a string, turn it into an array
        if (options.palette && typeof options.palette === "string") {
            options.palette = [options.palette.trim().split(/\s*,\s*/)];
        }

        this.options = Object.assign({}, this.options, defaults, options);

        // jQuery - sad panda
        $(this.input).spectrum(this.options);

        this.previewColor = document.querySelectorAll(`[data-pickercolor=${this.input.name}]`);

        $(this.input).on("dragstart.spectrum dragstop.spectrum change.spectrum", (e,color) => {
            this.previewColor.forEach(el => el.style.backgroundColor = color.toRgbString());
        });
    }

    setValue(color) {
        this.setRgb(...color.rgb,color.alpha);
    }

    setColor(color) {
        this.input.value = color;
        $(this.input).spectrum("set",color);
        this.previewColor.forEach(el => el.style.backgroundColor = color);
    }

    setHex(color) {
        const hex = `#${color}`.replace("##","#"); // support passing hash in argument or not
        this.setColor(hex);
    }

    setRgb(red,green,blue,alpha=1) {
        const rgba = `rgba(${red},${green},${blue},${alpha})`;
        this.setColor(rgba);
    }

    setHsl(h,s,l,alpha=1) {
        const hsla = `hsla(${h},${s}%,${l}%,${alpha})`;
        this.setColor(hsla);
    }

    schema() {
        return {
            "type":"object",
            "fieldset":"color"
        };
    }

}
