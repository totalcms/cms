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
		this.propertyFields = this.container.getElementsByClassName("property-field");
		for (const field of this.propertyFields) {
			new PropertyField(field);
		}

		this.sortableProperties();
    }

	sortableProperties() {
		// Make the fields sortable
		Sortable.create(this.propertyFields[0].parentNode, {
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
		const properties = {};
		for (const field of this.propertyFields) {
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
