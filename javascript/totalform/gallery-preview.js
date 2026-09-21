import tcmsConfirm from "../confirm-dialog";
import { t } from "../i18n";

//-----------------------------------------------
// Gallery Preview — lightweight thumbnail preview
// Delegates dialogs to parent GalleryField's shared dialog
//-----------------------------------------------
export default class GalleryPreview {

	constructor(container, gallery) {
		if (container.preview) return container.preview;

		this.container = container;
		this.container.preview = this;

		this.gallery  = gallery;
		this.api      = gallery.api;
		this.form     = gallery.form;
		this.property = gallery.property;
		this.type     = gallery.type;

		this.fields = [];

		this.setupActionBar();
	}

	getImageName() {
		return this.container.dataset.imageName || '';
	}

	setupActionBar() {
		const edit  = this.container.querySelector(".actionbar .edit");
		const links = this.container.querySelector(".actionbar .links");
		const image = this.container.querySelector(".dz-preview img");

		edit.addEventListener("click", event => {
			event.preventDefault();
			this.gallery.openEditDialog(this);
		});
		image.addEventListener("click", event => {
			event.preventDefault();
			this.gallery.openEditDialog(this);
		});
		links.addEventListener("click", event => {
			event.preventDefault();
			this.gallery.openLinkDialog(this);
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
	}

	isFeatured() {
		const data = this.gallery.imageDataStore.get(this.getImageName());
		return data ? !!data.featured : false;
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

	setFeatured(featured) {
		const name = this.getImageName();
		const data = this.gallery.imageDataStore.get(name);
		if (data) {
			data.featured = featured;
			this.gallery.imageDataStore.set(name, data);
		}

		// If the shared dialog is currently showing this image, update the checkbox
		if (this.gallery.activePreview === this && this.gallery.sharedDialogFields) {
			for (const field of this.gallery.sharedDialogFields) {
				if (field.totalfield.property === 'featured') {
					// Already persisted by the star's PATCH — reflect it as saved,
					// so closing the dialog does not commit it as an edit.
					field.totalfield.setSavedValue(featured);
					break;
				}
			}
		}

		this.toggleFeaturedActionButton();
	}

	setupDownload() {
		const downloadButton = this.container.querySelector(".actionbar .download");
		if (downloadButton) {
			downloadButton.addEventListener("click", event => {
				event.preventDefault();
				const name = this.getImageName();
				const data = this.gallery.imageDataStore.get(name);
				const mimeType = data ? data.mime : 'image/jpeg';
				const format = mimeType.split("/")[1];
				const downloadApi = `/imageworks/${this.form.collection}/${this.form.id}/${this.property}/${name}`;
				const downloadUrl = this.api.buildPublicQuery(downloadApi);

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
				const newFeatured = !this.isFeatured();
				this.tempToggleFeaturedActionButton();
				const name = this.getImageName();
				const featureApi = `/collections/${this.form.collection}/${this.form.id}/${this.property}/${name}`;
				this.form.api.postAPI(featureApi, { featured: newFeatured }, "patch").then(response => {
					this.setFeatured(newFeatured);
				}).catch(error => {
					this.tempToggleFeaturedActionButton();
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
				const name = this.getImageName();
				const clearApi = `/collections/${this.form.collection}/${this.form.id}/${this.property}/${name}/cache`;
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
				const name = this.getImageName();
				const deleteApi = `/collections/${this.form.collection}/${this.form.id}/${this.property}/${name}`;
				this.form.api.postAPI(deleteApi, "", "DELETE").then(response => {
					this.gallery.imageDataStore.delete(name);
					if (this.gallery.activePreview === this) {
						this.gallery.closeSharedDialog();
					}
					this.container.remove();
				}).catch(error => {
					console.error("Failed to delete image", error);
					alert(t("error.delete_image"));
				});
			});
		}
	}

	getValue() {
		return this.gallery.imageDataStore.get(this.getImageName()) || {};
	}

	setValue(image) {
		const name = image.name || this.getImageName();
		this.gallery.imageDataStore.set(name, image);
		this.container.classList.toggle('featured', !!image.featured);
	}

	clearValue() {
		this.gallery.imageDataStore.delete(this.getImageName());
	}
}
