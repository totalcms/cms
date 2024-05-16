import TotalField from "./totalfield";
import Dialog from "./dialog";
import Droplet from "./droplet";
import Details from "./details";
import Sortable from 'sortablejs';

//-----------------------------------------------
// Total CMS Droplet
//-----------------------------------------------
export default class ImageField extends TotalField {

    constructor(container, options) {
        super(container, options);

		this.fields        = this.container.getElementsByClassName("form-field");
		this.featuredField = this.container.querySelector(".form-field:has([name=featured])");

		this.droplet    = this.setupDroplet();
		this.editDialog = this.setupEditDialog();
		this.linkDialog = this.setupLinkDialog();

		this.setupActionBar();
    }

	apiUploadImage() {
		const api = `/collections/${this.form.collection}/${this.form.id}/${this.property}`;
		return this.api.buildApiQuery(api);
    }

	updateAPIUrl() {
		this.droplet.updateUrl(this.apiUploadImage());
	}

	setupActionBar() {
		const edit = this.container.querySelector(".actionbar .edit");
		const links = this.container.querySelector(".actionbar .links");
		const image = this.container.querySelector(".total-preview img");
		edit.addEventListener("click", event => {
			event.preventDefault();
			this.editDialog.open();
		});
		image.addEventListener("click", event => {
			event.preventDefault();
			this.editDialog.open();
		});
		links.addEventListener("click", event => {
			event.preventDefault();
			this.linkDialog.open();
		});
		this.setupDelete();
		this.setupClearCache();
		this.setupFeaturedToggle();
		this.setupDownload();

		// Keep the featured field in sync with the featured class for the action bar
		if (!this.featuredListener) {
			this.featuredListener = this.featuredField.addEventListener("field-change", e => this.toggleFeaturedActionButton());
		}
	}

	isFeatured() {
		// This is not 100% correct. A user could change the featured value in the edit dialog
		// and then not save. This could cause this flag to be wrong.
		return this.featuredField.totalfield.getValue();
	}

	toggleFeaturedActionButton() {
		if (this.isFeatured()) {
			this.container.querySelector(".total-preview").classList.add("featured");
		} else {
			this.container.querySelector(".total-preview").classList.remove("featured");
		}
	}

	toggleFeaturedField() {
		this.featuredField.totalfield.setValue(!this.isFeatured());
	}

	setupDownload() {
		const downloadButton = this.container.querySelector(".actionbar .download");
		if (downloadButton) {
			downloadButton.addEventListener("click", event => {
				event.preventDefault();
				const mimeType = this.container.querySelector('.form-field:has([name=mime])').totalfield.getValue();
				const format = mimeType.split("/")[1];
				const downloadApi = `/imageworks/${this.form.collection}/${this.form.id}/${this.property}.${format}`;
				const downloadUrl = this.api.buildApiQuery(downloadApi);

				const link = document.createElement('a');
				link.href = downloadUrl;
				link.download = `${this.form.id}-${this.property}-original.${format}`; // Suggest a filename for the downloaded file
				document.body.appendChild(link); // Append the anchor element to the body
				link.click(); // Programmatically click the anchor element
				document.body.removeChild(link); // Remove the anchor element from the body
			});
		}
	}

	setupFeaturedToggle() {
		const featureButton = this.container.querySelector(".actionbar .featured");
		if (featureButton) {
			featureButton.addEventListener("click", event => {
				event.preventDefault();
				const featureApi = `/collections/${this.form.collection}/${this.form.id}/${this.property}`;
				const newData = { featured: !this.isFeatured() };
				this.form.api.postAPI(featureApi, newData, "patch").then(response => {
					this.toggleFeaturedField();
				});
			});
		}
	}

	setupClearCache() {
		const clearButton = this.container.querySelector(".actionbar .clear");
		if (clearButton) {
			clearButton.addEventListener("click", event => {
				event.preventDefault();
				const clearApi = `/collections/${this.form.collection}/${this.form.id}/${this.property}/cache`;
				this.form.api.postAPI(clearApi, "", "DELETE").then(response => {
					this.container.querySelector(".total-preview").classList.toggle("cleared-cache");
				});
			});
		}
	}

	setupDelete() {
		const deleteButton = this.container.querySelector(".actionbar .trash");
		if (deleteButton) {
			deleteButton.addEventListener("click", event => {
				event.preventDefault();
				if (confirm("Are you sure that you want to delete this image?")) {
					// Delete the entire image object if it's an image schema
					const deleteApi = this.form.schema === "image" ?
						`/collections/${this.form.collection}/${this.form.id}` :
						`/collections/${this.form.collection}/${this.form.id}/${this.property}`;
					this.form.api.postAPI(deleteApi, "", "DELETE").then(response => {
						deleteButton.closest(".dz-preview").remove();
						this.clearValue();
					});
				}
			});
		}
	}

	setupDroplet() {
		return new Droplet(this, {
			paramName        : this.property,
			apiUrl           : this.apiUploadImage(),
			autoProcessQueue : this.form.isEditMode(),
			acceptedFiles    : "image/*",
			rules            : this.options.rules,
		});
	}

	setupLinkDialog() {
		return new Dialog(this.container.querySelector(".image-link-dialog"), {
			open  : null,
			close : ".close",
			onOpen : () => {
				const iframe = this.linkDialog.dialog.querySelector("iframe");
				if (!iframe.src) {
					iframe.src = iframe.dataset.src;
				}
			},
		});
	}

	setupEditDialog() {
		return new Dialog(this.container.querySelector(".image-edit-dialog"), {
			open  : null,
			close : ".close",
			onOpen : () => {
				if (this.dialogOpened) return;
				this.dialogOpened = true;
				this.setupEditAccordion();
				this.setupFocalPoint();
				this.sortablePalette();
			},
		});
	}

	setupEditAccordion() {
		// Close other details when one is opened
		const details = Array.from(this.editDialog.dialog.querySelectorAll("details"));
		this.editAccordion = new Details(details);
	}

	sortablePalette() {
		// Make the color palette sortable
		const palette = this.editDialog.dialog.querySelector(".palette");
		Sortable.create(palette, {
			animation  : 150,
			ghostClass : 'drag-ghost',
		});
	}

	setupFocalPoint() {
		const focalPoint  = this.editDialog.dialog.querySelector('.focal-point');
		const focalPointX = this.editDialog.dialog.querySelector('.form-field:has([name=focalpoint-x])');
		const focalPointY = this.editDialog.dialog.querySelector('.form-field:has([name=focalpoint-y])');
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

		// this.documentListeners = {
		// 	"mousemove" : document.addEventListener('mousemove', moveFocalPoint),
		// 	"touchmove" : document.addEventListener('touchmove', moveFocalPoint, { passive: false }),
		// };

		document.addEventListener('mousemove', moveFocalPoint);
		document.addEventListener('touchmove', moveFocalPoint, { passive: false });

		focalPoint.addEventListener('mousedown', startDragging);
		focalPoint.addEventListener('touchstart', startDragging, { passive: true });

		focalPoint.addEventListener('mouseup', stopDragging);
		focalPoint.addEventListener('touchend', stopDragging, { passive: true });
	}

	// getPaletteColors() {
	// 	// Get the colors in the palette in the order they are in the DOM
	// 	const palette = this.editDialog.dialog.querySelector(".palette");
	// 	const colors = [];
	// 	for (const color of palette.children) {
	// 		colors.push(color.totalfield.getValue());
	// 	}
	// 	return colors;
	// }

    getValue() {
		const imageData = {};
		for (const field of this.fields) {
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
				imageData["palette"].push(value.hex); // only store the hex value

			} else {
				imageData[key] = value;
			}
		}
        return imageData;
    }

	clearValue() {
		for (const field of this.fields) {
			field.totalfield.clearValue();
		}
	}

    setValue(image) {
		// from tests this.fields updates as colors are dragged in the palette
		for (const field of this.fields) {
			const key = field.totalfield.property;
			if (key.startsWith("exif-")) {
				const exifKey = key.replace("exif-","");
				field.totalfield.setValue(image.exif[exifKey]||"");

			} else if (key.startsWith("focalpoint-")) {
				const focalpointKey = key.replace("focalpoint-","");
				field.totalfield.setValue(image.focalpoint[focalpointKey]||0);

			} else if (key.startsWith("palette-")) {
				const paletteIndex = parseInt(key.replace("palette-",""));
				field.totalfield.setValue(image.palette[paletteIndex]);

			} else {
				field.totalfield.setValue(image[key]||"");
			}
			// setting to saved state since this data comes from the server
			field.totalfield.saved();
		}
		this.saved();
    }

	updatePreviewImage() {
		const newImage = this.container.querySelector(".dz-preview img");
		const previewImage = this.editDialog.dialog.querySelector("img");
		previewImage.src = newImage.src;
	}

	fileUploaded(file, response) {
		const image = response.data[this.property];
		this.setupActionBar();
		this.setValue(image);
		this.updatePreviewImage();
	}

	schema() {
        return {
            type     : "image",
            fieldset : this.type
        };
    }
}
