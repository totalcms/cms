import Dialog from "./dialog";

//-----------------------------------------------
// Total CMS Deck Item
//-----------------------------------------------
export default class DeckItem {

    constructor(container, fieldClass) {
        this.container            = container;
        this.container.totalfield = this;
        this.fieldClass           = fieldClass;
        this.deckref              = '';

        this.dialog = this.setupDialog();
    }

    setupDialog() {
        return new Dialog(this.container.querySelector("dialog"), {
            open: this.container.querySelector("button.edit"),
            close: ".close",
            onOpen: () => {
                if (this.dialogOpened) return;
                this.dialogOpened = true;
                // Process any form fields in the dialog
                if (this.form) {
                    this.form.processFields();
                }
            },
            onClose: () => {
                this.dialogOpened = false;
            }
        });
    }


    getItemId() {
        const idInput = this.container.querySelector("input[name='deck-item-id']");
        return idInput ? idInput.value : this.container.getAttribute('data-item-id') || '';
    }

    getName() {
        return this.getItemId();
    }

    getValue() {
        const data = {};

        // Collect all form fields within this item's dialog
        const formFields = this.dialog.dialog.querySelectorAll('input, textarea, select');

        for (const field of formFields) {
            if (field.name) {
                // Handle different field types
                if (field.type === 'checkbox') {
                    data[field.name] = field.checked;
                } else if (field.type === 'radio') {
                    if (field.checked) {
                        data[field.name] = field.value;
                    }
                } else if (field.tagName === 'SELECT' && field.multiple) {
                    data[field.name] = Array.from(field.selectedOptions).map(option => option.value);
                } else {
                    data[field.name] = field.value;
                }
            }
        }

        return Object.keys(data).length > 0 ? data : null;
    }

    setValue(value) {
        if (!value || typeof value !== 'object') return;

        // Set values for all fields in the dialog
        for (const [fieldName, fieldValue] of Object.entries(value)) {
            const field = this.dialog.dialog.querySelector(`[name="${fieldName}"]`);

            if (field) {
                if (field.type === 'checkbox') {
                    field.checked = Boolean(fieldValue);
                } else if (field.type === 'radio') {
                    if (field.value === fieldValue) {
                        field.checked = true;
                    }
                } else if (field.tagName === 'SELECT' && field.multiple && Array.isArray(fieldValue)) {
                    Array.from(field.options).forEach(option => {
                        option.selected = fieldValue.includes(option.value);
                    });
                } else {
                    field.value = fieldValue;
                }
            }
        }

    }

    clearValue() {
        const formFields = this.dialog.dialog.querySelectorAll('input, textarea, select');

        for (const field of formFields) {
            if (field.name) {
                if (field.type === 'checkbox' || field.type === 'radio') {
                    field.checked = false;
                } else if (field.tagName === 'SELECT' && field.multiple) {
                    Array.from(field.options).forEach(option => {
                        option.selected = false;
                    });
                } else {
                    field.value = '';
                }
            }
        }

    }

    isUnsaved() {
        return this.container.classList.contains("unsaved");
    }

    saved() {
        this.container.classList.remove("unsaved");
    }
}