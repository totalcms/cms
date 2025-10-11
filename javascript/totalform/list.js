import MultiSelectField from "./multiselect";
import Choices from "choices.js";
import TotalSortable from "./total-sortable";

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

		this.sortable = new TotalSortable(list, {
			draggable : ".choices__item",
			onEnd     : this.syncChoices.bind(this)
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
		let value = Array.from(this.input.options).filter(option => option.selected).map(option => option.value);

		// If Choices.js is initialized, get values from the actual DOM order of items
		const list = this.container.querySelector('.choices__list');
		if (list) value = Array.from(list.children).map(item => item.dataset.value);

		if (this.options.asString) {
			return value.join(',');
		}
		return value;
    }

	validate() {
		this.input.setCustomValidity("");
		// For list fields, we need to check if there are items when the field is required
		if (this.input.required) {
			const items = this.getValue();
			if ((this.options.asString === true && items === '') || (this.options.asString === false && (!Array.isArray(items) || items.length === 0))) {
				this.input.setCustomValidity("Please add at least one item to the list.");
				this.input.reportValidity();
				this.error(this.input.validationMessage);
				return false;
			}
		}
		// Call parent validation for other checks
		return super.validate();
	}

    schema() {
        return {
            "type"  : "array",
            "field" : "list"
        };
    }
}
