import MultiSelectField from "./multiselect";
import Choices from "choices.js";

//-----------------------------------------------
// Total CMS List Field
//-----------------------------------------------
export default class ListField extends MultiSelectField {

    constructor(container, options) {
        super(container, options);

		// Define option defaults
		const defaults = {
			allowHTML             : true,
			removeItemButton      : true,
			duplicateItemsAllowed : false,
			addChoices            : true,
		};
		this.options = Object.assign({}, this.options, defaults, options);

		this.choices = new Choices(this.input, {
			allowHTML             : this.options.allowHTML,
			removeItemButton      : this.options.removeItemButton,
			duplicateItemsAllowed : this.options.duplicateItemsAllowed,
			addChoices            : this.options.addChoices,
		});
    }

    schema() {
        return {
            "type"  : "array",
            "field" : "list"
        };
    }
}
