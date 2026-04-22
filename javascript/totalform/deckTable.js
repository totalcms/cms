import TotalField from "./totalfield";
import TotalSortable from "./total-sortable";

//-----------------------------------------------
// Total CMS Deck Table Field
//-----------------------------------------------
export default class DeckTableField extends TotalField {

    constructor(container, settings) {
        super(container, settings);

        this.deckref = container.dataset.deckref || '';
        this.minItems = parseInt(this.settings.minItems) || 0;
        this.maxItems = parseInt(this.settings.maxItems) || -1;

		this.valid = true;
		this.sortable = null;

        // Get the table body and template
        this.tableBody = this.container.querySelector('.deck-table-body');
        this.template = this.container.querySelector('template.deck-table-template');

        // Initialize existing rows
        const rows = this.tableBody?.querySelectorAll('.deck-table-row') || [];
        for (const row of rows) {
            this.initRow(row);
        }
        this.initSortable();

        // Initialize oid counter based on existing items
        this.initOidCounter();

        // Setup add button
        this.addButton = this.container.querySelector('.cms-add');
        this.addButton?.addEventListener('click', () => this.addRow());

        this.updateAddButton();

        // Propagate subfield changes to the deck table's own changed handler
        this.container.addEventListener('subfield-change', () => this.changed());
    }

    initOidCounter() {
        const existingCount = this.tableBody?.querySelectorAll('.deck-table-row').length || 0;
        this.oidCounter = existingCount;
    }

    getNextOid() {
        this.oidCounter++;
        return this.oidCounter;
    }

    initSortable() {
        if (this.sortable) {
            this.sortable.destroy();
        }

        if (!this.tableBody) return;

        this.sortable = new TotalSortable(this.tableBody, {
            handle: '.sort-handle',
            onEnd: () => this.changed(),
        });
    }

    /**
     * Regenerate unique IDs in a cloned element to prevent duplicate id/for collisions.
     */
    regenerateIds(element) {
        const idMap = {};

        element.querySelectorAll('[id]').forEach(el => {
            const oldId = el.id;
            const match = oldId.match(/^(field|help|datalist)-(.+)$/);
            if (!match) return;

            const prefix = match[1];
            const oldUuid = match[2];

            if (!idMap[oldUuid]) {
                idMap[oldUuid] = Math.random().toString(36).substring(2, 15);
            }

            el.id = `${prefix}-${idMap[oldUuid]}`;
        });

        for (const [oldUuid, newUuid] of Object.entries(idMap)) {
            element.querySelectorAll(`[for="field-${oldUuid}"]`).forEach(el => {
                el.setAttribute('for', `field-${newUuid}`);
            });
            element.querySelectorAll(`[aria-describedby="help-${oldUuid}"]`).forEach(el => {
                el.setAttribute('aria-describedby', `help-${newUuid}`);
            });
            element.querySelectorAll(`[list="datalist-${oldUuid}"]`).forEach(el => {
                el.setAttribute('list', `datalist-${newUuid}`);
            });
        }
    }

    initRow(row) {
        // Store reference on the DOM element
        row.deckTableRow = this;

        // Setup action button listeners
        const trash = row.querySelector('button.trash');
        trash?.addEventListener('click', () => this.removeRow(row));

        // Process fields in the row
        this.form?.processFields();
    }

    updateAddButton() {
        if (!this.addButton) return;
        const rowCount = this.tableBody?.querySelectorAll('.deck-table-row').length || 0;
        const atMax = this.maxItems > -1 && rowCount >= this.maxItems;
        this.addButton.disabled = atMax;
    }

    addRow() {
        if (!this.template || !this.tableBody) return;

        const rowCount = this.tableBody.querySelectorAll('.deck-table-row').length;
        if (this.maxItems > -1 && rowCount >= this.maxItems) return;

        const clone = this.template.content.cloneNode(true);
        this.regenerateIds(clone);

        this.tableBody.appendChild(clone);

        // Get the newly added row
        const rows = this.tableBody.querySelectorAll('.deck-table-row');
        const newRow = rows[rows.length - 1];

        this.initRow(newRow);

        // Auto-populate ID if autogen pattern exists
        this.autoPopulateId(newRow);

        this.changed();
        this.updateAddButton();
    }

    autoPopulateId(row) {
        const labelPattern = this.container.dataset.deckLabelPattern || '${id}';
        const idInput = row.querySelector("input[name='id']");
        if (!idInput) return;

        // Check for auto-generating ID patterns
        const hasAutoPattern = /\$\{(uuid|uid|oid|timestamp|now|currentyear|currentmonth|currentday)/.test(labelPattern);
        if (!hasAutoPattern) return;

        const now = new Date();
        const fieldData = {
            now: Date.now(),
            timestamp: now.toISOString().slice(0, -5).replace(/-|:/g, ''),
            uuid: this.generateUuid(),
            uid: Math.random().toString(36).substring(2, 9),
            oid: this.getNextOid(),
            currentyear: now.getFullYear().toString(),
            currentyear2: now.getFullYear().toString().slice(-2),
            currentmonth: String(now.getMonth() + 1).padStart(2, '0'),
            currentday: String(now.getDate()).padStart(2, '0'),
        };

        let generatedId = labelPattern.replace(/\${(.*?)}/g, (match, key) => {
            if (key.startsWith('oid-') && /^oid-0+$/.test(key)) {
                const zeros = key.substring(4);
                const paddingLength = zeros.length;
                return this.getNextOid().toString().padStart(paddingLength, '0');
            }
            return fieldData[key] || '';
        });

        idInput.value = generatedId.trim();
    }

    generateUuid() {
        return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, function(c) {
            const r = Math.random() * 16 | 0;
            const v = c == 'x' ? r : (r & 0x3 | 0x8);
            return v.toString(16);
        });
    }

    removeRow(row) {
        row.remove();
        this.changed();
        this.updateAddButton();
    }

    getValue() {
        const deckData = {};

        if (!this.tableBody) return deckData;

        const rows = this.tableBody.querySelectorAll('.deck-table-row');
        for (const row of rows) {
            const rowData = {};
            let itemId = '';

            const formFieldContainers = row.querySelectorAll('.form-field');
            for (const container of formFieldContainers) {
                if (container.totalfield && container.totalfield.input && container.totalfield.input.name) {
                    const fieldName = container.totalfield.input.name;
                    try {
                        rowData[fieldName] = container.totalfield.getValue();
                    } catch (error) {
                        console.warn(`Failed to get value for field ${fieldName} in deck table row:`, error);
                        if (container.totalfield.input) {
                            rowData[fieldName] = container.totalfield.input.value || '';
                        }
                    }

                    if (fieldName === 'id') {
                        itemId = String(rowData[fieldName]);
                    }
                }
            }

            if (itemId) {
                deckData[itemId] = rowData;
            }
        }

        return Object.keys(deckData).length === 0 ? {} : deckData;
    }

    setValue(value) {
        console.warn("DeckTableField.setValue() is not implemented", value);
    }

    clearValue() {
        if (this.tableBody) {
            this.tableBody.innerHTML = '';
        }
    }

    isUnsaved() {
        const unsavedChildren = this.container.querySelectorAll('.unsaved');
        return this.container.classList.contains('unsaved') || unsavedChildren.length > 0;
    }

    saved() {
        super.saved();
        const unsavedChildren = this.container.querySelectorAll('.unsaved');
        unsavedChildren.forEach(el => el.classList.remove('unsaved'));
    }

    error(message) {
        this.input.setCustomValidity(message);
        super.error(message);
    }

    changed() {
        this.input.setCustomValidity('');
        super.changed();
    }

    validate() {
        this.input.setCustomValidity('');

        if (!this.isVisible()) return true;

        let isValid = true;

        const rows = this.tableBody?.querySelectorAll('.deck-table-row') || [];

        // Check if required and empty
        if (this.input.required && rows.length === 0) {
            const errorMessage = 'Please add at least one.';
            this.input.setCustomValidity(errorMessage);
            this.input.reportValidity();
            this.error(errorMessage);
            this.valid = false;
            return this.valid;
        }

        // Check minimum item count
        if (this.minItems > 0 && rows.length < this.minItems) {
            const errorMessage = `Please add at least ${this.minItems} items`;
            this.input.setCustomValidity(errorMessage);
            this.input.reportValidity();
            this.error(errorMessage);
            this.valid = false;
            return this.valid;
        }

        // Check maximum item count
        if (this.maxItems > -1 && rows.length > this.maxItems) {
            const errorMessage = `Maximum ${this.maxItems} items allowed`;
            this.input.setCustomValidity(errorMessage);
            this.input.reportValidity();
            this.error(errorMessage);
            this.valid = false;
            return this.valid;
        }

        const itemIds = [];

        for (const row of rows) {
            // Get the ID from this row
            const idInput = row.querySelector("input[name='id']");
            const itemId = idInput ? String(idInput.value) : '';

            if (itemId.length === 0) {
                const errorMessage = 'Item ID cannot be empty';
                this.error(errorMessage);
                isValid = false;
                continue;
            }

            if (itemIds.includes(itemId)) {
                const errorMessage = `Duplicate ID found: ${itemId}`;
                this.error(errorMessage);
                isValid = false;
                continue;
            }

            itemIds.push(itemId);

            // Validate fields inside this row
            const formFieldContainers = row.querySelectorAll('.form-field');
            for (const container of formFieldContainers) {
                if (container.totalfield && typeof container.totalfield.validate === 'function') {
                    if (!container.totalfield.validate()) {
                        isValid = false;
                    }
                }
            }
        }

        this.valid = isValid;
        return this.valid;
    }

    schema() {
        return {
            type: "deck",
            fieldset: this.type,
            deckref: this.deckref
        };
    }
}
