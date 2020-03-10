//-----------------------------------------------
// Total CMS Range Slider Field
//-----------------------------------------------
class RangeSlider extends NumberField {

    constructor(container, options) {
        super(container, options);

        // Define option defaults
        const defaults = {
            precision  : null,
            unit       : null,
            unitPrepend: false,
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
        // get range index labels
        let rangeLabels = this.input.getAttribute("labels");
        if (!rangeLabels) return;
        rangeLabels = rangeLabels.replace(/\s+/g, "").split(",");
        if (rangeLabels.length === 0) return;

        const labelsContainer = document.createElement("div");
        labelsContainer.classList.add("rangeslider__labels");

        // add labels
        $(rangeLabels).each(function (index, value) {
            const label = document.createElement("span");
            label.classList.add("rangeslider__labels__label");
            label.innerHTML = value;
            labelsContainer.append(label);
        });

        // Add labels to rangeslider
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
        // this.input.value = value;
        $(this.input).val(value).change();
    }

    initRangeSlider() {
        // jQuery - sad panda
        $(this.input).rangeslider({
            polyfill       : false,
            rangeClass     : "rangeslider",
            disabledClass  : "rangeslider--disabled",
            horizontalClass: "rangeslider--horizontal",
            fillClass      : "rangeslider__fill",
            handleClass    : "rangeslider__handle",
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
}

