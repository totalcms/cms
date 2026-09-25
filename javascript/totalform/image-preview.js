import Dialog from "./dialog";
import Details from "./details";
import TotalSortable from "./total-sortable";
import tcmsConfirm from "../confirm-dialog";
import { t } from "../i18n";
import { apiErrorMessage } from "../api-error";

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
			this.openEditDialog();
		});
		image.addEventListener("click", event => {
			event.preventDefault();
			this.openEditDialog();
		});
		links.addEventListener("click", event => {
			event.preventDefault();
			this.linkDialog.open();
		});
		this.setupDelete();
		this.setupClearCache();
		// Action-bar buttons are mouse targets, not fields: keep a click from
		// moving focus into the image field, which in help-on-focus mode would
		// show the field's help label as if the person had tabbed into it.
		// mousedown is the moment focus is taken; preventing its default keeps
		// the click and skips the focus. Keyboard users still Tab to the
		// buttons, and for them the help is right to appear.
		this.container.querySelector(".actionbar")?.addEventListener("mousedown", event => event.preventDefault());
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
		// The star's PATCH has already persisted the new value. Reflect it in
		// the dialog's checkbox as SAVED — setValue() would mark the checkbox
		// unsaved and its subfield-change would mark this whole image field
		// unsaved (image.js turns subfield-change into changed()), leaving the
		// form dirty over a change that is already on disk.
		this.featuredField.totalfield.setSavedValue(!this.isFeatured());
		this.totalfield.saved();
	  	setTimeout(() => this.toggleFeaturedActionButton(), 0);
	}

	setupDownload() {
		const downloadButton = this.container.querySelector(".actionbar .download");
		if (downloadButton) {
			downloadButton.addEventListener("click", event => {
				event.preventDefault();
				const mimeType = this.container.querySelector('.form-field:has([name=mime])').totalfield.getValue();
				const format = mimeType.split("/")[1];
				// `.format` is appended to the last path segment (subpath if nested,
				// property otherwise) — `buildPropertyApi`'s suffix parameter handles
				// both shapes uniformly.
				const downloadUrl = this.api.buildPublicQuery(this.totalfield.buildPropertyApi('/imageworks', `.${format}`));

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
				// The PATCH meta action dispatches on filesystem state.
				const featureApi = this.totalfield.buildPropertyApi('/collections');
				const newData = { featured: !this.isFeatured() };
				this.form.api.postAPI(featureApi, newData, "patch").then(response => {
					this.toggleFeaturedField();
				}).catch(error => {
					console.error("Failed to update featured status", error);
					alert(apiErrorMessage(error, "error.featured_update"));
				});
			});
		}
	}

	setupClearCache() {
		const clearButton = this.container.querySelector(".actionbar .clear");
		if (clearButton) {
			clearButton.addEventListener("click", event => {
				event.preventDefault();
				// Top-level: /collections/{coll}/{id}/{prop}/cache.
				// Card-nested: /collections/{coll}/{id}/{cardprop}/{childkey}/cache.
				// The `{path:.+}/cache` route's action dispatches on filesystem
				// state to handle both gallery file caches and nested property caches.
				const clearApi = this.totalfield.buildPropertyApi('/collections', '/cache');
				this.form.api.postAPI(clearApi, "", "DELETE").then(response => {
					this.container.classList.toggle("cleared-cache");
				}).catch(error => {
					console.error("Failed to clear image cache", error);
					alert(apiErrorMessage(error, "error.cache_clear"));
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
				const deleteApi = this.totalfield.buildPropertyApi('/collections');
				this.form.api.postAPI(deleteApi, "", "DELETE").then(response => {
					this.clearValue();
					this.container.remove();
				}).catch(error => {
					console.error("Failed to delete image", error);
					alert(apiErrorMessage(error, "error.delete_image"));
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

	/**
	 * Open the edit dialog with a snapshot of every dialog field, so Cancel
	 * has something to put back. Taken per open, not once: the dialog can be
	 * opened, edited, closed (which autosaves) and opened again.
	 */
	openEditDialog() {
		this.dialogSnapshot = new Map(Array.from(this.fields).map(field => [field, field.totalfield.getValue()]));
		this.editDialog.open();
	}

	/**
	 * Cancel: restore the snapshot as SAVED values — setSavedValue() moves the
	 * value and its baseline without marking anything unsaved — so the
	 * close-time autosave finds nothing to send, and a later form save has
	 * nothing of the abandoned edit to sweep up. Restoring values does not
	 * undo a reorder of the palette swatches; that is a drag, not a value.
	 */
	restoreDialogSnapshot() {
		if (!this.dialogSnapshot) return;
		for (const [field, value] of this.dialogSnapshot) {
			field.totalfield.setSavedValue(value);
		}
		// The focal-point marker follows the fields through watch(), which
		// the silent path deliberately skips — sync it from the restored values.
		const focalPoint = this.editDialog.dialog.querySelector('.focal-point');
		const x = this.editDialog.dialog.querySelector('.form-field:has([name=focalpoint-x])');
		const y = this.editDialog.dialog.querySelector('.form-field:has([name=focalpoint-y])');
		if (focalPoint && x && y) {
			focalPoint.style.left = `${x.totalfield.getValue()}%`;
			focalPoint.style.top  = `${y.totalfield.getValue()}%`;
		}
		this.totalfield.saved();
	}

	setupEditDialog() {
		// process the form fields added in the edit dialog
		this.form.processFields();
		const dialogEl = this.container.querySelector(".image-edit-dialog");
		// Discard Changes and Escape put the fields back to their opening
		// snapshot before the close-time autosave runs; Save and a backdrop
		// click just close, which keeps them.
		dialogEl.querySelector(".cancel")?.addEventListener("click", event => {
			event.preventDefault();
			this.restoreDialogSnapshot();
			this.editDialog.close();
		});
		return new Dialog(dialogEl, {
			open  : null,
			close : ".close",
			onDismiss : () => this.restoreDialogSnapshot(),
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
		// Called after the file is gone from disk (the delete request has
		// answered): clear as SAVED values. clearValue() on each sub-field
		// would dispatch subfield-change and mark this field — and the row,
		// item or card it sits in — unsaved over a change already persisted.
		for (const field of this.fields) {
			field.totalfield.setSavedValue("");
		}
		this.totalfield.saved();
	}

    setValue(image) {
		// from tests this.fields updates as colors are dragged in the palette
		for (const field of this.fields) {
			const key = field.totalfield.property;
			if (key.startsWith("exif-")) {
				const exifKey = key.replace("exif-","");
				field.totalfield.setValue(image.exif?.[exifKey] ?? "");

			} else if (key.startsWith("focalpoint-")) {
				const focalpointKey = key.replace("focalpoint-","");
				field.totalfield.setValue(image.focalpoint[focalpointKey]||0);

			} else if (key.startsWith("palette-")) {
				const paletteIndex = parseInt(key.replace("palette-",""));
				field.totalfield.setValue(image.palette[paletteIndex]);

			} else {
				field.totalfield.setValue(image[key] ?? ""); // `??` keeps a 0
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
