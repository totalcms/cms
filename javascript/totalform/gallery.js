import ImageField from "./image";

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
