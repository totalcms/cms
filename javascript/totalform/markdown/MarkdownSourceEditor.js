/**
 * MarkdownSourceEditor - the markdown field's editor.
 *
 * The author writes Markdown source. The toolbar inserts Markdown syntax, the
 * preview shows it rendered, and nothing rewrites what was typed. The hidden
 * form textarea holds the value and is updated on every change.
 */

import MarkdownToolbar from './MarkdownToolbar.js';
import { markdownCommand } from './commands.js';
import { CodeMirrorSurface, TextareaSurface } from './surfaces.js';
import { renderMarkdownPreview } from './preview.js';
import { createImageDialog } from '../tiptap/extensions/ImageDialog.js';
import { createFileDialog } from '../tiptap/extensions/FileLink.js';

// The default. A field can set its own with `toolbarConfig`; `strike` also
// works here and is left out by choice.
const DEFAULT_TOOLBAR = [
	{ name: 'text', buttons: ['heading', 'bold', 'italic', 'inlineCode'] },
	{ name: 'paragraph', buttons: ['bulletList', 'orderedList', 'blockquote', 'codeBlock', 'horizontalRule'] },
	{ name: 'insert', buttons: ['link', 'image', 'file', 'table'] },
	{ name: 'misc', buttons: ['preview', 'fullscreen'], align: 'right' },
];

const PREVIEW_DELAY = 150;

// A URL inside ](…) ends at a space or a closing parenthesis.
function markdownUrl(url) {
	return String(url).replace(/ /g, '%20').replace(/\(/g, '%28').replace(/\)/g, '%29');
}

function markdownLabel(text) {
	return String(text ?? '').replace(/([\[\]])/g, '\\$1');
}

export default class MarkdownSourceEditor {

	constructor(textarea, options = {}) {
		this.textarea     = textarea;
		this.options      = options;
		this.previewing   = false;
		this.fullscreen   = false;
		this.previewTimer = null;
		this.escHandler   = (event) => {
			if (event.key === 'Escape' && this.fullscreen) this.toggleFullscreen();
		};

		this.build();
	}

	build() {
		this.textarea.style.display = 'none';

		this.container = document.createElement('div');
		this.container.className = 'ste-editor-container ste-markdown ste-markdown-source';
		this.textarea.parentNode.insertBefore(this.container, this.textarea.nextSibling);

		this.toolbar = new MarkdownToolbar(this.options.toolbarConfig || DEFAULT_TOOLBAR, {
			headingLevels: this.options.headingLevels,
			onCommand: (name, args) => this.run(name, args),
		});
		this.container.appendChild(this.toolbar.element);

		this.bodyEl = document.createElement('div');
		this.bodyEl.className = 'ste-source-body';
		this.container.appendChild(this.bodyEl);

		this.sourceEl = document.createElement('div');
		this.sourceEl.className = 'ste-source';
		this.bodyEl.appendChild(this.sourceEl);

		this.previewEl = document.createElement('div');
		this.previewEl.className = 'ste-preview';
		this.bodyEl.appendChild(this.previewEl);

		this.applyHeights();

		this.surface = this.createSurface();
		this.surface.onChange(() => this.handleChange());
	}

	// The same height settings as styledtext: a fixed height, or a range.
	applyHeights() {
		const { height, heightMin = 200, heightMax = 800 } = this.options;
		const style = this.container.style;

		style.setProperty('--ste-source-min', `${height || heightMin}px`);
		style.setProperty('--ste-source-max', `${height || heightMax}px`);
	}

	createSurface() {
		const value       = this.textarea.value || '';
		const placeholder = this.options.placeholder || this.textarea.getAttribute('placeholder') || '';

		// admin.js loads CodeMirror; a forms.js page does not, and gets a textarea.
		if (window.TotalCMSCodeMirror) {
			const settings = { value, lineNumbers: false };
			if (placeholder) settings.placeholder = placeholder;

			return new CodeMirrorSurface(window.TotalCMSCodeMirror.createMarkdownEditor(this.sourceEl, settings));
		}

		return new TextareaSurface(this.sourceEl, { value, placeholder });
	}

	handleChange() {
		this.textarea.value = this.surface.getValue();
		this.options.onContentChanged?.();
		if (this.previewing) this.schedulePreview();
	}

	run(name, args) {
		switch (name) {
			case 'preview':
				return this.togglePreview();
			case 'fullscreen':
				return this.toggleFullscreen();
			case 'image':
				return this.insertImage();
			case 'file':
				return this.insertFile();
			default:
				return this.edit(name, args);
		}
	}

	edit(name, args) {
		const [from, to] = this.surface.getSelection();
		const change     = markdownCommand(name, this.surface.getValue(), from, to, args);
		if (!change) return;

		this.surface.apply(change);
		this.surface.focus();
	}

	//-------------------------
	// Images and files
	//-------------------------

	uploadUrl() {
		const url = this.options.uploadUrl;

		return typeof url === 'function' ? url() : url;
	}

	buildUploadConfig(type) {
		const config = { url: this.options.uploadUrl };
		const rules  = this.options[`${type}UploadRules`];
		if (rules) config.rules = rules;
		if (type === 'image' && this.options.imagePreset) {
			config.imagePreset = this.options.imagePreset;
		}

		return config;
	}

	// What the upload dialogs insert into: markdown at the cursor.
	insertTarget() {
		return {
			getContent: () => this.textarea.value,
			insertImage: ({ src, alt }) => this.edit('insert', { text: `![${markdownLabel(alt)}](${markdownUrl(src)})` }),
			insertFileLink: ({ href, text }) => this.edit('insert', { text: `[${markdownLabel(text)}](${markdownUrl(href)})` }),
		};
	}

	// Uploads need a saved object. Until there is one, insert the syntax.
	insertImage() {
		if (!this.uploadUrl()) return this.edit('image');
		createImageDialog(this.insertTarget(), this.buildUploadConfig('image'));
	}

	insertFile() {
		if (!this.uploadUrl()) return this.edit('link');
		createFileDialog(this.insertTarget(), this.buildUploadConfig('file'));
	}

	//-------------------------
	// Preview and fullscreen
	//
	// One preview button. Inside the form the preview replaces the source;
	// in fullscreen the two sit side by side and the preview follows the
	// source. The layout is CSS on the two container classes.
	//-------------------------

	togglePreview() {
		this.previewing = !this.previewing;
		this.container.classList.toggle('ste-previewing', this.previewing);
		this.toolbar.setActive('preview', this.previewing);

		if (this.previewing) this.renderPreview();
		this.updateLayout();
	}

	toggleFullscreen() {
		this.fullscreen = !this.fullscreen;
		this.container.classList.toggle('ste-fullscreen', this.fullscreen);
		this.toolbar.setActive('fullscreen', this.fullscreen);

		if (this.fullscreen) {
			document.addEventListener('keydown', this.escHandler);
		} else {
			document.removeEventListener('keydown', this.escHandler);
		}
		this.updateLayout();
	}

	updateLayout() {
		// The source is hidden only by a preview inside the form.
		this.toolbar.setLocked(this.previewing && !this.fullscreen);
		this.surface.refresh();
	}

	renderPreview() {
		this.previewEl.innerHTML = renderMarkdownPreview(this.textarea.value);
	}

	schedulePreview() {
		clearTimeout(this.previewTimer);
		this.previewTimer = setTimeout(() => this.renderPreview(), PREVIEW_DELAY);
	}

	//-------------------------
	// Value
	//-------------------------

	getValue() {
		return this.textarea.value;
	}

	setValue(markdown) {
		this.textarea.value = markdown;
		this.surface.setValue(markdown);
		if (this.previewing) this.renderPreview();
	}

	focus() {
		this.surface.focus();
	}

	destroy() {
		clearTimeout(this.previewTimer);
		document.removeEventListener('keydown', this.escHandler);
		this.surface.destroy();
		this.toolbar.destroy();
		this.container.remove();
		this.textarea.style.display = '';
	}
}
