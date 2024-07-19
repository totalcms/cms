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
    }

    addTemplate() {
		const clone = this.template.content.cloneNode(true);
		const parent = this.addButton.parentNode;
		parent.insertBefore(clone, this.addButton);

		const field = Array.from(parent.querySelectorAll(".schema-field")).pop();
		field.querySelector("input").focus();
		this.newField(field);
	}

    newField(field) {
		new PropertyField(field);
		// this.initActionbar(field);
		this.form.processFields();
	}

	schema() {
        return {
            type     : "schemaProperties",
            fieldset : this.type
        };
    }
}
