import Dialog from "./dialog";
import Details from "./details";
import TotalSortable from "./total-sortable";
import tcmsConfirm from "../confirm-dialog";
import { t } from "../i18n";

//-----------------------------------------------
// Total CMS Droplet
//-----------------------------------------------
export default class ImagePreview {

    constructor(container, totalfield) {
		if (container.preview) return container.preview;

		this.container  = container;

		this.container.preview = this;

		this.api        = totalfield.api;
		this.form       = totalfield.form;
		this.property   = totalfield.property;
		this.type       = totalfield.type;
		this.totalfield = totalfield;

		this.fields        = this.container.getElementsByClassName("form-field");
		this.featuredField = this.container.querySelector(".form-field:has([name=featured])");

		this.editDialog = this.setupEditDialog();
		this.linkDialog = this.setupLinkDialog();

		this.setupActionBar();
    }

setupActionBar() {
		const edit  = this.container.querySelector(".actionbar .edit");
		const links = this.container.querySelector(".actionbar .links");
		const image = this.container.querySelector(".dz-preview img");
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
			this.featuredListener = this.featuredField.addEventListener("subfield-change", e => this.toggleFeaturedActionButton());
		}
	}

	isFeatured() {
		// This is not 100% correct. A user could change the featured value in the edit dialog
		// and then not save. This could cause this flag to be wrong.
		return this.featuredField.totalfield.getValue();
	}

	tempToggleFeaturedActionButton() {
		this.container.classList.toggle("featured");
	}

	toggleFeaturedActionButton() {
		if (this.isFeatured()) {
			this.container.classList.add("featured");
		} else {
			this.container.classList.remove("featured");
		}
	}

	toggleFeaturedField() {
		this.featuredField.totalfield.setValue(!this.isFeatured());
	  	setTimeout(() => this.toggleFeaturedActionButton(), 0);
	}

	setupDownload() {
		const downloadButton = this.container.querySelector(".actionbar .download");
		if (downloadButton) {
			downloadButton.addEventListener("click", event => {
				event.preventDefault();
				const mimeType = this.container.querySelector('.form-field:has([name=mime])').totalfield.getValue();
				const format = mimeType.split("/")[1];
				// For card-nested images, build /imageworks/{coll}/{id}/{cardprop}/{childkey}.{format}.
				// For top-level, /imageworks/{coll}/{id}/{prop}.{format}. Both shapes are dispatched
				// by ImageWorksGalleryFetchAction (which now also handles card data).
				const ctx = this.totalfield.getUploadContext();
				const collection = ctx?.collection ?? this.form.collection;
				const id         = ctx?.id ?? this.form.id ?? '';
				const property   = ctx?.property ?? this.property;
				const downloadApi = ctx?.subpath
					? `/imageworks/${collection}/${id}/${property}/${ctx.subpath}.${format}`
					: `/imageworks/${collection}/${id}/${property}.${format}`;
				const downloadUrl = this.api.buildApiQuery(downloadApi);

				const link = document.createElement('a');
				link.href = downloadUrl;
				link.download = `${this.form.id}-${this.property}-original.${format}`;
				document.body.appendChild(link);
				link.click();
				document.body.removeChild(link);
			});
		}
	}

	setupFeaturedToggle() {
		const featureButton = this.container.querySelector(".actionbar .featured");
		if (featureButton) {
			featureButton.addEventListener("click", event => {
				event.preventDefault();
				this.tempToggleFeaturedActionButton();
				// Top-level: PATCH /coll/id/prop. Card-nested: PATCH /coll/id/cardprop/childkey.
				// The PATCH meta action dispatches on filesystem state and merges into
				// the right JSON location.
				const ctx = this.totalfield.getUploadContext();
				const collection = ctx?.collection ?? this.form.collection;
				const id         = ctx?.id ?? this.form.id ?? '';
				const property   = ctx?.property ?? this.property;
				const featureApi = ctx?.subpath
					? `/collections/${collection}/${id}/${property}/${ctx.subpath}`
					: `/collections/${collection}/${id}/${property}`;
				const newData = { featured: !this.isFeatured() };
				this.form.api.postAPI(featureApi, newData, "patch").then(response => {
					this.toggleFeaturedField();
				}).catch(error => {
					console.error("Failed to update featured status", error);
					alert(t("error.featured_update"));
				});
			});
		}
	}

	setupClearCache() {
		const clearButton = this.container.querySelector(".actionbar .clear");
		if (clearButton) {
			clearButton.addEventListener("click", event => {
				event.preventDefault();
				// Card-nested: /collections/{coll}/{id}/{cardprop}/{childkey}/cache.
				// Top-level:   /collections/{coll}/{id}/{prop}/cache.
				// The `{path:.+}/cache` route's action dispatches on filesystem
				// state to handle both gallery file caches and nested property caches.
				const ctx = this.totalfield.getUploadContext();
				const collection = ctx?.collection ?? this.form.collection;
				const id         = ctx?.id ?? this.form.id ?? '';
				const property   = ctx?.property ?? this.property;
				const clearApi = ctx?.subpath
					? `/collections/${collection}/${id}/${property}/${ctx.subpath}/cache`
					: `/collections/${collection}/${id}/${property}/cache`;
				this.form.api.postAPI(clearApi, "", "DELETE").then(response => {
					this.container.classList.toggle("cleared-cache");
				}).catch(error => {
					console.error("Failed to clear image cache", error);
					alert(t("error.cache_clear"));
				});
			});
		}
	}

	setupDelete() {
		const deleteButton = this.container.querySelector(".actionbar .trash");
		if (deleteButton) {
			deleteButton.addEventListener("click", async event => {
				event.preventDefault();
				const ok = await tcmsConfirm({ message: t("confirm.delete_image"), countdown: 0 });
				if (!ok) return;
				// Top-level: DELETE /coll/id/prop. Card-nested: DELETE /coll/id/cardprop/childkey.
				// FileDeleteAction dispatches on filesystem state; nested clears
				// obj[parent][child] and the disk dir.
				const ctx = this.totalfield.getUploadContext();
				const collection = ctx?.collection ?? this.form.collection;
				const id         = ctx?.id ?? this.form.id ?? '';
				const property   = ctx?.property ?? this.property;
				const deleteApi  = ctx?.subpath
					? `/collections/${collection}/${id}/${property}/${ctx.subpath}`
					: `/collections/${collection}/${id}/${property}`;
				this.form.api.postAPI(deleteApi, "", "DELETE").then(response => {
					this.clearValue();
					this.container.remove();
				}).catch(error => {
					console.error("Failed to delete image", error);
					alert(t("error.delete_image"));
				});
			});
		}
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
		// process the form fields added in the edit dialog
		this.form.processFields();
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
			onClose : () => {
				this.dialogOpened = false;
				this.totalfield.autosave();
			}
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
		new TotalSortable(palette);
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

		document.addEventListener('mousemove', moveFocalPoint);
		document.addEventListener('touchmove', moveFocalPoint, { passive: false });

		focalPoint.addEventListener('mousedown', startDragging);
		focalPoint.addEventListener('touchstart', startDragging, { passive: true });

		focalPoint.addEventListener('mouseup', stopDragging);
		focalPoint.addEventListener('touchend', stopDragging, { passive: true });
	}

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
		if (!imageData["exif"]) {
			// if there was no exif data, add an empty object
			imageData["exif"] = { "nodata": "" };
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
    }

	updatePreviewImage() {
		const newImage = this.container.querySelector(".dz-preview img");
		const previewImage = this.editDialog.dialog.querySelector("img");
		previewImage.src = newImage.src;
	}
}
