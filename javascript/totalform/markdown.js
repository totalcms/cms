import TotalField from "./totalfield.js";
import MarkdownSourceEditor from "./markdown/MarkdownSourceEditor.js";
import { editorUploadUrl } from "./editor-uploads.js";

//-----------------------------------------------
// Total CMS Markdown Field
//
// A source editor: the author writes Markdown, the toolbar inserts Markdown
// syntax, and the value is stored as written. `styledmarkdown` edits the same
// value in the Styled Text visual editor.
//-----------------------------------------------
export default class MarkdownField extends TotalField {

	constructor(container, settings) {
		super(container, settings);

		// Skip if already initialized on this input. The same flag as the
		// styledtext editors: duplicating a deck item clears it (and removes
		// the cloned .ste-editor-container) so the copy builds its own editor.
		if (this.input.dataset.steInitialized) {
			return;
		}
		this.input.dataset.steInitialized = 'true';

		// get final settings... defaultConfig() -> settings from arguments
		this.settings = Object.assign({}, this.defaultConfig(), this.settings);

		this.editor = this.createEditor();
	}

	createEditor() {
		return new MarkdownSourceEditor(this.input, this.settings);
	}

	setValue(value) {
		this.input.value = value;
		this.editor.setValue(value);
		this.changed();
	}

	getValue() {
		// Fall back to input value if editor is not ready
		return this.editor ? this.editor.getValue() : this.input.value;
	}

	defaultConfig() {
		const height = this.input.dataset.height > 0 ? this.input.dataset.height : null;

		return {
			height           : height,
			heightMin        : 200,
			heightMax        : 800,
			placeholder      : this.input.getAttribute("placeholder"),
			onContentChanged : () => this.changed(),
			uploadUrl        : () => editorUploadUrl(this),
			imagePreset      : this.settings.imagePreset || null,
			imageUploadRules : this.settings.imageUploadRules || this.settings.rules || {},
		};
	}

	schema() {
		return {
			"type"  : "string",
			"field" : "markdown"
		};
	}
}
