import PropertiesField from "./properties";
import PropertyField from "./property";
const slugify = require('slugify')

//-----------------------------------------------
// Total CMS Properties
//-----------------------------------------------
export default class SchemaPropertiesField extends PropertiesField {

    constructor(container, options) {
		options.fieldClass = "schema-field";
        super(container, options);

        this.template  = this.container.querySelector(".schema-template");
        this.addButton = this.container.querySelector(".cms-add");
		this.addButton?.addEventListener("click", this.addTemplate.bind(this));
    }

    addTemplate() {
		const clone = this.template.content.cloneNode(true);
		const parent = this.addButton.parentNode;
		parent.insertBefore(clone, this.addButton);

		const field = Array.from(parent.querySelectorAll("."+this.fieldClass)).pop();
		field.querySelector("input").focus();
		this.newField(field);
	}

    newField(field) {
		const newField = new PropertyField(field, this.fieldClass);
		newField.form = this.form;
		this.initActionbar(field);
		this.form.processFields();
	}

	initActionbar(field) {
		const trash     = field.querySelector("button.trash");
		const duplicate = field.querySelector("button.duplicate");
		const property  = field.querySelector("input");

		// Ensure properties are all lowercase
		property?.addEventListener("change", () => {
			// Replace hyphens with underscores because it makes for nicer twig macros
			property.value = slugify(property.value, { lower: true }).replace(/-/g, "_");
		});

		trash?.addEventListener("click", () => this.removeField(field));
		duplicate?.addEventListener("click", () => this.duplicateField(field));
	}

	removeField(field) {
		field.remove();
	}

	duplicateField(field) {
		// Get all select values before cloning
		const selects = field.querySelectorAll('select');
		const selectValues = Array.from(selects).map(select => select.value);

		const clone = field.cloneNode(true);
		const parent = field.parentNode;
		parent.insertBefore(clone, field.nextSibling);

		const newField = field.nextSibling;
		
		// Restore select values after cloning
		const clonedSelects = newField.querySelectorAll('select');
		clonedSelects.forEach((select, index) => {
			if (selectValues[index]) {
				select.value = selectValues[index];
			}
		});

		newField.querySelector("input").focus();
		this.newField(newField);
	}

	schema() {
        return {
            type     : "schemaProperties",
            fieldset : this.type
        };
    }
}
