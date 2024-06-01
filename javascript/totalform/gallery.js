import ImageField from "./image";
import ImagePreview from "./image-preview";
import DropletArray from "./droplet-array";

//-----------------------------------------------
// Total CMS Gallery Field
//-----------------------------------------------
export default class GalleryField extends ImageField {

    constructor(container, options) {
        super(container, options);

		this.makeScrollable();
    }

	makeScrollable() {
		const style     = getComputedStyle(this.previewContainer);
		const maxHeight = parseInt(style.getPropertyValue('--scroll-height'));
		if (!maxHeight) return;

		if (this.previewContainer.scrollHeight > maxHeight) {
			return this.previewContainer.classList.add('scrollable');
		}
		this.previewContainer.classList.remove('scrollable');
	}

	isScrollable() {
		return this.previewContainer.classList.contains('scrollable');
	}

	scrollToBottom() {
		if (!this.isScrollable()) return;
		this.previewContainer.scrollTo({
			top      : this.previewContainer.scrollHeight,
			behavior : 'smooth'
		});
	}

	setupPreview(image) {
		const previews      = [];
		const imagePreviews = Array.from(this.previewContainer.children);
		imagePreviews.forEach(imagePreview => {
			if (imagePreview.preview) return previews.push(imagePreview.preview);
			const preview = new ImagePreview(imagePreview, this)
			if (image) preview.setValue(image);
			previews.push(preview);
		});
		return previews;
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
		return this.preview.map(preview => preview.getValue());
    }

	clearValue() {
		this.preview.map(preview => preview.clearValue());
	}

    setValue(image) {
		console.warn("GalleryField.setValue() is not implemented", image);
    }

	fileAdded(file) {
		this.makeScrollable();
		this.scrollToBottom();
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
