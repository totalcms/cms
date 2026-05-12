import TotalDispatcher from "./dispatcher";
import Autogen from "./autogen";
import Calc from "./calc";

//-----------------------------------------------
// Total CMS Generic Field
//-----------------------------------------------
export default class TotalField {

    constructor(container, settings) {
		this.container = container;
		this.input     = this.container.querySelector("input,textarea,select");

		if (!this.input) {
			throw new Error(`TotalField: no input element found in .form-field[data-type="${container.dataset.type}"]`);
		}

		// Check if we're inside a deck-item or deck-table-row
		this.deckItem = container.closest('.deck-item') || container.closest('.deck-table-row');
		this.isInDeck = !!this.deckItem;

		container.totalfield = this;

		this.type = container.dataset.type;
		this.property = this.input.name;

		// Extract label text from the label element
		const labelElement = this.container.querySelector('label');
		this.label = labelElement ? labelElement.textContent.trim() : this.property;

        // Define option defaults
        const defaults = {
			form        : null,
			sortOptions : false,
        };
        this.settings = Object.assign({}, defaults, settings);
        this.form = this.settings.form;

		if (this.container.dataset.settings) {
			this.settings = Object.assign(this.settings, JSON.parse(this.container.dataset.settings));
		}

        // Delele the form from the settings in case its used in JSON
        delete this.settings.form;

        if (this.form) {
            this.api = this.form.api;
        }

		this.dispatcher = new TotalDispatcher(this.container);
		// Subclasses (image, gallery, file, depot) override getValue() to read from
		// properties they set up after super(). Fall back to input.value here; the
		// subclass's first changed() event will overwrite storedValue with the real value.
		try {
			this.storedValue = this.getValue();
		} catch (e) {
			this.storedValue = this.input.value;
		}

		this.changeListener();
		this.initAutogen();
		this.initCalc();
		if (this.settings.sortOptions) {
			this.sortOptions();
		} else {
			this.cleanupDuplicateOptions();
		}
    }

	changeListener() {
		if (this.container.classList.contains("no-change-listener")) {
			// If the field has the no-change-listener class, don't add change listeners
			return;
		}

		// the change event happens more than once so the ID field can be updated for every change
		this.container.addEventListener("change", () => this.changed());
		// the input event happens once since the point is to mark the form as unsaved ASAP
		this.input.addEventListener("input", () => this.changed());
	}

	/**
	 * Initialize generic autogen support for non-ID fields.
	 * ID fields handle their own autogen via Identifier class.
	 */
	initAutogen() {
		if (!this.settings.autogen) return;
		if (this.type === 'id' || this.type === 'slug') return; // Handled by Identifier

		this.autogen = new Autogen(this);

		// Defer initial generation until the form is fully initialized
		// so that generateData() can read values from all fields
		this.form.form.addEventListener('totalform:ready', () => {
			if (this.getValue() === "") {
				this.input.value = this.autogen.generate();
			}
		}, { once: true });

		// Listen for changes to referenced fields
		this.autogen.attachListeners(() => {
			this.input.value = this.autogen.generate();
			this.changed();
		});
	}

	/**
	 * Initialize calc support for computed number fields.
	 * Evaluates a math expression referencing other fields.
	 */
	initCalc() {
		if (!this.settings.calc) return;

		this.calc = new Calc(this);

		// Make field readonly since it's computed
		this.input.readOnly = true;
		this.container.classList.add('calc-field');

		// Calculate initial value
		const result = this.calc.evaluate();
		if (result !== null) {
			this.input.value = result;
		}

		// Recalculate when referenced fields change
		this.calc.attachListeners(() => {
			const val = this.calc.evaluate();
			if (val !== null) {
				this.input.value = val;
				this.changed();
			}
		});
	}

	cleanupDuplicateOptions() {
		const container = this.container.querySelector("select,datalist");
		if (!container) return;
		const options = Array.from(container.querySelectorAll("option"));
		const values = [];
		options.forEach((option) => {
			if (values.includes(option.value)) {
				return option.remove();
			}
			values.push(option.value);
		});
	}

	sortOptions() {
		const container = this.container.querySelector("select,datalist");
		if (!container) return;
		if (container.querySelector("optgroup")) {
			const optgroups = Array.from(container.querySelectorAll("optgroup"));
			optgroups.forEach((optgroup) => {
				const options = Array.from(optgroup.querySelectorAll("option"));
				options.sort((a, b) => a.text.localeCompare(b.text));
				options.forEach((option) => optgroup.appendChild(option));
			});
			this.cleanupDuplicateOptions();
			return;
		}
		const options = Array.from(container.querySelectorAll("option"));
		options.sort((a, b) => a.text.localeCompare(b.text));
		options.forEach((option) => container.appendChild(option));

		this.cleanupDuplicateOptions();

		const placeholder = container.querySelector(".placeholder");
		if (placeholder) container.insertBefore(placeholder, container.firstChild);
	}

	isSubField() {
		// Filter for determining if a field is a subproperty of another field
		// Need to look at parentNode since closest also looks at self
		// Need to also look for cms-modal since droplets modify the DOM
		// and looking for .form-field is not enough.
		// parentNode is null if the container was detached from the DOM
		// (e.g. a caller mutated DOM and hasn't called refreshFields() yet);
		// a detached node can't be a subfield of anything.
		const parent = this.container.parentNode;
		if (!parent) return false;
		return parent.closest(".form-field") || parent.closest(".cms-modal") ? true : false;
	}

	isDroplet() {
		return (this.droplet && typeof this.droplet === "object");
	}

	/**
	 * Whether this field's parent has been persisted server-side. Used by
	 * upload-capable fields to decide whether to auto-process the dropzone
	 * queue or defer until the parent object (and any unsaved deck item) is
	 * saved by the form. Mirrors the new-object two-phase save: queue first,
	 * flush in saveDroplets().
	 */
	parentIsSaved() {
		if (!this.form?.isEditMode()) return false;
		if (this.deckItem && this.deckItem.classList.contains('unsaved')) return false;
		return true;
	}

	isTiptap() {
		return (this.tiptap && typeof this.tiptap === "object");
	}

    getValue() {
        return this.input.value;
    }

	/**
	 * Returns the upload-target context for this field as
	 * { collection, id, property, subpath }.
	 *
	 * For a top-level field, property is the field's own name and subpath is ''.
	 * For a field inside a card, property is the card's name and subpath is the
	 * field's own name. For a field inside a deck item, property is the deck's
	 * name and subpath is `${itemId}/${this.property}`.
	 *
	 * Single-level nesting only — card-in-deck (or deeper) returns the outermost
	 * container's context and is not yet supported.
	 */
	getUploadContext() {
		if (!this.form) return null;

		const collection = this.form.collection;
		const id         = this.form.getId() ?? '';

		// Parent object must have an ID before any upload can be addressed correctly.
		if (!id) return null;

		// Deck item ancestry — wins over card detection because deck items can host cards.
		if (this.deckItem) {
			const deckEl    = this.deckItem.parentElement?.closest('.form-field[data-type="deck"]');
			const deckField = deckEl?.totalfield;
			// Read the item ID directly from the dialog's id input — this is more
			// robust than going through `this.deckItem.deckitem.getItemId()` because
			// the DeckItem JS instance may not have been constructed yet when sibling
			// deck-items recursively trigger field processing during their setup.
			const itemIdInput = this.deckItem.querySelector('dialog input[name="id"]');
			const itemId      = itemIdInput?.value ?? '';
			if (!deckField?.property || !itemId) {
				// Deck item has no ID typed yet. Returning null prevents the URL from
				// being built — caller treats it as "not ready to upload."
				return null;
			}
			return {
				collection,
				id,
				property : deckField.property,
				subpath  : `${itemId}/${this.property}`,
			};
		}

		// Card ancestry — child's parent .form-field is the card.
		const cardEl = this.container.parentElement?.closest('.form-field[data-type="card"]');
		if (cardEl?.totalfield?.property) {
			return {
				collection,
				id,
				property : cardEl.totalfield.property,
				subpath  : this.property,
			};
		}

		// Top-level
		return { collection, id, property: this.property, subpath: '' };
	}

	/**
	 * Whether this field has enough context to perform an upload right now.
	 */
	isUploadReady() {
		return this.getUploadContext() !== null;
	}

	/**
	 * Build a URL path scoped to this field's resolved upload context:
	 *   `${prefix}/${collection}/${id}/${property}[/${subpath}]${suffix}`
	 *
	 * Used by the image/file fields and their previews to keep top-level and
	 * card-nested URLs consistent — for nested fields the subpath segment is
	 * inserted between property and suffix; for top-level it isn't.
	 *
	 * Falls back to form-level data when getUploadContext() returns null (e.g.
	 * a brand-new top-level field before the parent ID is set).
	 */
	buildPropertyApi(prefix, suffix = '') {
		const ctx        = this.getUploadContext();
		const collection = ctx?.collection ?? this.form?.collection ?? '';
		const id         = ctx?.id ?? this.form?.id ?? '';
		const property   = ctx?.property ?? this.property;
		const sub        = ctx?.subpath ? `/${ctx.subpath}` : '';
		return `${prefix}/${collection}/${id}/${property}${sub}${suffix}`;
	}

    setValue(value) {
        this.input.value = value;
		this.changed();
    }

	clearValue() {
		this.setValue("");
	}

	isUnsaved() {
		// Hidden fields don't count as unsaved — the visibility cascade
		// dispatches a synthetic `change` event on a dependent field's
		// container when visibility flips, which legitimately triggers
		// `changed()` and adds `.unsaved`. Without this guard, opening a
		// page form whose visibility rules hide a list/multicheckbox field
		// would mark the form dirty on first render and trigger the
		// beforeunload prompt with no real edits.
		if (this.isHidden()) return false;
		return this.container.classList.contains("unsaved");
	}

    changed() {
		const hadError = this.container.classList.contains("error");
		this.input.setCustomValidity("");
		this.container.classList.remove("error");

		// Check if value actually changed first - if not, return early
		if (this.storedValue === this.getValue()) return;

		// Value changed - update stored value and dispatch event
		this.storedValue = this.getValue();
		this.container.classList.add("unsaved");

		// Always dispatch field-change when value changes
		// This ensures form state is updated (e.g., from "error" to "unsaved")
		if (this.isSubField()) {
			this.dispatcher.dispatchEvent("subfield-change", { field: this });
			return;
		}
		this.dispatcher.dispatchEvent("field-change", { field: this });
    }

	validate() {
		if (!this.isVisible()) return true;
		if (this.input.checkValidity()) return true;
		this.input.reportValidity();
		this.error(this.input.validationMessage);
		return false;
	}

	saved() {
		this.container.classList.remove("unsaved");
	}

	error(message) {
		this.container.classList.add("error");
		this.dispatcher.dispatchEvent("field-error", { field: this, message: message });
		console.warn(`Field Error: ${this.property} - ${message}`);
    }

	//-------------------------
	// Visibility Methods
	//-------------------------
	hide() {
		this.container.style.display = 'none';
		this.container.classList.remove('field-visible');
		this.container.classList.add('field-hidden');
		this.input.setCustomValidity("");
		this.container.classList.remove("error");
		this.disableValidation();
	}

	show() {
		this.container.style.display = '';
		this.container.classList.remove('field-hidden');
		this.container.classList.add('field-visible');
		this.enableValidation();
	}

	isVisible() {
		return !this.container.classList.contains('field-hidden');
	}

	isHidden() {
		return this.container.classList.contains('field-hidden');
	}

	enableValidation() {
		const inputs = this.container.querySelectorAll('input, select, textarea');
		inputs.forEach(input => {
			// Restore original required state
			if (input.dataset.originalRequired !== undefined) {
				if (input.dataset.originalRequired === 'true') {
					input.required = true;
				}
				delete input.dataset.originalRequired;
			}
		});
	}

	disableValidation() {
		const inputs = this.container.querySelectorAll('input, select, textarea');
		inputs.forEach(input => {
			// Save original required state
			if (input.required) {
				input.dataset.originalRequired = 'true';
				input.required = false;
			} else {
				input.dataset.originalRequired = 'false';
			}
		});
	}

    schema() {
        return {
            "type"  : this.type,
            "field" : "text"
        };
    }
}

// Radio Logic
// if (field.nodeName === "INPUT" && field.type === "radio" ) {
//     if (field.checked) {
//         return this.data[key] = field.value;
//     }
// }

// Checkboxes are a special case. We have to grab each checked values and put them into an array.
// else if (field.nodeName === "INPUT" && field.type === "checkbox") {
//     if (field.checked){
//         if (!this.data[key]){
//             this.data[key] = [];
//         }
//         return this.data[key].push(field.value);
//     }
// }
