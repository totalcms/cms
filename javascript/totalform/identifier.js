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

		if (this.form.isEditMode()) {
			// The ID cannot be changed in edit mode
			this.disable();
		}
    }

	changed() {
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

		// Magic happens here
		const autogen = this.options.autogen.replace(/\${(.*?)}/g, (match, key) => data[key]);
		return this.slugify(autogen);
	}

	disable() {
		return this.input.setAttribute("disabled", true);
	}

	lock() {
		return this.input.classList.add("locked");
	}

	isLocked() {
		return this.input.classList.contains("locked") || this.input.hasAttribute("disabled");
	}

    slugify(id){
        return slugify(id).toLowerCase();
    }

    idExists() {
		console.warn("ID already exists", this.getValue());
        this.input.classList.remove("saving", "success");
        this.input.classList.add("error");
    }

    idAvailable() {
        this.input.classList.remove("saving", "error");
        this.input.classList.add("success");
    }

    validateIdExists() {
		const id = this.getValue();
		if (!id) return;
        // Check that the id exists on the server or not
		const api = `/collections/${this.form.collection}/${id}`;
        this.api.existsAPI(api).then(response => {
			response.ok ? this.idExists() : this.idAvailable();
		});
    }
}

