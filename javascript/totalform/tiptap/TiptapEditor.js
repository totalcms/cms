/**
 * TiptapEditor - Main editor wrapper.
 * Creates a Tiptap instance, manages lifecycle, and provides getHTML/setHTML.
 * The textarea stays hidden; a sibling container div holds the toolbar + ProseMirror.
 */

import { Editor } from '@tiptap/core';
import StarterKit from '@tiptap/starter-kit';
import Underline from '@tiptap/extension-underline';
import TextAlign from '@tiptap/extension-text-align';
import Placeholder from '@tiptap/extension-placeholder';
import Link from '@tiptap/extension-link';
import { Table } from '@tiptap/extension-table';
import TableRow from '@tiptap/extension-table-row';
import TableCell from '@tiptap/extension-table-cell';
import TableHeader from '@tiptap/extension-table-header';
import Color from '@tiptap/extension-color';
import { TextStyle } from '@tiptap/extension-text-style';
import Highlight from '@tiptap/extension-highlight';
import FontFamily from '@tiptap/extension-font-family';
import CharacterCount from '@tiptap/extension-character-count';
import Superscript from '@tiptap/extension-superscript';
import Subscript from '@tiptap/extension-subscript';
import Typography from '@tiptap/extension-typography';
import { Markdown } from '@tiptap/markdown';

import ImageUpload from './extensions/ImageUpload.js';
import FigureImage from './extensions/FigureImage.js';
import { Youtube, createVideoDialog } from './extensions/VideoEmbed.js';
import VideoNode from './extensions/VideoNode.js';
import AudioNode from './extensions/AudioNode.js';
import { createFileDialog } from './extensions/FileLink.js';
import { createLinkDialog } from './extensions/LinkDialog.js';
import TablePopover from './extensions/TablePopover.js';
import RawHTML from './extensions/RawHTML.js';
import InlineClass from './extensions/InlineClass.js';
import { StyledBulletList, StyledOrderedList } from './extensions/ListStyle.js';

import TiptapToolbar from './TiptapToolbar.js';
import TiptapCodeView from './TiptapCodeView.js';

export default class TiptapEditor {

	constructor(textarea, options = {}) {
		this.textarea = textarea;
		this.options = options;
		this.editor = null;
		this.toolbar = null;
		this.container = null;
		this.codeView = null;
		this.footerEl = null;

		this.init();
	}

	init() {
		// Hide the textarea
		this.textarea.style.display = 'none';

		// Create editor container
		this.container = document.createElement('div');
		this.container.className = 'ste-editor-container';
		this.textarea.parentNode.insertBefore(this.container, this.textarea.nextSibling);

		// Build extensions
		const extensions = this.buildExtensions();

		// Create Tiptap editor instance
		this.editor = new Editor({
			element: null, // we'll attach manually
			extensions: extensions,
			content: this.textarea.value || '',
			uploadConfig: this.options.upload || {},
			onUpdate: ({ editor }) => {
				this.syncToTextarea();
				this.updateFooter();
				this.options.onContentChanged?.();
			},
			onSelectionUpdate: () => {
				this.toolbar?.updateActiveStates();
			},
		});

		// Create toolbar
		this.toolbar = new TiptapToolbar(this.editor, this.options.toolbarConfig);
		this.container.appendChild(this.toolbar.element);

		// Listen for custom toolbar commands
		this.container.addEventListener('toolbar-command', (e) => {
			this.handleToolbarCommand(e.detail.command, e.detail.args);
		});

		// Create wrapper for the editor content
		const editorWrapper = document.createElement('div');
		editorWrapper.className = 'ste-editor-wrapper';
		this.container.appendChild(editorWrapper);

		// Mount editor to the wrapper
		editorWrapper.appendChild(this.editor.options.element || this.createEditorElement());

		// Set height constraints
		const heightMin = this.options.heightMin || 200;
		const heightMax = this.options.heightMax || 800;
		const height = this.options.height;

		if (height) {
			editorWrapper.style.height = `${height}px`;
			editorWrapper.style.overflowY = 'auto';
		} else {
			editorWrapper.style.minHeight = `${heightMin}px`;
			editorWrapper.style.maxHeight = `${heightMax}px`;
			editorWrapper.style.overflowY = 'auto';
		}

		// Create code view handler
		this.codeView = new TiptapCodeView(this.container);

		// Create footer for char/word counters
		this.buildFooter();

		// Update active states initially
		this.toolbar.updateActiveStates();
		this.updateFooter();
	}

	createEditorElement() {
		// Tiptap needs an element to mount to - create one and re-init
		const el = document.createElement('div');
		el.className = 'ste-prosemirror';

		// Destroy and recreate with the element
		const content = this.editor.getHTML();
		this.editor.destroy();

		this.editor = new Editor({
			element: el,
			extensions: this.buildExtensions(),
			content: content,
			uploadConfig: this.options.upload || {},
			onUpdate: ({ editor }) => {
				this.syncToTextarea();
				this.updateFooter();
				this.options.onContentChanged?.();
			},
			onSelectionUpdate: () => {
				this.toolbar?.updateActiveStates();
			},
		});

		// Re-bind toolbar's editor reference
		this.toolbar.editor = this.editor;

		return el;
	}

	buildExtensions() {
		const extensions = [
			StarterKit.configure({
				heading: {
					levels: [2, 3, 4],
				},
				// Disable extensions we configure separately
				link: false,
				underline: false,
				bulletList: false,
				orderedList: false,
			}),
			StyledBulletList,
			StyledOrderedList,
			Underline,
			TextAlign.configure({
				types: ['heading', 'paragraph'],
			}),
			Link.configure({
				openOnClick: false,
				autolink: true,
				defaultProtocol: 'https',
			}),
			ImageUpload.configure({
				inline: false,
				allowBase64: false,
			}),
			FigureImage,
			Youtube.configure({
				inline: false,
				ccLanguage: 'en',
			}),
			VideoNode,
		AudioNode,
			// Phase 3 extensions
			Table.configure({
				resizable: true,
			}),
			TableRow,
			TableCell,
			TableHeader,
			TablePopover,
			TextStyle,
			Color,
			Highlight.configure({
				multicolor: true,
			}),
			FontFamily,
			Superscript,
			Subscript,
			RawHTML,
			InlineClass,
			Typography,
			Markdown,
			CharacterCount.configure({
				limit: this.options.charCounterMax || null,
			}),
		];

		const placeholder = this.options.placeholder || this.textarea.getAttribute('placeholder');
		if (placeholder) {
			extensions.push(Placeholder.configure({
				placeholder: placeholder,
			}));
		}

		return extensions;
	}

	buildFooter() {
		const showChars = this.options.charCounterCount;
		const showWords = this.options.wordCounterCount;
		if (!showChars && !showWords) return;

		this.footerEl = document.createElement('div');
		this.footerEl.className = 'ste-footer';
		this.container.appendChild(this.footerEl);
	}

	updateFooter() {
		if (!this.footerEl || !this.editor) return;

		const storage = this.editor.storage.characterCount;
		const parts = [];

		if (this.options.charCounterCount) {
			const chars = storage.characters();
			const max = this.options.charCounterMax;
			if (max) {
				parts.push(`<span class="ste-counter${chars > max ? ' exceeded' : ''}">${chars} / ${max} characters</span>`);
			} else {
				parts.push(`<span class="ste-counter">${chars} characters</span>`);
			}
		}

		if (this.options.wordCounterCount) {
			const words = storage.words();
			const max = this.options.wordCounterMax;
			if (max) {
				parts.push(`<span class="ste-counter${words > max ? ' exceeded' : ''}">${words} / ${max} words</span>`);
			} else {
				parts.push(`<span class="ste-counter">${words} words</span>`);
			}
		}

		this.footerEl.innerHTML = parts.join(' &middot; ');
	}

	handleToolbarCommand(command, args) {
		switch (command) {
			case 'toggleCodeView':
				this.toggleCodeView();
				break;
			case 'openLinkDialog':
				this.openLinkDialog();
				break;
			case 'openImageDialog':
				this.openImageDialog();
				break;
			case 'openVideoDialog':
				this.openVideoDialog();
				break;
			case 'openFileDialog':
				this.openFileDialog();
				break;
			case 'insertTable':
				this.insertTable();
				break;
			case 'setColor':
				this.setColor(args);
				break;
			case 'setHighlight':
				this.setHighlight(args);
				break;
			case 'setFontFamily':
				this.setFontFamily(args);
				break;
			case 'toggleFullscreen':
				this.toggleFullscreen();
				break;
		}
	}

	toggleCodeView() {
		const wrapper = this.container.querySelector('.ste-editor-wrapper');

		if (this.codeView.isActive()) {
			const html = this.codeView.close(wrapper);
			if (html !== null) {
				this.editor.commands.setContent(html);
			}
			this.container.querySelector('[data-command="toggleCodeView"]')?.classList.remove('is-active');
		} else {
			this.codeView.open(this.getFormattedHTML(), wrapper);
			this.container.querySelector('[data-command="toggleCodeView"]')?.classList.add('is-active');
		}
	}

	openLinkDialog() {
		createLinkDialog(this.editor);
	}

	openImageDialog() {
		this.editor.commands.openImageDialog();
	}

	openVideoDialog() {
		const uploadConfig = this.options.upload || {};
		const dialog = createVideoDialog(this.editor, uploadConfig);
		document.body.appendChild(dialog);
	}

	openFileDialog() {
		const uploadConfig = this.options.upload || {};
		const dialog = createFileDialog(this.editor, uploadConfig);
		document.body.appendChild(dialog);
	}

	insertTable() {
		// Grid picker handles this from toolbar dropdown; fallback for programmatic use
		this.editor.chain().focus().insertTable({ rows: 3, cols: 3, withHeaderRow: true }).run();
	}

	setColor(color) {
		if (color) {
			this.editor.chain().focus().setColor(color).run();
		} else {
			this.editor.chain().focus().unsetColor().run();
		}
	}

	setHighlight(color) {
		if (color) {
			this.editor.chain().focus().toggleHighlight({ color }).run();
		} else {
			this.editor.chain().focus().unsetHighlight().run();
		}
	}

	setFontFamily(family) {
		if (family) {
			this.editor.chain().focus().setFontFamily(family).run();
		} else {
			this.editor.chain().focus().unsetFontFamily().run();
		}
	}

	toggleFullscreen() {
		this.container.classList.toggle('ste-fullscreen');

		// ESC key handler
		if (this.container.classList.contains('ste-fullscreen')) {
			this._escHandler = (e) => {
				if (e.key === 'Escape') {
					this.container.classList.remove('ste-fullscreen');
					document.removeEventListener('keydown', this._escHandler);
					this._escHandler = null;
				}
			};
			document.addEventListener('keydown', this._escHandler);
		} else if (this._escHandler) {
			document.removeEventListener('keydown', this._escHandler);
			this._escHandler = null;
		}
	}

	getFormattedHTML() {
		const html = this.editor.getHTML();
		return html
			.replace(/></g, '>\n<')
			.replace(/\n<\/(p|h[1-6]|ul|ol|li|blockquote|div|table|tr|td|th|thead|tbody|pre|hr|figure|figcaption)>/g, '</$1>\n')
			.trim();
	}

	syncToTextarea() {
		this.textarea.value = this.editor.getHTML();
	}

	getHTML() {
		if (this.codeView?.isActive()) {
			return this.codeView.getValue();
		}
		return this.editor.getHTML();
	}

	setHTML(html) {
		this.editor.commands.setContent(html);
		this.syncToTextarea();
	}

	focus() {
		this.editor.commands.focus();
	}

	destroy() {
		if (this._escHandler) {
			document.removeEventListener('keydown', this._escHandler);
		}
		this.codeView?.destroy();
		this.toolbar?.destroy();
		this.editor?.destroy();
		this.container?.remove();
		this.textarea.style.display = '';
	}
}
