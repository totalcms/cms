import TotalField from "./totalfield";
import DeckItem from "./deckItem";
import Sortable from 'sortablejs';
const slugify = require('slugify');

//-----------------------------------------------
// Total CMS Deck Field
//-----------------------------------------------
export default class DeckField extends TotalField {

    constructor(container, options) {
        super(container, options);

        this.fieldClass = "deck-item";
        this.deckref = container.dataset.deckref || '';

		this.items = [];

        // Initialize existing deck items
        const deckItems = this.container.getElementsByClassName(this.fieldClass);
        for (const item of deckItems) {
            this.items.push(this.newItem(item));
        }
        this.sortableDeckItems(deckItems);

        // Get template for new items
        this.template = this.container.querySelector("template.deck-template");

        // Setup add button
        this.addButton = this.container.querySelector(".cms-add");
        this.addButton?.addEventListener("click", this.addItem.bind(this));
    }

    sortableDeckItems(deckItems) {
        if (deckItems.length === 0) return;
        // Make the items sortable
        Sortable.create(deckItems[0].parentNode, {
            animation: 150,
            ghostClass: 'drag-ghost',
        });
    }

    addItem() {
        if (!this.template) {
            console.error('No deck template found');
            return;
        }

        // Clone the template
        const clone = this.template.content.cloneNode(true);

        // Insert before add button
        const parent = this.addButton.parentNode;
        parent.insertBefore(clone, this.addButton);

        // Get the newly added item (last deck-item before the add button)
        const deckItems = parent.querySelectorAll(`.${this.fieldClass}`);
        const itemElement = deckItems[deckItems.length - 1];

        // Clear the ID field and focus on it
        const idInput = itemElement.querySelector("input[name='deck-item-id']");
        if (idInput) {
            idInput.value = '';
            idInput.focus();
        }

        // Initialize the new item
        this.newItem(itemElement);
    }



    newItem(itemElement) {
        const newItem = new DeckItem(itemElement, this.fieldClass, this);
        this.initActionbar(itemElement);
        this.form?.processFields();
		return newItem;
    }

    initActionbar(itemElement) {
        const trash = itemElement.querySelector("button.trash");
        const duplicate = itemElement.querySelector("button.duplicate");
        const idInput = itemElement.querySelector("input[name='deck-item-id']");

        // Ensure item IDs are properly formatted
        idInput?.addEventListener("change", () => {
            // Replace hyphens with underscores and ensure lowercase
            const oldValue = idInput.value;
            const newValue = slugify(oldValue, { lower: true }).replace(/-/g, "_");

            if (oldValue !== newValue) {
                idInput.value = newValue;

                // Update the item's data-item-id attribute
                itemElement.setAttribute('data-item-id', newValue);
                itemElement.className = itemElement.className.replace(/deck-item-\S+/, `deck-item-${newValue}`);

                // Update button titles
                const editBtn = itemElement.querySelector("button.edit");
                const duplicateBtn = itemElement.querySelector("button.duplicate");
                const trashBtn = itemElement.querySelector("button.trash");

                if (editBtn) editBtn.setAttribute("title", `Edit ${newValue} item`);
                if (duplicateBtn) duplicateBtn.setAttribute("title", `Duplicate ${newValue} item`);
                if (trashBtn) trashBtn.setAttribute("title", `Delete ${newValue} item`);
            }
        });

        trash?.addEventListener("click", () => this.removeItem(itemElement));
        duplicate?.addEventListener("click", () => this.duplicateItem(itemElement));
    }

    removeItem(itemElement) {
        // Call destroy method to clean up observers before removing
        if (itemElement.deckitem && typeof itemElement.deckitem.destroy === 'function') {
            itemElement.deckitem.destroy();
        }
        itemElement.remove();
    }

    duplicateItem(itemElement) {
        // Get the current item ID and generate a new one for the duplicate
        const currentId = itemElement.getAttribute('data-item-id');
        const random = Math.random().toString(36).substr(2, 5);
        const newId = `${currentId}_${random}`;

        // Get all form values before cloning
        const selects = itemElement.querySelectorAll('select');
        const selectValues = Array.from(selects).map(select => select.value);

        const inputs = itemElement.querySelectorAll('input:not([name="deck-item-id"]), textarea');
        const inputValues = Array.from(inputs).map(input => {
            if (input.type === 'checkbox' || input.type === 'radio') {
                return input.checked;
            }
            return input.value;
        });

        // Clone the item element
        const clone = itemElement.cloneNode(true);

        // Clean up any existing totalfield references or event listeners on cloned elements
        const clonedElements = clone.querySelectorAll('*');
        clonedElements.forEach(element => {
            if (element.totalfield) {
                delete element.totalfield;
            }
        });

        // Update the cloned element
        clone.className = clone.className.replace(/deck-item-\S+/, `deck-item-${newId}`);
        clone.setAttribute('data-item-id', ''); // Clear item ID for new item

        // Update the ID input - clear it for new item and make it editable
        const idInput = clone.querySelector("input[name='deck-item-id']");
        if (idInput) {
            idInput.value = ''; // Clear the ID for new item
            idInput.removeAttribute('readonly'); // Make it editable again
            idInput.removeAttribute('disabled'); // Remove any disabled state
        }

        // Also clear the dialog ID field
        const dialogIdInput = clone.querySelector("dialog input[name='id']");
        if (dialogIdInput) {
            dialogIdInput.value = '';
            dialogIdInput.removeAttribute('readonly');
            dialogIdInput.removeAttribute('disabled');

            // Remove any locked/disabled state from the field container
            const dialogIdContainer = dialogIdInput.closest('.form-field');
            if (dialogIdContainer) {
                dialogIdContainer.classList.remove('locked');
            }
        }

        // Restore form values after cloning
        const clonedSelects = clone.querySelectorAll('select');
        clonedSelects.forEach((select, index) => {
            if (selectValues[index]) {
                select.value = selectValues[index];
            }
        });

		const clonedFroala = clone.querySelectorAll('.fr-box');
		clonedFroala.forEach(froala => froala.remove());

        const clonedInputs = clone.querySelectorAll('input:not([name="deck-item-id"]), textarea');
        clonedInputs.forEach((input, index) => {
            if (inputValues[index] !== undefined) {
                if (input.type === 'checkbox' || input.type === 'radio') {
                    input.checked = inputValues[index];
                } else {
                    input.value = inputValues[index];
                }
            }
        });

        // Update button titles
        const editBtn = clone.querySelector("button.edit");
        const duplicateBtn = clone.querySelector("button.duplicate");
        const trashBtn = clone.querySelector("button.trash");

        if (editBtn) editBtn.setAttribute("title", `Edit ${newId} item`);
        if (duplicateBtn) duplicateBtn.setAttribute("title", `Duplicate ${newId} item`);
        if (trashBtn) trashBtn.setAttribute("title", `Delete ${newId} item`);

        // Insert after the original item
        const parent = itemElement.parentNode;
        parent.insertBefore(clone, itemElement.nextSibling);

        // Focus on the new item's ID field
        const newIdInput = clone.querySelector("input[name='deck-item-id']");
        newIdInput?.focus();

        // Initialize the duplicated item (this will properly initialize all form fields)
        this.newItem(clone);
    }

    getValue() {
        const deckItems = this.container.getElementsByClassName(this.fieldClass);
        const deckData = {};

        for (const itemElement of deckItems) {
            const deckItem = itemElement.totalfield;
            if (deckItem) {
                const itemId = deckItem.getItemId();
                const itemData = deckItem.getValue();
                if (itemId && itemData) {
                    deckData[itemId] = itemData;
                }
            }
        }

        return deckData;
    }

    setValue(value) {
        // Clear existing items
		this.clearValue();

        // Add items from value
        if (value && typeof value === 'object' && this.template) {
            for (const [itemId, itemData] of Object.entries(value)) {
                // Clone the template
                const clone = this.template.content.cloneNode(true);

                // Insert before add button
                const parent = this.addButton.parentNode;
                parent.insertBefore(clone, this.addButton);

                // Get the newly added item
                const deckItems = parent.querySelectorAll(`.${this.fieldClass}`);
                const itemElement = deckItems[deckItems.length - 1];

                // Set the ID
                const idInput = itemElement.querySelector("input[name='deck-item-id']");
                if (idInput) {
                    idInput.value = itemId;
                }

                // Update item attributes
                itemElement.setAttribute('data-item-id', itemId);
                itemElement.className = itemElement.className.replace(/deck-item-\S*/, `deck-item-${itemId}`);

                // Initialize and set data
                this.newItem(itemElement);
                const deckItem = itemElement.totalfield;
                if (deckItem) {
                    deckItem.setValue(itemData);
                }
            }
        }
    }

    clearValue() {
        const deckItems = this.container.querySelectorAll(`.${this.fieldClass}`);
        deckItems.forEach(item => this.removeItem(item));
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

    schema() {
        return {
            type: "deck",
            fieldset: this.type,
            deckref: this.deckref
        };
    }
}