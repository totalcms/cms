import TotalField from "./totalfield";
import Droplet from "./droplet";
import ImagePreview from "./image-preview";

//-----------------------------------------------
// Total CMS Droplet
//-----------------------------------------------
export default class ImageField extends TotalField {

    constructor(container, options) {
        super(container, options);

		this.previewContainer = container.querySelector(".total-preview");

		this.setupPreview();
		this.setupDroplet();
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
			rules            : this.options.rules,
		});
	}

	autosave(force = false) {
		// Only autosave if we are in edit mode
		if (!this.form.isEditMode()) return;

		if (!force && !this.isUnsaved()) return;

		const patchApi = `/collections/${this.form.collection}/${this.form.id}/${this.property}`;
		this.form.api.postAPI(patchApi, this.getValue(), "put").then(response => {
			console.log("Image Meta Autosaved", response);
			this.saved();
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

	schema() {
        return {
            type     : "image",
            fieldset : this.type
        };
    }
}
