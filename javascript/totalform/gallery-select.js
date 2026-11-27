import tcmsConfirm from "../confirm-dialog";
import { t } from "../i18n";
import { apiErrorMessage } from "../api-error";

//-----------------------------------------------
// Gallery multi-delete: a selection mode on the gallery field.
//
// The Select button on the label's row turns it on and off, and so does a
// Cmd-, Ctrl- or Shift-click on a thumbnail, which also selects that image.
// While selecting, a click on a thumbnail selects it instead of opening the
// edit dialog, shift-click selects a range, and Esc leaves. Delete sends every
// selected name in ONE request (…/{property}/bulk/delete), which saves the
// object once: N single deletes fired N `object.updated` events and raced
// each other's read-modify-write of the record.
//
// The selection is a Set of image names, not elements, so it survives the
// thumbnails being rebuilt or reordered. Reordering itself is off while
// selecting because the CSS hides the action bars, and with them the .move
// handle that is the only place a drag can start.
//-----------------------------------------------
export default class GallerySelection {

	constructor(gallery) {
		this.gallery  = gallery;
		this.selected = new Set();
		this.active   = false;
		this.busy     = false;
		this.anchor   = null;

		this.bar    = gallery.container.querySelector(".gallery-select-bar");
		this.button = gallery.container.querySelector(".gallery-select");
		if (!this.bar || !this.button) return;

		this.countEl   = this.bar.querySelector(".gallery-select-count");
		this.allButton = this.bar.querySelector(".gallery-select-all");
		this.delButton = this.bar.querySelector(".gallery-delete-selected");
		this.setup();
	}

	setup() {
		// A click must not focus the button: it sits in the field, so focus would
		// set the field's :focus-within and show its help over the label row.
		this.button.addEventListener("mousedown", event => event.preventDefault());
		this.button.addEventListener("click", event => {
			event.preventDefault();
			if (this.active) this.stop();
			else this.start();
		});

		this.bar.addEventListener("click", event => {
			const button = event.target.closest("button");
			if (!button) return;
			event.preventDefault();
			if (button.classList.contains("gallery-select-all"))           this.toggleAll();
			else if (button.classList.contains("gallery-delete-selected")) this.deleteSelected();
			else if (button.classList.contains("gallery-select-done"))     this.stop();
		});

		// Capture phase, so this runs before the thumbnail's own handlers (the
		// image click opens the edit dialog) and can keep them from running.
		this.gallery.previewContainer.addEventListener("click", event => this.onThumbnailClick(event), true);

		// Esc leaves selection mode, unless it is closing a dialog (the delete
		// confirmation, the edit dialog) that is open on top.
		document.addEventListener("keydown", event => {
			if (event.key !== "Escape" || !this.active) return;
			if (document.querySelector("dialog[open]")) return;
			this.stop();
		});
	}

	// Thumbnails that can be selected: saved images. An upload still running has
	// no name yet, and there is nothing on the server to delete.
	selectable() {
		return Array.from(this.gallery.previewContainer.children)
			.filter(el => el.classList.contains("image-preview") && el.dataset.imageName);
	}

	start() {
		if (!this.gallery.form.isEditMode()) return;
		this.active = true;
		this.anchor = null;
		this.gallery.container.classList.add("gallery-selecting");
		this.button?.setAttribute("aria-pressed", "true");
		this.render();
	}

	stop() {
		this.active = false;
		this.anchor = null;
		this.selected.clear();
		this.gallery.container.classList.remove("gallery-selecting");
		this.button?.setAttribute("aria-pressed", "false");
		this.render();
	}

	onThumbnailClick(event) {
		const preview = event.target.closest(".image-preview");
		if (!preview || preview.parentElement !== this.gallery.previewContainer) return;

		// Outside selection mode a plain click is the thumbnail's own (the image
		// opens the edit dialog, the action bar does its thing). A Cmd-, Ctrl- or
		// Shift-click on the image starts selecting, with that image selected.
		if (!this.active) {
			const modified = event.metaKey || event.ctrlKey || event.shiftKey;
			if (!modified || !preview.dataset.imageName || event.target.closest("button")) return;
			this.start();
			if (!this.active) return;
		}

		event.preventDefault();
		event.stopPropagation();
		if (this.busy) return;

		const name = preview.dataset.imageName;
		if (!name) return;

		if (event.shiftKey && this.anchor) {
			this.selectRange(this.anchor, name);
		} else if (this.selected.has(name)) {
			this.selected.delete(name);
		} else {
			this.selected.add(name);
		}
		this.anchor = name;
		this.render();
	}

	// Select every thumbnail between two names, both included, in page order.
	selectRange(from, to) {
		const names = this.selectable().map(el => el.dataset.imageName);
		const start = names.indexOf(from);
		const end   = names.indexOf(to);
		if (start < 0 || end < 0) {
			this.selected.add(to);
			return;
		}
		const [low, high] = start < end ? [start, end] : [end, start];
		names.slice(low, high + 1).forEach(name => this.selected.add(name));
	}

	toggleAll() {
		if (this.busy) return;
		const names = this.selectable().map(el => el.dataset.imageName);
		const all   = names.length > 0 && names.every(name => this.selected.has(name));
		if (all) {
			this.selected.clear();
		} else {
			names.forEach(name => this.selected.add(name));
		}
		this.render();
	}

	render() {
		const previews = this.selectable();
		const present  = new Set(previews.map(el => el.dataset.imageName));
		// A selected image deleted some other way is no longer selected.
		for (const name of this.selected) {
			if (!present.has(name)) this.selected.delete(name);
		}
		previews.forEach(el => {
			el.classList.toggle("selected", this.active && this.selected.has(el.dataset.imageName));
		});

		if (!this.bar) return;
		const count = this.selected.size;
		if (this.countEl) {
			this.countEl.textContent = t("gallery.selected", { count }, "{count} selected");
		}
		if (this.delButton) this.delButton.disabled = this.busy || count === 0;
		if (this.allButton) {
			this.allButton.disabled = this.busy;
			this.allButton.classList.toggle("active", count > 0 && count === previews.length);
		}
		this.bar.classList.toggle("busy", this.busy);
	}

	async deleteSelected() {
		const names = [...this.selected];
		if (names.length === 0 || this.busy) return;

		const message = names.length === 1
			? t("confirm.delete_image", {}, "Are you sure that you want to delete this image?")
			: t("confirm.delete_images", { count: names.length }, "Are you sure that you want to delete these {count} images?");
		if (!await tcmsConfirm({ message })) return;

		const { form } = this.gallery;
		const api = `/collections/${form.collection}/${form.id}/${this.gallery.property}/bulk/delete`;

		this.busy = true;
		this.render();
		try {
			const response = await form.api.postAPI(api, { names });
			// `missing` names are not in the gallery on the server either (deleted
			// in another tab, say): their thumbnails go too.
			this.removeImages([...(response?.deleted ?? []), ...(response?.missing ?? [])]);
		} catch (error) {
			console.error("Failed to delete images", error);
			alert(apiErrorMessage(error, "error.delete_images"));
		} finally {
			this.busy = false;
			if (this.active && this.selected.size === 0) {
				this.stop();
			} else {
				this.render();
			}
		}
	}

	// The images are gone on the server: take them off the page, as the single
	// delete does. Nothing to autosave; the server already saved the gallery.
	removeImages(names) {
		const gone   = new Set(names);
		const active = this.gallery.activePreview;
		if (active && gone.has(active.getImageName())) {
			this.gallery.closeSharedDialog();
		}
		for (const el of this.selectable()) {
			const name = el.dataset.imageName;
			if (!gone.has(name)) continue;
			this.gallery.imageDataStore.delete(name);
			this.selected.delete(name);
			el.remove();
		}
	}
}
