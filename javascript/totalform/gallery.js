import ImageField from "./image";
import GalleryPreview from "./gallery-preview";
import Dialog from "./dialog";
import Details from "./details";
import TotalSortable from "./total-sortable";
import DropletArray from "./droplet-array";
import Scrollable from "./scrollable";

//-----------------------------------------------
// Total CMS Gallery Field
//-----------------------------------------------
export default class GalleryField extends ImageField {

    constructor(container, settings) {
        super(container, settings);

		this.imageDataStore = this.loadImageData();
		this.sharedDialog = null;
		this.sharedLinkDialog = null;
		this.activePreview = null;

		this.scrollable = new Scrollable(this.previewContainer);
		this.sortable = null;
		this.watchPreviews();
    }

	loadImageData() {
		const store = new Map();
		const scriptEl = this.container.querySelector(`script[id^="gallery-data-"]`);
		if (scriptEl) {
			try {
				const data = JSON.parse(scriptEl.textContent);
				if (Array.isArray(data)) {
					data.forEach(image => {
						if (image.name) {
							store.set(image.name, image);
						}
					});
				}
			} catch (e) {
				console.error("Failed to parse gallery data JSON", e);
			}
		}
		return store;
	}

	watchPreviews() {
		const observer = new MutationObserver((mutationsList, observer) => {
			if (this.previewContainer.classList.contains('sorting')) {
				return;
			}

			for (const mutation of mutationsList) {
				if (mutation.type === 'childList') {
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
				const preview = new GalleryPreview(imagePreview, this);
				previews.push(preview);
			}
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

	// --- Shared Dialog Management ---

	getSharedDialog() {
		if (this.sharedDialog) return this.sharedDialog;

		const template = this.container.querySelector('template');
		if (!template) return null;

		const clone = template.content.cloneNode(true);
		const wrapper = clone.querySelector('.image-preview');
		this.container.appendChild(wrapper);
		wrapper.classList.add('shared-edit-container');

		// Process only the fields inside the cloned dialog (not the whole form,
		// which would re-discover this gallery field and cause an infinite loop)
		const dialogFields = Array.from(wrapper.getElementsByClassName("form-field"));
		dialogFields.forEach(field => {
			if (!field.totalfield && !field.closest('template')) {
				this.form.generateFieldObject(field);
			}
		});

		this.sharedDialogFields = wrapper.getElementsByClassName("form-field");

		const dialogEl = wrapper.querySelector(".image-edit-dialog");
		this.sharedDialog = new Dialog(dialogEl, {
			open  : null,
			close : ".close",
			onOpen : () => {
				if (!this.sharedDialogSetup) {
					this.sharedDialogSetup = true;
					this.setupSharedEditAccordion(dialogEl);
					this.setupSharedFocalPoint(dialogEl);
					this.setupSharedSortablePalette(dialogEl);
				}
			},
			onClose : () => {
				if (this.activePreview) {
					// Read data back from dialog fields into the data store
					const updatedData = this.readSharedDialog();
					const name = this.activePreview.getImageName();
					this.imageDataStore.set(name, updatedData);
					// Update featured CSS class on the preview
					this.activePreview.container.classList.toggle('featured', !!updatedData.featured);
				}
				this.autosave();
			}
		});

		// Also set up the shared link dialog
		const linkDialogEl = wrapper.querySelector(".image-link-dialog");
		if (linkDialogEl) {
			this.sharedLinkDialog = new Dialog(linkDialogEl, {
				open  : null,
				close : ".close",
			});
		}

		return this.sharedDialog;
	}

	setupSharedEditAccordion(dialogEl) {
		const details = Array.from(dialogEl.querySelectorAll("details"));
		new Details(details);
	}

	setupSharedFocalPoint(dialogEl) {
		const focalPoint  = dialogEl.querySelector('.focal-point');
		const focalPointX = dialogEl.querySelector('.form-field:has([name=focalpoint-x])');
		const focalPointY = dialogEl.querySelector('.form-field:has([name=focalpoint-y])');
		if (!focalPoint || !focalPointX || !focalPointY) return;

		const focalPointCoords = (event) => {
			const clientX = event.touches ? event.touches[0].clientX : event.clientX;
			const clientY = event.touches ? event.touches[0].clientY : event.clientY;
			const rect = focalPoint.parentNode.getBoundingClientRect();
			let x = (clientX - rect.left) / rect.width * 100;
			let y = (clientY - rect.top) / rect.height * 100;
			if (x < 0) x = 0;
			if (x > 100) x = 100;
			if (y < 0) y = 0;
			if (y > 100) y = 100;
			return { x, y };
		};
		focalPointX.totalfield.watch(() => {
			focalPoint.style.left = `${focalPointX.totalfield.getValue()}%`;
		});
		focalPointY.totalfield.watch(() => {
			focalPoint.style.top = `${focalPointY.totalfield.getValue()}%`;
		});
		let dragging = false;

		const startDragging = () => { dragging = true };
		const stopDragging  = () => { if (dragging) dragging = false };
		const moveFocalPoint = (event) => {
			if (dragging) {
				event.preventDefault();
				const { x, y } = focalPointCoords(event);
				focalPoint.style.left = `${x}%`;
				focalPoint.style.top = `${y}%`;
				focalPointX.totalfield.setValue(x.toFixed(1));
				focalPointY.totalfield.setValue(y.toFixed(1));
			}
		};

		document.addEventListener('mousemove', moveFocalPoint);
		document.addEventListener('touchmove', moveFocalPoint, { passive: false });
		focalPoint.addEventListener('mousedown', startDragging);
		focalPoint.addEventListener('touchstart', startDragging, { passive: true });
		focalPoint.addEventListener('mouseup', stopDragging);
		focalPoint.addEventListener('touchend', stopDragging, { passive: true });
	}

	setupSharedSortablePalette(dialogEl) {
		const palette = dialogEl.querySelector(".palette");
		if (palette) new TotalSortable(palette);
	}

	populateSharedDialog(imageData) {
		for (const field of this.sharedDialogFields) {
			const key = field.totalfield.property;
			if (key.startsWith("exif-")) {
				const exifKey = key.replace("exif-","");
				field.totalfield.setValue((imageData.exif && imageData.exif[exifKey]) || "");
			} else if (key.startsWith("focalpoint-")) {
				const fpKey = key.replace("focalpoint-","");
				field.totalfield.setValue((imageData.focalpoint && imageData.focalpoint[fpKey]) || 0);
			} else if (key.startsWith("palette-")) {
				const paletteIndex = parseInt(key.replace("palette-",""));
				field.totalfield.setValue(imageData.palette ? imageData.palette[paletteIndex] : "");
			} else {
				field.totalfield.setValue(imageData[key] || "");
			}
			field.totalfield.saved();
		}

		// Update the preview image in the dialog
		const dialogImg = this.sharedDialog.dialog.querySelector("section.image-preview img");
		const previewImg = this.activePreview.container.querySelector(".dz-preview img");
		if (dialogImg && previewImg) {
			dialogImg.src = previewImg.src;
		}

		// Update focal point position
		const focalPoint = this.sharedDialog.dialog.querySelector('.focal-point');
		if (focalPoint && imageData.focalpoint) {
			focalPoint.style.left = `${imageData.focalpoint.x || 50}%`;
			focalPoint.style.top = `${imageData.focalpoint.y || 50}%`;
		}
	}

	readSharedDialog() {
		const imageData = {};
		for (const field of this.sharedDialogFields) {
			let key = field.totalfield.property;
			const value = field.totalfield.getValue();

			if (key.startsWith("exif-")) {
				if (!value) continue;
				key = key.replace("exif-","");
				if (!imageData["exif"]) imageData["exif"] = {};
				imageData["exif"][key] = value;
			} else if (key.startsWith("focalpoint-")) {
				key = key.replace("focalpoint-","");
				if (!imageData["focalpoint"]) imageData["focalpoint"] = {};
				imageData["focalpoint"][key] = value;
			} else if (key.startsWith("palette-")) {
				if (!imageData["palette"]) imageData["palette"] = [];
				imageData["palette"].push(value.hex);
			} else {
				imageData[key] = value;
			}
		}
		if (!imageData["exif"]) {
			imageData["exif"] = { "nodata": "" };
		}
		return imageData;
	}

	openEditDialog(preview) {
		const dialog = this.getSharedDialog();
		if (!dialog) return;

		this.activePreview = preview;
		const name = preview.getImageName();
		const imageData = this.imageDataStore.get(name) || {};
		this.populateSharedDialog(imageData);
		dialog.open();
	}

	openLinkDialog(preview) {
		const dialog = this.getSharedDialog();
		if (!dialog || !this.sharedLinkDialog) return;

		this.activePreview = preview;
		const name = preview.getImageName();
		const iframe = this.sharedLinkDialog.dialog.querySelector("iframe");
		if (iframe) {
			// Update the iframe src with the current image name
			const baseSrc = iframe.dataset.src || iframe.src;
			if (baseSrc) {
				// Replace the name parameter in the query string
				const url = new URL(baseSrc, window.location.origin);
				url.searchParams.set('name', name);
				iframe.src = url.pathname + url.search;
			}
		}
		this.sharedLinkDialog.open();
	}

	closeSharedDialog() {
		if (this.sharedDialog) {
			this.sharedDialog.close();
		}
		this.activePreview = null;
	}

	// --- Standard Gallery Methods ---

	setupReorder() {
		if (this.sortable) {
			return;
		}

		this.sortable = new TotalSortable(this.previewContainer, {
			animation : 500,
			handle    : ".move",
			draggable : ".image-preview",
			onEnd     : () => {
				this.preview = Array.from(this.previewContainer.children)
					.filter(el => el.preview)
					.map(el => el.preview);

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
			rules            : this.settings.rules,
		});
		this.droplet.onQueueComplete(() => this.uploadComplete());
	}

    getValue() {
		if (!this.form.isEditMode()) {
			return [];
		}
		// Build array from DOM order using data store lookups
		return Array.from(this.previewContainer.children)
			.filter(el => el.preview)
			.map(el => {
				const name = el.dataset.imageName;
				return name ? (this.imageDataStore.get(name) || {}) : el.preview.getValue();
			});
    }

	clearValue() {
		this.imageDataStore.clear();
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
		if (image && image.name) {
			// Add to data store
			this.imageDataStore.set(image.name, image);
			// Set data-image-name on the new preview element
			const newPreview = Array.from(this.previewContainer.children).find(el => {
				const img = el.querySelector('img');
				return img && img.alt === image.name && !el.dataset.imageName;
			});
			if (newPreview) {
				newPreview.dataset.imageName = image.name;
			}
		}
		this.setupPreview(image);
	}

	validate() {
		this.input.setCustomValidity("");

		if (!this.isVisible()) return true;

		if (this.input.required) {
			if (!this.preview || !Array.isArray(this.preview) || this.preview.length === 0) {
				const message = `${this.label} is required - please upload at least one image`;
				this.input.setCustomValidity(message);
				this.input.reportValidity();
				this.error(message);
				return false;
			}

			if (!this.hasImage()) {
				const message = `${this.label} is required - please upload at least one image`;
				this.input.setCustomValidity(message);
				this.input.reportValidity();
				this.error(message);
				return false;
			}
			this.input.value = this.property;
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
