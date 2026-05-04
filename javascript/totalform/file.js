import TotalField from "./totalfield";
import Droplet from "./droplet";
import FilePreview from "./file-preview";

//-----------------------------------------------
// Total CMS Droplet
//-----------------------------------------------
export default class FileField extends TotalField {

    constructor(container, settings) {
        super(container, settings);

		this.previewContainer = container.querySelector(".total-preview");

		this.setupPreview();
		this.setupDroplet();
    }

	uploadComplete() {
		// this is a hack to mark the field and form as saved
		// When a new file finishes uploading, a preview is created
		// and the field is marked as unsaved. This timeout is to give
		// the preview time to be created before marking the field as saved
		setTimeout(() => {
			this.saved();
			this.form.uploadComplete();
		}, 1000);
	}

	apiUploadFile() {
		return this.api.buildApiQuery(this.buildPropertyApi('/collections'));
    }

	updateAPIUrl() {
		this.droplet.updateUrl(this.apiUploadFile());
	}

	setupPreview(file) {
		const filePreview = this.previewContainer.children.item(0);
		const preview = new FilePreview(filePreview, this);
		if (file) preview.setValue(file);
		this.preview = preview;

		Array.from(preview.fields).forEach(field => {
			field.addEventListener("subfield-change", () => this.changed());
		});
	}

	setupDroplet() {
		this.droplet = new Droplet(this, {
			paramName        : this.property,
			apiUrl           : this.apiUploadFile(),
			autoProcessQueue : this.parentIsSaved(),
			acceptedFiles    : null,
			chunking         : true,
			rules            : this.settings.rules,
		});
		this.droplet.onQueueComplete(() => this.uploadComplete());
	}

	autosave(force = false) {
		// Only autosave if we are in edit mode
		if (!this.form.isEditMode()) return;

		if (!force && !this.isUnsaved()) return;

		// Top-level: PUT /coll/id/prop. Card-nested: PUT /coll/id/cardprop/childkey.
		const updateApi = this.buildPropertyApi('/collections');
		this.form.api.postAPI(updateApi, this.getValue(), "put").then(response => {
			console.log("File Meta Autosaved", response);
			this.saved();
		}).catch(error => {
			console.error("File Meta Autosave failed", error);
			// Keep unsaved state so user knows to retry
		});
	}

	isUnsaved() {
		const unsavedChildren = this.previewContainer.querySelectorAll(".unsaved");
		return this.container.classList.contains("unsaved") || unsavedChildren.length > 0;
	}

	saved() {
		super.saved();
		const unsavedChildren = this.previewContainer.querySelectorAll(".unsaved");
		unsavedChildren.forEach(unsavedChild => unsavedChild.classList.remove("unsaved"));
	}

    getValue() {
		return this.preview.getValue();
    }

	clearValue() {
		this.preview.clearValue();
	}

    setValue(file) {
		this.preview.setValue(file);
		this.saved();
    }

	fileAdded(file) {
		const notFound = this.previewContainer.querySelector(".not-found");
		if (notFound) notFound.classList.remove("not-found");
	}

	fileUploaded(file, response) {
		// Top-level: response.data[this.property]. Card-nested: descend to
		// response.data[cardParent][childKey] where childKey is this.property.
		const ctx = this.getUploadContext();
		let fileData;
		if (ctx?.subpath) {
			fileData = response.data?.[ctx.property]?.[this.property];
		} else {
			fileData = response.data?.[this.property];
		}
		this.setupPreview(fileData);
	}

	validate() {
		// Clear any previous custom validity and call parent validation
		this.input.setCustomValidity("");

		if (!this.isVisible()) return true;

		// Check if field is required
		if (this.input.required) {
			this.input.value = this.property; // Set a dummy value to satisfy HTML5 required validation
			// Check if file has been uploaded using the preview's hasFile method
			if (!this.hasFile()) {
				const message = `${this.label} is required - please upload a file`;
				this.input.setCustomValidity(message);
				this.input.reportValidity();
				this.error(message);
				return false;
			}
		}

		return super.validate();
	}

	hasFile() {
		// Check if the preview container has the "not-found" class
		const preview = this.previewContainer.querySelector(".dz-preview");
		if (preview && preview.classList.contains("not-found")) {
			return false;
		}

		return true;
	}

	schema() {
        return {
            type     : "file",
            fieldset : this.type
        };
    }
}
