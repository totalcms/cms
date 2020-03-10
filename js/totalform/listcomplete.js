//-----------------------------------------------
// Total CMS List Field
//-----------------------------------------------
class ListComplete extends Fieldset {

    constructor(container, options) {
        super(container, options);

        this.datalist  = this.container.getElementsByTagName("datalist")[0];

        // Define option defaults
        const defaults = {
            multiple : true,
            minChars : 0,
            maxItems : 15,
        };
        this.options = Object.assign({}, this.options, defaults, options);

        this.prefillData();
        this.initAwesomplete();
    }

    schema() {
        return {
            "type":"array",
            "fieldset":"list"
        };
    }

    getValue() {
        // return array of unique values that are not blank
        return this.input.value.split(/\s*,\s*/).filter(x => x.length > 0).filter((x, i, a) => a.indexOf(x) == i);
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
        }
        else if (this.awesomplete.ul.hasAttribute("hidden")) {
            this.awesomplete.open();
        }
        else {
            this.awesomplete.close();
        }
    }

    multipleFilter(text,input) {
        return Awesomplete.FILTER_CONTAINS(text, input.match(/[^,]*$/)[0]);
    }

    multipleReplace(text) {
        var before = this.input.value.match(/^.+,\s*|/)[0];
        this.input.value = before + text + ", ";
    }

    initAwesomplete() {
        const multiple = this.input.dataset.multiple;
        this.awesomplete = new Awesomplete(this.input,{
            filter   : this.options.multiple === true ? this.multipleFilter  : Awesomplete.FILTER_CONTAINS,
            replace  : this.options.multiple === true ? this.multipleReplace : Awesomplete.REPLACE,
            minChars : this.options.minChars,
            maxItems : this.options.maxItems,
        });
        this.input.addEventListener("dblclick", () => this.enableplete());
    }
}
