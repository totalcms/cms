/**
 * TiptapToolbar - Builds and manages the toolbar for the Tiptap editor.
 * Each button uses CSS mask-image via --icon-ste-* CSS variables from icons.css.
 * Subscribes to editor selectionUpdate to toggle .is-active class.
 */

/* Maps button names to their --icon-ste-* CSS variable suffix */
const ICON_MAP = {
	bold:            'bold',
	italic:          'italic',
	underline:       'underline',
	strike:          'strikethrough',
	superscript:     'superscript',
	subscript:       'subscript',
	bulletList:      'unordered-list',
	orderedList:     'ordered-list',
	blockquote:      'blockquote',
	codeBlock:       'code',
	horizontalRule:  'hr',
	alignLeft:       'align-left',
	alignCenter:     'align-center',
	alignRight:      'align-right',
	alignJustify:    'align-justify',
	link:            'link',
	image:           'image',
	video:           'video',
	file:            'file',
	table:           'table',
	undo:            'undo',
	redo:            'redo',
	clearFormatting: 'eraser',
	codeView:        'code-view',
	fullscreen:      'fullscreen',
	hardBreak:       'hard-break',
	caretDown:       'caret-down',
};

const BUTTON_DEFS = {
	bold:            { command: 'toggleBold',         label: 'Bold' },
	italic:          { command: 'toggleItalic',       label: 'Italic' },
	underline:       { command: 'toggleUnderline',    label: 'Underline' },
	strike:          { command: 'toggleStrike',       label: 'Strikethrough' },
	superscript:     { command: 'toggleSuperscript',  label: 'Superscript' },
	subscript:       { command: 'toggleSubscript',    label: 'Subscript' },
	bulletList:      { command: 'toggleBulletList',   label: 'Bullet List' },
	orderedList:     { command: 'toggleOrderedList',  label: 'Ordered List' },
	blockquote:      { command: 'toggleBlockquote',   label: 'Blockquote' },
	codeBlock:       { command: 'toggleCodeBlock',    label: 'Code Block' },
	horizontalRule:  { command: 'setHorizontalRule',  label: 'Horizontal Rule' },
	alignLeft:       { command: 'setTextAlign',       args: 'left',    label: 'Align Left' },
	alignCenter:     { command: 'setTextAlign',       args: 'center',  label: 'Align Center' },
	alignRight:      { command: 'setTextAlign',       args: 'right',   label: 'Align Right' },
	alignJustify:    { command: 'setTextAlign',       args: 'justify', label: 'Align Justify' },
	link:            { command: 'openLinkDialog',     label: 'Link' },
	image:           { command: 'openImageDialog',    label: 'Image' },
	video:           { command: 'openVideoDialog',    label: 'Video' },
	file:            { command: 'openFileDialog',     label: 'File' },
	table:           { command: 'insertTable',        label: 'Table' },
	undo:            { command: 'undo',               label: 'Undo' },
	redo:            { command: 'redo',               label: 'Redo' },
	clearFormatting: { command: 'unsetAllMarks',      label: 'Clear Formatting' },
	codeView:        { command: 'toggleCodeView',     label: 'Code View' },
	fullscreen:      { command: 'toggleFullscreen',   label: 'Fullscreen' },
	hardBreak:       { command: 'setHardBreak',       label: 'Hard Break' },
};

const HEADING_OPTIONS = [
	{ level: 0,  label: 'Normal' },
	{ level: 2,  label: 'Heading 2' },
	{ level: 3,  label: 'Heading 3' },
	{ level: 4,  label: 'Heading 4' },
];

export default class TiptapToolbar {

	constructor(editor, config) {
		this.editor = editor;
		this.config = config || this.defaultConfig();
		this.element = null;
		this.buttons = new Map();
		this.build();
	}

	defaultConfig() {
		return [
			{ name: 'text', buttons: ['bold', 'italic', 'underline'] },
			{ name: 'paragraph', buttons: ['bulletList', 'orderedList', 'heading', 'alignLeft', 'alignCenter', 'alignRight', 'alignJustify'] },
			{ name: 'insert', buttons: ['link', 'image'] },
			{ name: 'misc', buttons: ['undo', 'redo', 'clearFormatting', 'codeView'], align: 'right' },
		];
	}

	build() {
		this.element = document.createElement('div');
		this.element.className = 'ste-toolbar';

		for (const group of this.config) {
			const groupEl = document.createElement('div');
			groupEl.className = 'ste-toolbar-group';
			if (group.align === 'right') {
				groupEl.classList.add('ste-toolbar-group--right');
			}

			for (const buttonName of group.buttons) {
				if (buttonName === 'heading') {
					groupEl.appendChild(this.buildHeadingDropdown());
					continue;
				}

				const def = BUTTON_DEFS[buttonName];
				if (!def) continue;

				const btn = this.createButton(buttonName, def);
				groupEl.appendChild(btn);
			}

			this.element.appendChild(groupEl);
		}
	}

	createButton(name, def) {
		const btn = document.createElement('button');
		btn.type = 'button';
		btn.className = 'ste-toolbar-btn';
		btn.dataset.command = def.command;
		btn.title = def.label;
		btn.setAttribute('aria-label', def.label);
		if (def.args) btn.dataset.args = def.args;

		if (ICON_MAP[name]) {
			btn.style.setProperty('--btn-icon', `var(--icon-ste-${ICON_MAP[name]})`);
		}

		btn.addEventListener('click', (e) => {
			e.preventDefault();
			this.executeCommand(def);
		});

		this.buttons.set(name, { element: btn, def });
		return btn;
	}

	buildHeadingDropdown() {
		const wrapper = document.createElement('div');
		wrapper.className = 'ste-toolbar-dropdown';

		const toggle = document.createElement('button');
		toggle.type = 'button';
		toggle.className = 'ste-toolbar-btn ste-toolbar-dropdown-toggle';
		toggle.title = 'Paragraph Format';
		toggle.setAttribute('aria-label', 'Paragraph Format');
		toggle.style.setProperty('--btn-icon', 'var(--icon-ste-format)');
		toggle.innerHTML = `<span class="ste-caret"></span>`;

		const menu = document.createElement('div');
		menu.className = 'ste-toolbar-dropdown-menu';

		for (const opt of HEADING_OPTIONS) {
			const item = document.createElement('button');
			item.type = 'button';
			item.className = 'ste-toolbar-dropdown-item';
			item.textContent = opt.label;
			item.dataset.level = opt.level;

			item.addEventListener('click', (e) => {
				e.preventDefault();
				if (opt.level === 0) {
					this.editor.chain().focus().setParagraph().run();
				} else {
					this.editor.chain().focus().toggleHeading({ level: opt.level }).run();
				}
				this.closeDropdowns();
			});

			menu.appendChild(item);
		}

		toggle.addEventListener('click', (e) => {
			e.preventDefault();
			e.stopPropagation();
			const isOpen = wrapper.classList.contains('is-open');
			this.closeDropdowns();
			if (!isOpen) wrapper.classList.add('is-open');
		});

		// Close on outside click
		document.addEventListener('click', () => this.closeDropdowns());

		wrapper.appendChild(toggle);
		wrapper.appendChild(menu);

		this.buttons.set('heading', { element: wrapper, type: 'dropdown' });
		return wrapper;
	}

	closeDropdowns() {
		this.element.querySelectorAll('.ste-toolbar-dropdown.is-open')
			.forEach(el => el.classList.remove('is-open'));
	}

	executeCommand(def) {
		const { command, args } = def;

		// Commands that need to be handled by TiptapEditor (not direct editor commands)
		const delegatedCommands = [
			'toggleCodeView', 'openLinkDialog', 'openImageDialog',
			'openVideoDialog', 'openFileDialog', 'insertTable',
			'setColor', 'setHighlight', 'setFontFamily', 'toggleFullscreen',
		];

		if (delegatedCommands.includes(command)) {
			// Try direct command first, otherwise dispatch event for TiptapEditor
			if (this.editor.commands[command]) {
				this.editor.commands[command](args);
			} else {
				this.element.dispatchEvent(new CustomEvent('toolbar-command', {
					detail: { command, args },
					bubbles: true,
				}));
			}
			return;
		}

		if (args) {
			this.editor.chain().focus()[command](args).run();
		} else {
			this.editor.chain().focus()[command]().run();
		}
	}

	updateActiveStates() {
		for (const [name, entry] of this.buttons) {
			if (entry.type === 'dropdown') {
				this.updateHeadingDropdown(entry.element);
				continue;
			}

			const { element: btn, def } = entry;
			let isActive = false;

			switch (name) {
				case 'bold':           isActive = this.editor.isActive('bold'); break;
				case 'italic':         isActive = this.editor.isActive('italic'); break;
				case 'underline':      isActive = this.editor.isActive('underline'); break;
				case 'strike':         isActive = this.editor.isActive('strike'); break;
				case 'superscript':    isActive = this.editor.isActive('superscript'); break;
				case 'subscript':      isActive = this.editor.isActive('subscript'); break;
				case 'bulletList':     isActive = this.editor.isActive('bulletList'); break;
				case 'orderedList':    isActive = this.editor.isActive('orderedList'); break;
				case 'blockquote':     isActive = this.editor.isActive('blockquote'); break;
				case 'codeBlock':      isActive = this.editor.isActive('codeBlock'); break;
				case 'alignLeft':      isActive = this.editor.isActive({ textAlign: 'left' }); break;
				case 'alignCenter':    isActive = this.editor.isActive({ textAlign: 'center' }); break;
				case 'alignRight':     isActive = this.editor.isActive({ textAlign: 'right' }); break;
				case 'alignJustify':   isActive = this.editor.isActive({ textAlign: 'justify' }); break;
				case 'link':           isActive = this.editor.isActive('link'); break;
			}

			btn.classList.toggle('is-active', isActive);
		}
	}

	updateHeadingDropdown(wrapper) {
		const items = wrapper.querySelectorAll('.ste-toolbar-dropdown-item');
		for (const item of items) {
			const level = Number(item.dataset.level);
			const isActive = level === 0
				? !this.editor.isActive('heading')
				: this.editor.isActive('heading', { level });
			item.classList.toggle('is-active', isActive);
		}
	}

	destroy() {
		this.buttons.clear();
		this.element?.remove();
		this.element = null;
	}
}
