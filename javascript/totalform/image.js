import TotalField from "./totalfield";
import Droplet from "./droplet";
import ImagePreview from "./image-preview";

//-----------------------------------------------
// Total CMS Droplet
//-----------------------------------------------
export default class ImageField extends TotalField {

    constructor(container, settings) {
        super(container, settings);

		this.previewContainer = container.querySelector(".total-preview");

		this.setupPreview();
		this.setupDroplet();
    }

	uploadComplete() {
		// this is a hack to mark the field and form as saved
		// When a new image finishes uploading, a preview is created
		// and the field is marked as unsaved. This timeout is to give
		// the preview time to be created before marking the field as saved
		setTimeout(() => {
			this.saved();
			this.form.uploadComplete();
		}, 1000);
	}

	apiUploadImage() {
		const api = `/collections/${this.form.collection}/${this.form.id}/${this.property}`;
		return this.api.buildApiQuery(api);
    }

	updateAPIUrl() {
		this.droplet.updateUrl(this.apiUploadImage());
	}

	setupPreview(image) {
		const imagePreview = this.previewContainer.children.item(0);
		const preview = new ImagePreview(imagePreview, this);
		if (image) preview.setValue(image);
		this.preview = preview;

		Array.from(preview.fields).forEach(field => {
			field.addEventListener("subfield-change", () => this.changed());
		});
	}

	setupDroplet() {
		this.droplet = new Droplet(this, {
			paramName        : this.property,
			apiUrl           : this.apiUploadImage(),
			autoProcessQueue : this.form.isEditMode(),
			acceptedFiles    : "image/*",
			rules            : this.settings.rules,
		});
		this.droplet.onQueueComplete(() => this.uploadComplete());
	}

	autosave(force = false) {
		// Only autosave if we are in edit mode
		if (!this.form.isEditMode()) return;

		if (!force && !this.isUnsaved()) return;

		const patchApi = `/collections/${this.form.collection}/${this.form.id}/${this.property}`;
		this.form.api.postAPI(patchApi, this.getValue(), "put").then(response => {
			console.log("Image Meta Autosaved", response);
			this.saved();
		}).catch(error => {
			console.error("Image Meta Autosave failed", error);
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

    setValue(image) {
		this.preview.setValue(image);
		this.saved();
    }

	fileAdded(file) {
		// Do nothing
	}

	fileUploaded(file, response) {
		const image = response.data[this.property];
		this.setupPreview(image);
	}

	validate() {
		// Clear any previous custom validity and call parent validation
		this.input.setCustomValidity("");

		if (!this.isVisible()) return true;

		// Check if field is required
		if (this.input.required) {
			this.input.value = this.property; // Set a dummy value to satisfy HTML5 required validation
			// Check if image has been uploaded using the preview's hasImage method
			if (!this.hasImage()) {
				const message = `${this.label} is required - please upload an image`;
				this.input.setCustomValidity(message);
				this.input.reportValidity();
				this.error(message);
				return false;
			}
		}

		return super.validate();
	}

	hasImage() {
		// Check if the preview container has the "not-found" class
		const preview = this.previewContainer.querySelector(".dz-preview");
		if (preview && preview.classList.contains("not-found")) {
			return false;
		}

		// Check if there's an image with a valid src
		const img = this.previewContainer.querySelector(".dz-preview img");
		if (!img || !img.src) {
			return false;
		}

		return true;
	}

	schema() {
        return {
            type     : "image",
            fieldset : this.type
        };
    }
}
