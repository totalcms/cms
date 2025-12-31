import ImageField from "./image";
import ImagePreview from "./image-preview";
import DropletArray from "./droplet-array";
import TotalSortable from "./total-sortable";
import Scrollable from "./scrollable";

//-----------------------------------------------
// Total CMS Gallery Field
//-----------------------------------------------
export default class GalleryField extends ImageField {

    constructor(container, options) {
        super(container, options);

		this.scrollable = new Scrollable(this.previewContainer);
		this.sortable = null; // Store sortable instance
		this.watchPreviews();
    }

	watchPreviews() {
		// Watch for changes in previews
		const observer = new MutationObserver((mutationsList, observer) => {
			// Skip if currently sorting - SortableJS moves elements which triggers mutations
			if (this.previewContainer.classList.contains('sorting')) {
				return;
			}

			for (const mutation of mutationsList) {
				if (mutation.type === 'childList') {
					// Only setup if nodes were actually added or removed (not just moved by Sortable)
					const hasAddedNodes = mutation.addedNodes.length > 0;
					const hasRemovedNodes = mutation.removedNodes.length > 0;

					if (hasAddedNodes || hasRemovedNodes) {
						this.setupPreview();
					}
				}
			}
		});
		observer.observe(this.previewContainer, { childList: true });
	}

	setupPreview(image) {
		const previews      = [];
		const imagePreviews = Array.from(this.previewContainer.children);
		imagePreviews.forEach(imagePreview => {
			if (imagePreview.preview) {
				previews.push(imagePreview.preview);
			} else {
				const preview = new ImagePreview(imagePreview, this)
				previews.push(preview);
			}

			Array.from(imagePreview.preview.fields).forEach(field => {
				field.addEventListener("subfield-change", () => this.changed());
			});
		});
		if (image) {
			imagePreviews.forEach(imagePreview => {
				const img = imagePreview.querySelector('img');
				if (image.name === img.alt) {
					imagePreview.preview.setValue(image);
				}
			});
		}
		this.preview = previews;
		this.setupReorder();
	}

	setupReorder() {
		// Only create Sortable once
		if (this.sortable) {
			return;
		}

		this.sortable = new TotalSortable(this.previewContainer, {
			animation : 500,
			handle    : ".move",
			draggable : ".image-preview",
			onEnd     : () => {
				// Rebuild preview array from DOM order (SortableJS has already reordered the DOM)
				this.preview = Array.from(this.previewContainer.children)
					.filter(el => el.preview)
					.map(el => el.preview);

				// Update the order of the images in the CMS
				this.autosave(true);
			}
		});
	}

	setupDroplet() {
		this.droplet = new DropletArray(this, {
			paramName        : this.property,
			apiUrl           : this.apiUploadImage(),
			autoProcessQueue : this.form.isEditMode(),
			acceptedFiles    : "image/*",
			rules            : this.options.rules,
		});
		this.droplet.onQueueComplete(() => this.uploadComplete());
	}

    getValue() {
		if (!this.form.isEditMode()) {
			// When saving a new object, save an empty gallery
			// After the initial object saves, the image uploads
			// will add the image data to the gallery
			return [];
		}
		return this.preview.map(preview => preview.getValue());
    }

	clearValue() {
		this.preview.map(preview => preview.clearValue());
	}

    setValue(image) {
		console.warn("GalleryField.setValue() is not implemented", image);
    }

	fileAdded(file) {
		this.scrollable.scrollToBottom();
	}

	fileUploaded(file, response) {
		const images = response.data[this.property];
		const image = images.filter(image => image.name === file.name).shift();
		this.setupPreview(image);
	}

	validate() {
		// Clear any previous custom validity and call parent validation
		this.input.setCustomValidity("");

		if (!this.isVisible()) return true;

		// Check if field is required
		if (this.input.required) {
			// Check if gallery has images by checking each preview
			if (!this.preview || !Array.isArray(this.preview) || this.preview.length === 0) {
				const message = `${this.label} is required - please upload at least one image`;
				this.input.setCustomValidity(message);
				this.input.reportValidity();
				this.error(message);
				return false;
			}

			// Check if all images in the gallery are valid
			if (!this.hasImage()) {
				const message = `${this.label} is required - please upload at least one image`;
				this.input.setCustomValidity(message);
				this.input.reportValidity();
				this.error(message);
				return false;
			}
			this.input.value = this.property; // Set a dummy value to satisfy HTML5 required validation
		}

		return super.validate();
	}

	schema() {
        return {
            type     : "gallery",
            fieldset : this.type
        };
    }
}
