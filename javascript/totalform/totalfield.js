//-----------------------------------------------
// Total CMS Generic Field
//-----------------------------------------------
export default class TotalField {

    constructor(container, options) {
        this.container = container;
        this.input = this.container.querySelector("input,textarea,select");

        // Define option defaults
        const defaults = {
            form: null
        };
        this.options = Object.assign({}, defaults, options);
        this.form = this.options.form;

        // Delele the form from the options in case its used in JSON
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
        // this.input.setAttribute("placeholder", "");
        // Hipwig
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
            "type"     : "text",
            "fieldset" : "text"
        };
    }
}

// Radio Logic
// if (field.nodeName === "INPUT" && field.type === "radio" ) {
//     if (field.checked) {
//         return this.data[key] = field.value;
//     }
// }

// Checkboxes are a special case. We have to grab each checked values and put them into an array.
// else if (field.nodeName === "INPUT" && field.type === "checkbox") {
//     if (field.checked){
//         if (!this.data[key]){
//             this.data[key] = [];
//         }
//         return this.data[key].push(field.value);
//     }
// }
