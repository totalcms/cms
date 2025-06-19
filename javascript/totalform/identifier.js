import TotalField from './totalfield';
const slugify = require('slugify')

//-----------------------------------------------
// Total CMS ID Field Automation
//-----------------------------------------------
export default class Identifier extends TotalField {

    constructor(container, options) {
        // Define option defaults
        const defaults = {
            autogen : false,
        };
        options = Object.assign({}, defaults, options);

		super(container, options);

		this.valid = false;

		if (this.form.isEditMode()) {
			// The ID cannot be changed in edit mode
			this.disable();
			this.valid = false;
		}
		if (this.getValue() === "" && this.options.autogen) {
			this.setValue(this.autogenId());
		}
    }

	changed() {
		this.form.setId(this.getValue());
		// don't trigger change events for ID field
		// turning this on will cause infinite event loops
		return;
	}

	// Override TotalField.changeListener
	changeListener() {
		if (this.options.autogen) {
			// autogen example: ${title}-${timestamp}
			const autogenNames = this.options.autogen.match(/\${(.*?)}/g).map(v => v.slice(2, -1));
			const reservedNames = ["now", "timestamp", "uuid", "id"];
			autogenNames.forEach(name => {
				// Skip reserved names
				if (reservedNames.includes(name)) return;
				// Only listen to the fields that are used in the autogen string
				this.form.form.querySelector(`[name=${name}]`).addEventListener("change", e => {
					if (this.isLocked()) return;
					this.setValue(this.autogenId());
					this.validateIdExists();
				});
			});
		}
        // Check ID changes directly
        this.input.addEventListener("input",  e => this.lock(), {once: true});
        this.input.addEventListener("change", e => this.validateIdExists());
	}

	autogenId() {
		// Get the field data from the form
		let data = this.form.generateData();
		// Filter out non-string values from data
		data = Object.entries(data).reduce((acc, [key, value]) => {
			if (typeof value === 'string') {
				acc[key] = value;
			}
			return acc;
		}, {});

		// Add some default data
		data.now       = Date.now();
		data.timestamp = new Date().toISOString().slice(0, -5).replace(/-|:/g, '');
		data.uuid      = Math.random().toString(36).substring(2,9);

		// Examples: ${title}-${timestamp}, ${title}-${now}, ${title}-${uuid}

		// Magic happens here
		const autogen = this.options.autogen.replace(/\${(.*?)}/g, (match, key) => data[key] || "");
		return this.slugify(autogen);
	}

	disable() {
		return this.input.setAttribute("disabled", true);
	}

	lock() {
		return this.container.classList.add("locked");
	}

	isLocked() {
		return this.container.classList.contains("locked") || this.input.hasAttribute("disabled");
	}

    slugify(id) {
		id = id.replace('@', '-at-').replace('.', '-');
        return slugify(id, {
			replacement : '-', // replace spaces with replacement character, defaults to `-`
			remove      : /[*+~.()'"!:@]/g, // remove characters that match regex, defaults to `undefined`
			lower       : true, // convert to lower case, defaults to `false`
			strict      : false, // strip special characters except replacement, defaults to `false`
			trim        : true, // trim leading and trailing replacement chars, defaults to `true`
			// locale      : 'vi', // language code of the locale to use
		});
    }

    idExists() {
		this.valid = false;
		this.form.setId("");
		console.warn("ID already exists: "+this.getValue());
        this.container.classList.remove("unsaved");
        this.container.classList.add("error");
		this.input.setCustomValidity("ID already exists");
    }

    idAvailable() {
		this.valid = true;
		this.form.setId(this.getValue());
		this.form.unsaved();
        this.container.classList.remove("error");
        this.container.classList.add("unsaved");
		this.input.setCustomValidity("");
    }

	updateNonIDProperty() {
		this.valid = true;
		this.form.unsaved();
    }

	validate() {
		if (this.valid && this.input.checkValidity()) {
			return true;
		}
		this.error(this.input.validationMessage);
		return false;
	}

    validateIdExists() {
		// slugify the value to ensure it's a valid ID
		const id = this.slugify(this.getValue());
		if (!id) return;

		// Set the slugified value
		this.setValue(id);

		// If the property is not ID, do not check for existence
		// This is for when you use and ID field on a non-ID property
		if (this.property !== "id") {
			this.updateNonIDProperty();
			return;
		}

		let api = `/collections/${this.form.collection}/${id}`;

		if (this.form.isCollectionForm()) {
			api = `/collections/${id}`;
		}
		if (this.form.isSchemaForm()) {
			api = `/schemas/${id}`;
		}

        this.api.existsAPI(api).then(response => {
			response.ok ? this.idExists() : this.idAvailable();
		});
    }
}

