import MultiSelectField from "./multiselect";
import Choices from "choices.js";
import Sortable from 'sortablejs';

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
			callbackOnInit        : this.initSortable.bind(this),
		});
    }

	initSortable() {
		const list = this.container.querySelector('.choices__list');
		console.log('init sortable', list);

		this.sortable = new Sortable(list, {
			animation  : 150,
			filter     : 'button',
			draggable  : ".choices__item",
			ghostClass : 'drag-ghost'
		});
	}

    schema() {
        return {
            "type"  : "array",
            "field" : "list"
        };
    }
}
