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
		const remove = Array.from(this.statusBanner.classList);
		if (status) this.statusBanner.classList.add(status);
		this.statusBanner.classList.remove(...remove);
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
			form.form.addEventListener("error", event => this.error(event.detail.error));
			form.form.addEventListener("success", () => this.success());
		});
		// Save on CMD/Ctrl+S
		document.addEventListener("keydown", (event) => {
			if (event.key === "s" && (event.ctrlKey||event.metaKey)) {
				event.preventDefault();
				this.saveAllUnsavedForms();
			}
        });
	}

    registerButtons() {
		const externalButtons = Array.from(document.querySelectorAll(":not(form.totalform) .cms-save"));
        externalButtons.forEach(button => {
            button.addEventListener("click", event => {
				event.preventDefault();
				this.saveAllUnsavedForms();
			});
        });

		const internalButtons = Array.from(document.querySelectorAll("form.totalform .cms-save"));
        internalButtons.forEach(button => {
            button.addEventListener("click", event => {
				event.preventDefault();
				this.startProcessing();
				this.unsavedCounter = 1; // Only one form to save
				const totalform = button.closest("form").totalform;
				totalform.save();
			});
        });
	}

	startProcessing() {
		this.processingStart = Date.now();
		this.bannerStatus("processing");
	}

	saveAllUnsavedForms() {
		this.startProcessing();
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
		window.setTimeout(() => {
            if (typeof callback === "function") callback();
        }, delay);
	}

    error(error) {
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
			this.processingStart = Date.now();
			this.delayProcessing(() => this.bannerStatus());
		});
    }
}
