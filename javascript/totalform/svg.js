import TotalField from "./totalfield.js";

import CodeMirror from "codemirror";
import "codemirror/mode/xml/xml";

//-----------------------------------------------
// Total CMS SVG Field
//-----------------------------------------------
export default class SVGField extends TotalField {

	constructor(container, options) {
		super(container, options);

		// Skip if already initialized on this input
		if (this.input.dataset.codemirrorInitialized) {
			return;
		}
		this.input.dataset.codemirrorInitialized = 'true';

		this.codeMirror = null;
		this.initCodeMirror();
	}

	setValue(value) {
		this.input.value = value;
		if (this.codeMirror) {
			this.codeMirror.setValue(value);
		}
		this.changed();
	}

	getValue() {
		if (this.codeMirror) {
			return this.codeMirror.getValue();
		}
		return this.input.value;
	}

	validate() {
		if (!this.isVisible()) return true;
		// Sync from CodeMirror to textarea before validation
		if (this.codeMirror) {
			this.input.value = this.codeMirror.getValue();
		}
		return super.validate();
	}

	initCodeMirror() {
		// Hide the textarea
		this.input.style.display = 'none';

		// Create a wrapper for the CodeMirror instance
		const wrapper = document.createElement('div');
		wrapper.className = 'svg-codemirror-wrapper';
		this.input.parentNode.insertBefore(wrapper, this.input.nextSibling);

		this.codeMirror = CodeMirror(wrapper, {
			value: this.input.value || '',
			indentWithTabs: true,
			lineNumbers: true,
			lineWrapping: true,
			readOnly: false,
			autofocus: false,
			mode: 'xml',
			tabMode: 'indent',
			tabSize: 2,
		});

		this.codeMirror.setSize(null, '300px');

		// Sync content changes back to textarea
		this.codeMirror.on('change', () => {
			this.input.value = this.codeMirror.getValue();
			this.changed();
		});

		// Refresh after a tick to ensure proper rendering
		setTimeout(() => this.codeMirror?.refresh(), 50);
	}

	schema() {
		return {
			"type"  : "string",
			"field" : "svg"
		};
	}
}
