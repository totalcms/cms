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
