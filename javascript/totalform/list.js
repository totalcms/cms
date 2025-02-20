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

		this.sortable = new Sortable(list, {
			animation  : 150,
			draggable  : ".choices__item",
			ghostClass : 'drag-ghost',
			onEnd      : this.syncChoices.bind(this)
		});
	}

	syncChoices(event) {
		const oldIndex = event.oldIndex;
		const newIndex = event.newIndex;

		if (oldIndex === newIndex) {
			return;
		}

		const select = this.container.querySelector('select.choices__input');
		const options = Array.from(select.options);
		const movedOption = options.splice(oldIndex, 1)[0];
		options.splice(newIndex, 0, movedOption);

		select.innerHTML = '';
		options.forEach(option => select.appendChild(option));

		this.changed();
	}

    schema() {
        return {
            "type"  : "array",
            "field" : "list"
        };
    }
}
