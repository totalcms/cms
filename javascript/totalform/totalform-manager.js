import TotalForm from './totalform';

//-----------------------------------------------
// Total CMS Form constructor
//-----------------------------------------------
export default class TotalFormManager {

    // Constructors
    constructor() {
		this.processingStart = Date.now();
		this.processingLimit = 1500;
		this.unsavedCounter  = 0;

		this.forms = this.findForms();
		this.registerButtons();
		this.formListeners();
    }

	findForms() {
		const totalforms = [];
		const forms = Array.from(document.querySelectorAll("form.totalform"));
		for (const form of forms) {
			totalforms.push(new TotalForm(form));
		}
		return totalforms;
	}

	formListeners() {
		this.forms.forEach(form => {
			form.form.addEventListener("error", () => this.error());
			form.form.addEventListener("success", () => this.success());
		});
	}
    registerButtons() {
		const saveButtons = Array.from(document.querySelectorAll(":not(form.totalform) .cms-save"));
        saveButtons.forEach(button => {
            button.addEventListener("click", event => this.saveForms());
        });
    }

    saveListener() {
        document.addEventListener("keydown", (event) => {
			if (event.key === "s" && (event.ctrlKey||event.metaKey)) {
				event.preventDefault();
				this.saveForms();
			}
        });
    }

	saveForms() {
		this.processingStart = Date.now();
		this.unsavedCounter = this.forms.filter(form => form.isUnsaved()).length;

		for (const form of this.forms) {
			if (form.isUnsaved()) {
				form.save();
			}
		}
	}

	delayProcessing(callback) {
		// The purpose of this is purely to give the user a visual cue that the form is processing
        const processingTime = Date.now() - this.processingStart;
        const delay = this.processingLimit - processingTime;
		// console.log("Processing Delay",delay);
		window.setTimeout(() => {
            if (typeof callback === "function") callback();
        }, delay);
	}

    error(error) {
		this.delayProcessing(() => {
			console.log("Error saving forms.", error);
        });
    }

    success() {
		this.unsavedCounter--;
		if (this.unsavedCounter !== 0) return;
		this.delayProcessing(() => {
			console.log("All forms saved successfully.");
		});
    }
}
