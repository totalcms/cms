import PropertyField from "./property";
import TotalField from "./totalfield";

//-----------------------------------------------
// Total CMS Properties
//-----------------------------------------------
export default class PropertiesField extends TotalField {

    constructor(container, options) {
        super(container, options);

		this.propertyFields = Array.from(this.container.getElementsByClassName("schema-field"));

		this.properties = [];

		this.propertyFields.forEach(field => {
			const property = new PropertyField(field);
			this.properties.push(property);
		});

		// this.form.processFields();
    }

	isUnsaved() {
		const unsavedChildren = this.container.querySelectorAll(".unsaved");
		return this.container.classList.contains("unsaved") || unsavedChildren.length > 0;
	}

	saved() {
		super.saved();
		const unsavedChildren = this.container.querySelectorAll(".unsaved");
		unsavedChildren.forEach(unsavedChild => unsavedChild.classList.remove("unsaved"));
	}

    getValue() {
		const properties = {};
		this.properties.forEach(prop => properties[prop.name] = prop.getValue());
		return properties;
	}

	clearValue() {
		this.preview.clearValue();
	}

    setValue(image) {
		this.preview.setValue(image);
		this.saved();
    }

	schema() {
        return {
            type     : "properties",
            fieldset : this.type
        };
    }
}
