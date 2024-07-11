import PropertiesField from "./properties";
import TotalField from "./totalfield";

//-----------------------------------------------
// Total CMS Properties
//-----------------------------------------------
export default class CustomPropertiesField extends TotalField {

    constructor(container, options) {
        super(container, options);

		this.objects = [];

		const objectFields = Array.from(this.container.getElementsByClassName("customProperties-object"));
		objectFields.forEach(field => {
			this.objects.push(new PropertiesField(field));
		});
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
		const objects = {};

		this.objects.forEach(object => {
			const properties = object.getValue();
			if (Object.keys(properties).length > 0) {
				const objectId = object.container.querySelector('[name=object]').value;
				objects[objectId] = properties;
			}
		});

		return objects;
	}

	clearValue() {
	}

    setValue() {
    }

	schema() {
        return {
            type     : "customProperties",
            fieldset : this.type
        };
    }
}
