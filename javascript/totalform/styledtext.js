import TotalField from "./totalfield.js";
import TiptapEditor from "./tiptap/TiptapEditor.js";
import tcmsConfirm from "../confirm-dialog";
import { t } from "../i18n";

import DOMPurify from 'dompurify';

window.DOMPurify = DOMPurify;

//-----------------------------------------------
// Total CMS Styled Text Field
//-----------------------------------------------
export default class StyledTextField extends TotalField {

	constructor(container, settings) {
		super(container, settings);

		// Skip if already initialized on this input
		if (this.input.dataset.steInitialized) {
			return;
		}
		this.input.dataset.steInitialized = 'true';

		// get final settings... defaultConfig() -> global window.totalcms settings -> settings from arguments
		this.settings = Object.assign({}, this.defaultConfig(), this.settings);

		this.tiptap = new TiptapEditor(this.input, this.settings);

		// Initial upload-enabled state, then keep it in sync with parent-form ID
		// (top-level case) and deck-item ID (nested case) as they get filled in.
		this.tiptap.updateUploadEnabled();
		this.bindUploadReadyWatchers();
	}

	bindUploadReadyWatchers() {
		const refresh = () => this.tiptap?.updateUploadEnabled();
		const inputs  = new Set();

		// Watch the parent form's top-level ID field — the first `input[name="id"]`
		// in the form that isn't inside a dialog (deck-item dialogs have their own).
		const allFormIdInputs = Array.from(this.form?.form?.querySelectorAll('input[name="id"]') || []);
		const formIdInput = allFormIdInputs.find(input => !input.closest('dialog'));
		if (formIdInput) inputs.add(formIdInput);

		// Watch the deck-item ID input if we're inside one.
		if (this.deckItem) {
			const itemIdInput = this.deckItem.querySelector('dialog input[name="id"]');
			if (itemIdInput) inputs.add(itemIdInput);
		}

		inputs.forEach(input => input.addEventListener('input', refresh));
	}

	setValue(value) {
		this.input.value = value;
		this.tiptap.setHTML(value);
		this.changed();
	}

	getValue() {
		if (this.tiptap) {
			let content = this.tiptap.getHTML();
			content = content.replace(/\%25/g, "%"); // fix for double encoding of many characters such as colon

			// Return empty string if the editor has no real content (just empty tags)
			const tmp = document.createElement('div');
			tmp.innerHTML = content;
			if (tmp.textContent.trim() === '' && !tmp.querySelector('img, video, audio, iframe, hr, table')) {
				return '';
			}

			return content;
		}
		// Fall back to input value if editor is not ready
		return this.input.value;
	}

	uploadAPI() {
		const ctx = this.getUploadContext();
		if (!ctx) return null;
		const path = ctx.subpath ? `/${ctx.subpath}` : '';
		return this.api.buildApiQuery(`/upload/${ctx.collection}/${ctx.id}/${ctx.property}${path}`);
	}

	updateUploadURLs() {
		// Will be used in Phase 2 for media upload integration
	}

	charCountExceeded() {
		// TODO: Phase 3 character counter integration
	}

	async deleteFileFromServer(url) {
		if (!url) return;

		// Skip data URLs and blob URLs - they weren't uploaded to the server
		if (url.startsWith('data:') || url.startsWith('blob:')) return;

		if (await tcmsConfirm({ message: t("confirm.delete_image") })) {
			// The embedded URL is the source of truth for *where* the file lives,
			// regardless of the field's current upload context (the field could
			// have been moved across deck items via copy/paste).
			// URL shape: .../{imageworks|download|stream}/upload/{coll}/{id}/{prop}/{rest}
			const cleanUrl = url.split("?")[0];
			const match = cleanUrl.match(/\/(?:imageworks|download|stream)\/upload\/([^/]+)\/([^/]+)\/([^/]+)\/(.+)$/);
			if (!match) return;

			const [, coll, id, prop, rest] = match;
			if (rest.includes(':')) return;

			const api = `/upload/${coll}/${id}/${prop}/${rest}`;
			console.log("Deleting file from server", api);
			this.api.postAPI(api, {}, "DELETE");
		}
	}

	defaultConfig() {
		const megabyte = 1024 * 1024;
		const height   = this.input.dataset.height > 0 ? this.input.dataset.height : null;
		const uploadUrl = () => this.uploadAPI();

		return {
			height             : height,
			heightMin          : 200,
			heightMax          : 800,
			placeholder        : this.input.getAttribute("placeholder"),
			wordCounterCount   : false,
			pasteAsPlainText   : true,
			onContentChanged   : () => this.changed(),
			uploadUrl          : uploadUrl,
			imagePreset        : this.settings.imagePreset || null,
			imageUploadRules   : this.settings.imageUploadRules || this.settings.rules || {},
		};
	}

	schema() {
		return {
			"type"  : "string",
			"field" : "styledtext"
		};
	}
}
