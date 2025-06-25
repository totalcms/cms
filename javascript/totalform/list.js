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
			asString              : false,
			allowHTML             : true,
			removeItemButton      : true,
			duplicateItemsAllowed : false,
			addChoices            : true,
			maxItemCount          : -1,
		};
		this.options = Object.assign({}, this.options, defaults, options);

		// Temporarily set maxItemCount to -1 to prevent initial notification
		const tempMaxItemCount = this.options.maxItemCount;
		const showNotification = this.options.maxItemCount > 0 && this.getValue().length >= this.options.maxItemCount;

		this.choices = new Choices(this.input, {
			allowHTML             : this.options.allowHTML,
			removeItemButton      : this.options.removeItemButton,
			duplicateItemsAllowed : this.options.duplicateItemsAllowed,
			addChoices            : this.options.addChoices,
			maxItemCount          : showNotification ? -1 : this.options.maxItemCount,
			callbackOnInit        : () => {
				this.initSortable();
				// Restore the actual maxItemCount after initialization
				if (showNotification) {
					setTimeout(() => {this.choices.config.maxItemCount = tempMaxItemCount}, 0);
				}
			},
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

	getValue() {
		const value = super.getValue();

		if (this.options.asString) {
			return value.join(',');
		}
		return value;
    }

    schema() {
        return {
            "type"  : "array",
            "field" : "list"
        };
    }
}
