import PropertyField from "./property";
import TotalField from "./totalfield";
import Sortable from 'sortablejs';

//-----------------------------------------------
// Total CMS Properties
//-----------------------------------------------
export default class PropertiesField extends TotalField {

    constructor(container, options) {
        super(container, options);

		// not storing this as an array so that it can be updated simply through the DOM
		const propertyFields = this.container.getElementsByClassName("property-field");
		for (const field of propertyFields) {
			new PropertyField(field);
		}
		this.sortableProperties(propertyFields);
    }

	sortableProperties(propertyFields) {
		if (propertyFields.length === 0) return;
		// Make the fields sortable
		Sortable.create(propertyFields[0].parentNode, {
			animation  : 150,
			ghostClass : 'drag-ghost',
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
		const propertyFields = this.container.getElementsByClassName("property-field");

		const properties = {};
		for (const field of propertyFields) {
			const property = field.totalfield;
			properties[property.name] = property.getValue()
		}
		return properties;
	}

	clearValue() {
	}

    setValue() {
    }

	schema() {
        return {
            type     : "properties",
            fieldset : this.type
        };
    }
}
