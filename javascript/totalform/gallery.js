import ImageField from "./image";
import ImagePreview from "./image-preview";
import DropletArray from "./droplet-array";

//-----------------------------------------------
// Total CMS Gallery Field
//-----------------------------------------------
export default class GalleryField extends ImageField {

    constructor(container, options) {
        super(container, options);
    }

	setupPreview(image) {
		const previews = Array.from(this.container.getElementsByClassName("total-preview"));
		return previews.map(preview => {
			if (preview.preview) return preview.preview;
			const newPreview = new ImagePreview(preview, this)
			if (image) newPreview.setValue(image);
			return newPreview;
		});
	}

	setupDroplet() {
		return new DropletArray(this, {
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
		this.preview.map(preview => preview.clearValue());
	}

    setValue(image) {
		console.warn("GalleryField.setValue() is not implemented", image);
    }

	fileUploaded(file, response) {
		const image = response.data[this.property];
		this.preview = this.setupPreview(image);
	}

	schema() {
        return {
            type     : "gallery",
            fieldset : this.type
        };
    }
}
