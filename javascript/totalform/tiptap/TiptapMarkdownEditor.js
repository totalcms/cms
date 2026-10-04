/**
 * TiptapMarkdownEditor - the markdown field's editor.
 *
 * The same editor as styledtext, limited to what Markdown can express. The
 * hidden textarea holds the canonical Markdown string and is only rewritten
 * when the user edits, so loading and saving an untouched field stores the
 * same bytes.
 */

import StarterKit from '@tiptap/starter-kit';
import Link from '@tiptap/extension-link';
import Placeholder from '@tiptap/extension-placeholder';
import { Table } from '@tiptap/extension-table';
import TableRow from '@tiptap/extension-table-row';
import TableCell from '@tiptap/extension-table-cell';
import TableHeader from '@tiptap/extension-table-header';
import CharacterCount from '@tiptap/extension-character-count';
import { TaskList, TaskItem } from '@tiptap/extension-list';
import { Markdown } from '@tiptap/markdown';
import DOMPurify from 'dompurify';
import { Marked } from 'marked';

import TiptapEditor from './TiptapEditor.js';
import TiptapCodeView from './TiptapCodeView.js';
import ImageUpload from './extensions/ImageUpload.js';
import TablePopover from './extensions/TablePopover.js';
import { patchMarkdownSerializer } from './markdown/serializer.js';
import { findLossySyntax } from './markdown/lossy.js';
import tcmsConfirm from '../../confirm-dialog';
import { t } from '../../i18n';

// The default. A field can set its own with `toolbarConfig`; `strike`, `undo`,
// `redo`, `preview` and `fullscreen` also work here and are left out by choice.
const MARKDOWN_TOOLBAR = [
	{ name: 'text', buttons: ['heading', 'bold', 'italic', 'inlineCode'] },
	{ name: 'paragraph', buttons: ['bulletList', 'orderedList', 'blockquote', 'codeBlock', 'horizontalRule'] },
	{ name: 'insert', buttons: ['link', 'image', 'file', 'table'] },
	{ name: 'misc', buttons: ['codeView'], align: 'right' },
];

// English fallbacks; the translations are the markdown.syntax.* keys.
const SYNTAX_LABELS = {
	html:         'raw HTML',
	comment:      'HTML comments',
	footnote:     'footnotes',
	abbreviation: 'abbreviations',
	linkedImage:  'linked images',
};

// The view buttons stay usable while the visual editor is hidden.
const VIEW_COMMANDS = ['toggleCodeView', 'togglePreview', 'toggleFullscreen'];

// A parser of its own: Tiptap's instance carries tokenizers with no HTML
// renderer (task lists) and throws when asked to render.
const previewParser = new Marked({ gfm: true, breaks: true });

export default class TiptapMarkdownEditor extends TiptapEditor {

	constructor(textarea, options = {}) {
		// init() runs inside super(), before anything below is assigned.
		super(textarea, { plainLists: true, ...options });

		this.mode      = 'visual';
		this.previewEl = null;
		this.noticeEl  = null;

		patchMarkdownSerializer(this.editor.markdown);
		this.container.classList.add('ste-markdown');
		this.rememberLoaded();

		// Until this is set, handleUpdate() does nothing: a transaction
		// dispatched while the editor mounts must not rewrite the stored value.
		this.ready = true;

		this.guardStoredValue();
	}

	initialContent() {
		return { content: this.textarea.value || '', contentType: 'markdown' };
	}

	toolbarConfig() {
		return this.options.toolbarConfig || MARKDOWN_TOOLBAR;
	}

	createCodeView() {
		return new TiptapCodeView(this.container, { factory: 'createMarkdownEditor' });
	}

	buildExtensions() {
		const extensions = [
			StarterKit.configure({
				heading: {
					levels: [1, 2, 3, 4, 5, 6],
				},
				link: false,
				underline: false,
			}),
			Link.configure({
				openOnClick: false,
				autolink: true,
				defaultProtocol: 'https',
				HTMLAttributes: {
					target: null,
					rel: null,
				},
			}),
			ImageUpload.configure({
				inline: false,
				allowBase64: false,
				popover: false,
			}),
			Table.configure({
				resizable: false,
			}),
			TableRow,
			TableCell,
			TableHeader,
			TablePopover,
			// No toolbar button: these exist so "- [ ]" items survive a save.
			TaskList,
			TaskItem.configure({
				nested: true,
			}),
			// breaks: a single newline is a line break, as the |markdown filter renders it.
			Markdown.configure({
				markedOptions: { gfm: true, breaks: true },
			}),
			// Counts only. A limit would make the extension cut over-long
			// content on the first transaction, before anyone has typed.
			CharacterCount,
		];

		const placeholder = this.options.placeholder || this.textarea.getAttribute('placeholder');
		if (placeholder) {
			extensions.push(Placeholder.configure({
				placeholder: placeholder,
			}));
		}

		return extensions;
	}

	serialize(editor = this.editor) {
		if (editor.isEmpty) return '';

		// Blocks end with blank lines and a leading table starts with one.
		return editor.getMarkdown().replace(/^\n+|\n+$/g, '');
	}

	/**
	 * The stored string and what the editor writes for it, taken each time
	 * content is loaded. While the document still serializes to the same
	 * thing, the field keeps the string it was given.
	 */
	rememberLoaded() {
		this.loaded = { raw: this.textarea.value, serialized: this.serialize(this.editor) };
	}

	/**
	 * Not every transaction is an edit: focusing the editor appends a trailing
	 * paragraph, and commands can reach the document while it is hidden behind
	 * source mode or the preview. The stored value changes only when the
	 * visible document serializes to something new.
	 */
	handleUpdate(editor) {
		if (!this.ready || this.mode !== 'visual' || this.previewEl) return;
		if (!editor || editor.isDestroyed) return;

		this.updateFooter();

		const serialized = this.serialize(editor);
		const value      = serialized === this.loaded.serialized ? this.loaded.raw : serialized;
		if (value === this.textarea.value) return;

		this.textarea.value = value;
		this.options.onContentChanged?.();
	}

	getValue() {
		return this.textarea.value;
	}

	setValue(markdown) {
		this.textarea.value = markdown;

		if (this.previewEl) this.closePreview();
		if (this.mode === 'source') {
			this.codeView.close(this.wrapperEl());
			this.hideNotice();
			this.setMode('visual');
		}

		this.editor.commands.setContent(markdown, { contentType: 'markdown', emitUpdate: false });
		this.rememberLoaded();
		this.guardStoredValue();
	}

	wrapperEl() {
		return this.container.querySelector('.ste-editor-wrapper');
	}

	/**
	 * Content the visual editor cannot keep is edited as source.
	 */
	guardStoredValue() {
		const lossy = findLossySyntax(this.textarea.value);
		if (lossy.length === 0) return;

		this.openSource();
		this.showNotice(lossy);
	}

	openSource() {
		this.codeView.open(this.textarea.value, this.wrapperEl());

		const sync = () => {
			this.textarea.value = this.codeView.getValue();
			this.options.onContentChanged?.();
		};
		if (this.codeView.editor) {
			this.codeView.editor.on('change', sync);
		} else {
			// No CodeMirror on the page: the code view is a plain textarea.
			this.codeView.editorContainer.querySelector('textarea')?.addEventListener('input', sync);
		}

		this.setMode('source');
	}

	/**
	 * @returns {Promise<boolean>} false when the user kept source mode
	 */
	async closeSource() {
		const value = this.codeView.getValue();
		const lossy = findLossySyntax(value);
		if (lossy.length > 0 && !(await this.confirmVisual(lossy))) return false;

		this.codeView.close(this.wrapperEl());
		this.textarea.value = value;
		this.editor.commands.setContent(value, { contentType: 'markdown', emitUpdate: false });
		this.rememberLoaded();
		this.hideNotice();
		this.setMode('visual');

		return true;
	}

	async toggleCodeView() {
		if (this.previewEl) this.closePreview();

		if (this.mode === 'source') {
			await this.closeSource();
		} else {
			this.openSource();
		}
	}

	setMode(mode) {
		this.mode = mode;
		this.container.classList.toggle('ste-mode-source', mode === 'source');
		this.container.querySelector('[data-command="toggleCodeView"]')?.classList.toggle('is-active', mode === 'source');
		this.lockToolbar();
	}

	/**
	 * Disable the formatting buttons while the visual editor is hidden.
	 * Dimming them in CSS would still leave them reachable by keyboard.
	 */
	lockToolbar() {
		const locked = this.mode === 'source' || this.previewEl !== null;

		for (const button of this.container.querySelectorAll('.ste-toolbar-btn')) {
			button.disabled = locked && !VIEW_COMMANDS.includes(button.dataset.command);
		}
		// Unlocked: let the toolbar decide again (the link button needs a selection).
		if (!locked) this.toolbar.updateActiveStates();
	}

	syntaxNames(lossy) {
		return lossy.map((name) => t(`markdown.syntax.${name}`, {}, SYNTAX_LABELS[name])).join(', ');
	}

	confirmVisual(lossy) {
		return tcmsConfirm({
			title: t('markdown.visual_title', {}, 'Switch to the visual editor?'),
			message: t(
				'markdown.visual_lossy',
				{ syntax: this.syntaxNames(lossy) },
				'This content uses {syntax}, which the visual editor cannot keep. Editing in visual mode will remove it.'
			),
			confirmLabel: t('markdown.visual_confirm', {}, 'Switch anyway'),
		});
	}

	showNotice(lossy) {
		this.hideNotice();
		this.noticeEl = document.createElement('div');
		this.noticeEl.className = 'ste-notice';
		this.noticeEl.textContent = t(
			'markdown.source_notice',
			{ syntax: this.syntaxNames(lossy) },
			'Opened in source mode because this content uses {syntax}, which the visual editor cannot keep.'
		);
		this.toolbar.element.after(this.noticeEl);
	}

	hideNotice() {
		this.noticeEl?.remove();
		this.noticeEl = null;
	}

	togglePreview() {
		if (this.previewEl) {
			this.closePreview();
		} else {
			this.openPreview();
		}
	}

	/**
	 * An approximation: marked renders here, ParsedownExtra renders the site.
	 */
	openPreview() {
		this.previewEl = document.createElement('div');
		this.previewEl.className = 'ste-preview';
		this.previewEl.innerHTML = DOMPurify.sanitize(previewParser.parse(this.getValue()));

		this.wrapperEl().style.display = 'none';
		if (this.codeView.editorContainer) this.codeView.editorContainer.style.display = 'none';
		// insertBefore(node, null) appends, for a field with no counter footer.
		this.container.insertBefore(this.previewEl, this.footerEl);

		this.container.classList.add('ste-mode-preview');
		this.container.querySelector('[data-command="togglePreview"]')?.classList.add('is-active');
		this.lockToolbar();
	}

	closePreview() {
		this.previewEl.remove();
		this.previewEl = null;

		if (this.mode === 'source') {
			this.codeView.editorContainer.style.display = '';
		} else {
			this.wrapperEl().style.display = '';
		}

		this.container.classList.remove('ste-mode-preview');
		this.container.querySelector('[data-command="togglePreview"]')?.classList.remove('is-active');
		this.lockToolbar();
	}
}
