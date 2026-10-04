/**
 * The two things a markdown source editor can type into: the CodeMirror
 * editor from codemirror-bundle.js, or a plain textarea on a page that does
 * not load CodeMirror (forms.js). Both take the edits commands.js produces.
 */

export class TextareaSurface {

	constructor(parent, { value = '', placeholder = '' } = {}) {
		this.listeners = [];
		this.element = document.createElement('textarea');
		this.element.className = 'ste-source-textarea';
		this.element.spellcheck = false;
		this.element.value = value;
		if (placeholder) this.element.placeholder = placeholder;

		this.element.addEventListener('input', () => this.changed());
		parent.appendChild(this.element);
	}

	changed() {
		this.listeners.forEach((listener) => listener());
	}

	getValue() {
		return this.element.value;
	}

	setValue(text) {
		this.element.value = text;
	}

	getSelection() {
		return [this.element.selectionStart, this.element.selectionEnd];
	}

	apply(edit) {
		const text = this.element.value;
		this.element.value = text.slice(0, edit.from) + edit.insert + text.slice(edit.to);
		this.element.setSelectionRange(edit.select[0], edit.select[1]);
		this.changed();
	}

	onChange(callback) {
		this.listeners.push(callback);
	}

	focus() {
		this.element.focus();
	}

	refresh() {
	}

	destroy() {
		this.element.remove();
		this.listeners = [];
	}
}

export class CodeMirrorSurface {

	constructor(editor) {
		this.editor    = editor;
		this.listeners = [];
		this.silent    = false;

		this.editor.on('change', () => {
			if (!this.silent) this.listeners.forEach((listener) => listener());
		});
	}

	getValue() {
		return this.editor.getValue();
	}

	// Loading a value is not an edit: the editor's own change event is muted.
	setValue(text) {
		this.silent = true;
		try {
			this.editor.setValue(text);
		} finally {
			this.silent = false;
		}
	}

	getSelection() {
		const range = this.editor.view.state.selection.main;

		return [range.from, range.to];
	}

	apply(edit) {
		this.editor.view.dispatch({
			changes: { from: edit.from, to: edit.to, insert: edit.insert },
			selection: { anchor: edit.select[0], head: edit.select[1] },
		});
	}

	onChange(callback) {
		this.listeners.push(callback);
	}

	focus() {
		this.editor.focus();
	}

	refresh() {
		this.editor.refresh();
	}

	destroy() {
		this.editor.destroy();
		this.listeners = [];
	}
}
