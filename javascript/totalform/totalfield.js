import TotalDispatcher from "./dispatcher";

//-----------------------------------------------
// Total CMS Generic Field
//-----------------------------------------------
export default class TotalField {

    constructor(container, options) {
		this.container = container;
		this.input     = this.container.querySelector("input,textarea,select");

		container.totalfield = this;

		this.type = container.dataset.type;
		this.property = this.input.name;

        // Define option defaults
        const defaults = {
            form : null
        };
        this.options = Object.assign({}, defaults, options);
        this.form = this.options.form;

        // Delele the form from the options in case its used in JSON
        delete this.options.form;

        if (this.form) {
            this.api = this.form.api;
        }

		this.dispathcer = new TotalDispatcher(this.container);

		this.changeListener();
    }

	changeListener() {
		// the change event happens more than once so the ID field can be updated for every change
		this.input.addEventListener("change", () => this.changed());
		// the input event happens once since the point is to mark the form as unsaved ASAP
		this.input.addEventListener("input", () => this.changed(), {once: true});
	}

	isSubField() {
		// Filter for determining if a field is a subproperty of another field
		// Need to look at parentNode since closest also looks at self
		return this.container.parentNode.closest(".form-field") ? true : false;
	}

	isDroplet() {
		return (this.droplet && typeof this.droplet === "object");
	}

	isFroala() {
		return (this.froala && typeof this.froala === "object");
	}

    getValue() {
        return this.input.value;
    }

    setValue(value) {
        this.input.value = value;
		this.changed();
    }

	clearValue() {
		this.setValue("");
	}

    changed() {
		this.container.classList.add("unsaved");
		this.dispathcer.dispatchEvent("field-change", { field: this });
    }

	saved() {
		this.container.classList.remove("unsaved");
		this.changeListener();
	}

	error(message) {
		this.container.classList.add("error");
		this.dispathcer.dispatchEvent("field-error", { field: this, message: message });
    }

    schema() {
        return {
            "type"  : this.type,
            "field" : "text"
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
