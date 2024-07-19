import PropertiesField from "./properties";
import PropertyField from "./property";

//-----------------------------------------------
// Total CMS Properties
//-----------------------------------------------
export default class SchemaPropertiesField extends PropertiesField {

    constructor(container, options) {
		options.fieldClass = "schema-field";
        super(container, options);

        this.template  = this.container.querySelector("template");
        this.addButton = this.container.querySelector(".cms-add");
		this.addButton.addEventListener("click", this.addTemplate.bind(this));

		const fields = this.container.getElementsByClassName(this.fieldClass);
		for (const field of fields) {
			this.initActionbar(field);
		}
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
		new PropertyField(field);
		this.initActionbar(field);
		this.form.processFields();
	}

	initActionbar(field) {
		const trash = field.querySelector("button.trash");
		const duplicate = field.querySelector("button.duplicate");

		trash.addEventListener("click", () => this.removeField(field));
		duplicate.addEventListener("click", () => this.duplicateField(field));
	}

	removeField(field) {
		field.remove();
	}

	duplicateField(field) {
		const clone = field.cloneNode(true);
		const parent = field.parentNode;
		parent.insertBefore(clone, field.nextSibling);

		const newField = field.nextSibling;
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
