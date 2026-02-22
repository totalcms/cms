import TotalField from "./totalfield.js";
import DOMPurify from 'dompurify';

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
		this.debounceTimer = null;
		this.initField();
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
		if (this.codeMirror) {
			this.input.value = this.codeMirror.getValue();
		}
		return super.validate();
	}

	initField() {
		this.input.style.display = 'none';

		// Create field wrapper (don't overwrite this.container — TotalField uses it)
		this.fieldWrapper = document.createElement('div');
		this.fieldWrapper.className = 'svg-field-container';
		this.input.parentNode.insertBefore(this.fieldWrapper, this.input.nextSibling);

		// Editor pane
		const editorPane = document.createElement('div');
		editorPane.className = 'svg-field-editor';
		this.fieldWrapper.appendChild(editorPane);

		// Preview pane
		this.previewPane = document.createElement('div');
		this.previewPane.className = 'svg-field-preview';
		this.fieldWrapper.appendChild(this.previewPane);

		// Drop zone overlay
		this.dropZone = document.createElement('div');
		this.dropZone.className = 'svg-field-dropzone';
		this.dropZone.textContent = 'Drop SVG file';
		this.fieldWrapper.appendChild(this.dropZone);

		// Init CodeMirror
		this.codeMirror = CodeMirror(editorPane, {
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

		this.codeMirror.setSize(null, '100%');

		// Sync changes with debounced preview
		this.codeMirror.on('change', () => {
			this.input.value = this.codeMirror.getValue();
			this.changed();
			this.debouncedPreview();
		});

		// Drag & drop
		this.initDragDrop();

		// Initial preview
		this.updatePreview();

		// Refresh after a tick to ensure proper rendering
		setTimeout(() => this.codeMirror?.refresh(), 50);
	}

	debouncedPreview() {
		clearTimeout(this.debounceTimer);
		this.debounceTimer = setTimeout(() => this.updatePreview(), 300);
	}

	updatePreview() {
		const code = this.codeMirror ? this.codeMirror.getValue().trim() : '';

		if (!code) {
			this.previewPane.innerHTML = '';
			const placeholder = document.createElement('div');
			placeholder.className = 'svg-field-placeholder';
			placeholder.textContent = 'Paste SVG code or drag & drop an SVG file';
			this.previewPane.appendChild(placeholder);
			return;
		}

		const clean = DOMPurify.sanitize(code, {
			USE_PROFILES: { svg: true },
			ADD_TAGS: ['use'],
		});
		this.previewPane.innerHTML = clean;
	}

	initDragDrop() {
		let dragCounter = 0;

		this.fieldWrapper.addEventListener('dragover', (e) => {
			e.preventDefault();
			e.stopPropagation();
		});

		this.fieldWrapper.addEventListener('dragenter', (e) => {
			e.preventDefault();
			e.stopPropagation();
			dragCounter++;
			this.fieldWrapper.classList.add('svg-field-container--dragover');
		});

		this.fieldWrapper.addEventListener('dragleave', (e) => {
			e.preventDefault();
			e.stopPropagation();
			dragCounter--;
			if (dragCounter <= 0) {
				dragCounter = 0;
				this.fieldWrapper.classList.remove('svg-field-container--dragover');
			}
		});

		this.fieldWrapper.addEventListener('drop', (e) => {
			e.preventDefault();
			e.stopPropagation();
			dragCounter = 0;
			this.fieldWrapper.classList.remove('svg-field-container--dragover');

			const files = e.dataTransfer?.files;
			if (!files || files.length === 0) return;

			const file = files[0];
			if (file.type !== 'image/svg+xml' && !file.name.endsWith('.svg')) {
				return;
			}

			const reader = new FileReader();
			reader.onload = (event) => {
				let text = event.target.result;
				// Strip XML declaration and DOCTYPE
				text = text.replace(/<\?xml[^?]*\?>\s*/i, '');
				text = text.replace(/<!DOCTYPE[^>]*>\s*/i, '');
				text = text.trim();
				this.setValue(text);
			};
			reader.readAsText(file);
		});
	}

	schema() {
		return {
			"type"  : "string",
			"field" : "svg"
		};
	}
}
