import TotalField from "./totalfield";
import Droplet from "./droplet";
import FilePreview from "./file-preview";

//-----------------------------------------------
// Total CMS Droplet
//-----------------------------------------------
export default class FileField extends TotalField {

	// TODO: this class and ImageField are very similar. They should be combined

    constructor(container, options) {
        super(container, options);

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
		const api = `/collections/${this.form.collection}/${this.form.id}/${this.property}`;
		return this.api.buildApiQuery(api);
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
			autoProcessQueue : this.form.isEditMode(),
			// acceptedFiles    : "image/*",
			rules            : this.options.rules,
		});
		this.droplet.onQueueComplete(() => this.uploadComplete());
	}

	autosave(force = false) {
		// Only autosave if we are in edit mode
		if (!this.form.isEditMode()) return;

		if (!force && !this.isUnsaved()) return;

		const patchApi = `/collections/${this.form.collection}/${this.form.id}/${this.property}`;
		this.form.api.postAPI(patchApi, this.getValue(), "put").then(response => {
			console.log("File Meta Autosaved", response);
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

    setValue(file) {
		this.preview.setValue(file);
		this.saved();
    }

	fileAdded(file) {
		// Do nothing
	}

	fileUploaded(file, response) {
		const fileData = response.data[this.property];
		this.setupPreview(fileData);
	}

	schema() {
        return {
            type     : "file",
            fieldset : this.type
        };
    }
}
