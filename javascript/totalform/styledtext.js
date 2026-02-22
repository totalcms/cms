import TotalField from "./totalfield.js";
import TiptapEditor from "./tiptap/TiptapEditor.js";

import CodeMirror from "codemirror";
import "codemirror/mode/xml/xml";
import "codemirror/mode/twig/twig";
import DOMPurify from 'dompurify';

window.CodeMirror = CodeMirror;
window.DOMPurify = DOMPurify;

//-----------------------------------------------
// Total CMS Styled Text Field
//-----------------------------------------------
export default class StyledTextField extends TotalField {

	// TODO: if form ID changes, need to update upload URLs

	constructor(container, options) {
		super(container, options);

		// Skip if already initialized on this input
		if (this.input.dataset.steInitialized) {
			return;
		}
		this.input.dataset.steInitialized = 'true';

		// get final options... defaultConfig() -> global window.totalcms options -> options from arguments
		this.options = Object.assign({}, this.defaultConfig(), this.options);

		this.tiptap = new TiptapEditor(this.input, this.options);
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
			return content;
		}
		// Fall back to input value if editor is not ready
		return this.input.value;
	}

	uploadAPI() {
		if (!this.form) return null;
		const collection = this.form.collection;
		const id         = this.form.getId() ?? '';
		const property   = this.property;
		return this.api.buildApiQuery(`/upload/${collection}/${id}/${property}`);
	}

	updateUploadURLs() {
		// Will be used in Phase 2 for media upload integration
	}

	charCountExceeded() {
		// TODO: Phase 3 character counter integration
	}

	deleteFileFromServer(url) {
		if (!url) return;

		// Skip data URLs and blob URLs - they weren't uploaded to the server
		if (url.startsWith('data:') || url.startsWith('blob:')) return;

		if (confirm("Are you sure you want to delete this image?")) {
			const collection = this.form.collection;
			const id         = this.form.getId();
			const property   = this.property;
			const name       = url.split("?")[0].split("/").pop();

			// Skip if name is empty or invalid
			if (!name || name.includes(':')) return;

			const api        = `/upload/${collection}/${id}/${property}/${name}`;
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
			onContentChanged   : () => this.changed(),
			uploadUrl          : uploadUrl,
			imagePreset        : this.options.imagePreset || null,
			imageUploadRules   : this.options.imageUploadRules || this.options.rules || {},
		};
	}

	schema() {
		return {
			"type"  : "string",
			"field" : "styledtext"
		};
	}
}
