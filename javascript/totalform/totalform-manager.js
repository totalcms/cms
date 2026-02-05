import TotalForm from './totalform';

//-----------------------------------------------
// Total CMS Form constructor
//-----------------------------------------------
export default class TotalFormManager {

    // Constructors
    constructor() {
		this.processingStart = Date.now();
		this.processingLimit = 1000;
		this.successLimit    = 2500;
		this.unsavedCounter  = 0;

		this.forms = this.findForms();
		this.registerButtons();
		this.formListeners();

		this.statusBanner = this.createStatusBanner();
    }

	createStatusBanner() {
		const id = "totalcms-status-banner";

		// Check if the banner already exists
		const existing = document.getElementById(id);
		if (existing) return existing;

		// Create the banner
		const banner = document.createElement("div");
		banner.id = id;
		document.body.appendChild(banner);
		return banner;
	}

	bannerStatus(status) {
		if (this.statusBanner.classList.contains(status)) return;
		const remove = Array.from(this.statusBanner.classList);
		if (status) this.statusBanner.classList.add(status);
		this.statusBanner.classList.remove(...remove);
	}

	findForms() {
		const totalforms = [];
		const forms = Array.from(document.querySelectorAll("form.totalform"));
		for (const form of forms) {
			// Skip simple forms - they handle their own initialization
			if (form.classList.contains("simple-form")) continue;
			totalforms.push(new TotalForm(form));
		}
		return totalforms;
	}

	formListeners() {
		this.forms.forEach(form => {
			const useStatusBanner = !form.form.classList.contains("no-status-banner");
			form.form.addEventListener("error", event => {
				if (form.validated) {
					if (useStatusBanner) this.error(event.detail.error);
					return;
				}
				// Validation error - clear processing state
				this.unsavedCounter = 0;
				if (useStatusBanner) this.bannerStatus();
			});
			form.form.addEventListener("success", () => {
				if (useStatusBanner) this.success();
			});
		});
		// Save on CMD/Ctrl+S
		document.addEventListener("keydown", (event) => {
			if (event.key === "s" && (event.ctrlKey||event.metaKey)) {
				event.preventDefault();
				this.saveAllUnsavedForms();
			}
        });
		// Prevent Enter key from submitting forms
		document.addEventListener("keydown", (event) => {
			if (event.key === "Enter") {
				const target = event.target;
				const form = target.closest("form");

				// Only prevent Enter on forms with totalform or simple-form classes
				if (form && (form.classList.contains("totalform") || form.classList.contains("simple-form"))) {
					// Allow Enter in textareas and certain input types
					if (target.tagName === "TEXTAREA" ||
						target.type === "search" ||
						target.classList.contains("allow-enter")) {
						return;
					}

					event.preventDefault();
				}
			}
        });
	}

    registerButtons() {
		const buttonSelector = "button.cms-save,a.cms-save,.cms-save a,.cms-save button";
		const saveButtons = Array.from(document.querySelectorAll(buttonSelector));
		const externalButtons = saveButtons.filter(button => button.closest("form.totalform") === null);
		const internalButtons = saveButtons.filter(button => button.closest("form.totalform") !== null);

        externalButtons.forEach(button => {
            button.addEventListener("click", event => {
				event.preventDefault();
				this.saveAllUnsavedForms();
			});
        });

		const saveFormWithButton = (button) => {
			button.addEventListener("click", event => {
				event.preventDefault();

				const form = event.target.closest("form");
				const totalform = form.totalform;

				// Skip if this is a simple-form (they handle their own submission)
				if (!totalform) return;

				if (!totalform.validate()) return;

 				// Only one form to save
 				this.unsavedCounter = 1;
				if (!form.classList.contains("no-status-banner")) {
					this.startProcessing();
				}
				totalform.save();
			});
		};

        internalButtons.forEach(button => saveFormWithButton(button));

		// Watch for new save buttons added to droplets
		const observer = new MutationObserver((mutationsList, observer) => {
			for (const mutation of mutationsList) {
				if (mutation.type === 'childList') {
					mutation.addedNodes.forEach(node => {
						if (node.nodeType === Node.ELEMENT_NODE) {
							const button = node.querySelector(buttonSelector);
							if (button) saveFormWithButton(button);
						}
					});
				}
			}
		});

		// Start observing for droplet previews
		const dropletPreviews = Array.from(document.querySelectorAll("form.totalform .total-preview"));
		dropletPreviews.forEach(preview => observer.observe(preview, { childList: true }));
	}

	startProcessing() {
		this.processingStart = Date.now();
		this.bannerStatus("processing");
	}

	saveAllUnsavedForms() {
		this.unsaved = this.forms.filter(form => form.isUnsaved());
		this.unsavedCounter = this.unsaved.length;

		if (this.unsavedCounter === 0) {
			console.log("No unsaved forms to save.");
			return;
		}

		// Validate forms and do not save unless all are valid
		for (const form of this.unsaved) {
			if (!form.validate()) return;
		}

		// Only show status banner if at least one form wants it
		const showBanner = this.unsaved.some(form => !form.form.classList.contains("no-status-banner"));
		if (showBanner) this.startProcessing();

		this.unsaved.forEach(form => form.save());
	}

	delayProcessing(callback) {
		// The purpose of this is purely to give the user a visual cue that the form is processing
        const processingTime = Date.now() - this.processingStart;
        const delay = this.processingLimit - processingTime;
		window.setTimeout(() => {
            if (typeof callback === "function") callback();
        }, delay);
	}

    error(error) {
		if (error) {
			this.statusBanner.style.setProperty('--totalform-formerror', `"${error.toString().replace(/"/g,'')}"`);
		}
		this.delayProcessing(() => {
			this.bannerStatus("error");
			this.statusBanner.addEventListener("click", () => {
				navigator.clipboard.writeText(error);
				this.bannerStatus();
				this.statusBanner.style.setProperty('--totalform-formerror', "");
			}, {once: true});
        });
    }

    success() {
		this.unsavedCounter--;
		if (this.unsavedCounter !== 0) return;
		this.delayProcessing(() => {
			this.bannerStatus("success");
			console.log("All forms saved successfully.");
			window.setTimeout(() => this.bannerStatus(), this.successLimit);
		});
    }
}
