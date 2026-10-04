import StyledTextField from "./styledtext.js";
import TiptapMarkdownEditor from "./tiptap/TiptapMarkdownEditor.js";

//-----------------------------------------------
// Total CMS Styled Markdown Field
//
// The styledtext field with a markdown editor: same uploads, same settings,
// but the value is a Markdown string. Render it with the |markdown filter.
// (The `markdown` field edits the same value as plain source.)
//-----------------------------------------------
export default class StyledMarkdownField extends StyledTextField {

	createEditor() {
		return new TiptapMarkdownEditor(this.input, this.settings);
	}

	setValue(value) {
		this.input.value = value;
		this.tiptap.setValue(value);
		this.changed();
	}

	getValue() {
		// Fall back to input value if editor is not ready
		return this.tiptap ? this.tiptap.getValue() : this.input.value;
	}

	schema() {
		return {
			"type"  : "string",
			"field" : "styledmarkdown"
		};
	}
}
