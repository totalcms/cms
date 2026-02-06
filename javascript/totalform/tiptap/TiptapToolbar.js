/**
 * TiptapToolbar - Builds and manages the toolbar for the Tiptap editor.
 * Each button uses CSS mask-image via --icon-ste-* CSS variables from icons.css.
 * Subscribes to editor selectionUpdate to toggle .is-active class.
 */

import { BULLET_STYLES, ORDERED_STYLES } from './extensions/ListStyle.js';

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

const ALIGN_OPTIONS = [
	{ value: 'left',    icon: 'align-left',    label: 'Align Left' },
	{ value: 'center',  icon: 'align-center',  label: 'Align Center' },
	{ value: 'right',   icon: 'align-right',   label: 'Align Right' },
	{ value: 'justify', icon: 'align-justify',  label: 'Align Justify' },
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
			{ name: 'paragraph', buttons: ['bulletList', 'orderedList', 'heading', 'align'] },
			{ name: 'insert', buttons: ['link', 'image'] },
			{ name: 'misc', buttons: ['clearFormatting', 'codeView'], align: 'right' },
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
				if (buttonName === 'align') {
					groupEl.appendChild(this.buildAlignDropdown());
					continue;
				}
				if (buttonName === 'bulletList') {
					groupEl.appendChild(this.buildListDropdown('bulletList', BULLET_STYLES));
					continue;
				}
				if (buttonName === 'orderedList') {
					groupEl.appendChild(this.buildListDropdown('orderedList', ORDERED_STYLES));
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

	buildAlignDropdown() {
		const wrapper = document.createElement('div');
		wrapper.className = 'ste-toolbar-dropdown';

		const toggle = document.createElement('button');
		toggle.type = 'button';
		toggle.className = 'ste-toolbar-btn ste-toolbar-dropdown-toggle ste-toolbar-dropdown-toggle--icon';
		toggle.title = 'Text Alignment';
		toggle.setAttribute('aria-label', 'Text Alignment');
		toggle.style.setProperty('--btn-icon', 'var(--icon-ste-align-left)');
		toggle.innerHTML = `<span class="ste-caret"></span>`;

		const menu = document.createElement('div');
		menu.className = 'ste-toolbar-dropdown-menu ste-toolbar-dropdown-menu--row';

		for (const opt of ALIGN_OPTIONS) {
			const item = document.createElement('button');
			item.type = 'button';
			item.className = 'ste-toolbar-btn ste-toolbar-dropdown-item';
			item.title = opt.label;
			item.setAttribute('aria-label', opt.label);
			item.dataset.align = opt.value;
			item.style.setProperty('--btn-icon', `var(--icon-ste-${opt.icon})`);

			item.addEventListener('click', (e) => {
				e.preventDefault();
				this.editor.chain().focus().setTextAlign(opt.value).run();
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

		document.addEventListener('click', () => this.closeDropdowns());

		wrapper.appendChild(toggle);
		wrapper.appendChild(menu);

		this.buttons.set('align', { element: wrapper, type: 'dropdown' });
		return wrapper;
	}

	buildListDropdown(name, styles) {
		const isBullet = name === 'bulletList';
		const toggleCommand = isBullet ? 'toggleBulletList' : 'toggleOrderedList';
		const classCommand = isBullet ? 'setBulletListClass' : 'setOrderedListClass';
		const iconName = isBullet ? 'unordered-list' : 'ordered-list';
		const label = isBullet ? 'Bullet List' : 'Ordered List';

		const wrapper = document.createElement('div');
		wrapper.className = 'ste-toolbar-dropdown';

		const toggle = document.createElement('button');
		toggle.type = 'button';
		toggle.className = 'ste-toolbar-btn ste-toolbar-dropdown-toggle ste-toolbar-dropdown-toggle--icon';
		toggle.title = label;
		toggle.setAttribute('aria-label', label);
		toggle.style.setProperty('--btn-icon', `var(--icon-ste-${iconName})`);
		toggle.innerHTML = `<span class="ste-caret"></span>`;

		// Clicking the icon area toggles the list
		toggle.addEventListener('click', (e) => {
			e.preventDefault();
			// If caret was clicked, open dropdown instead
			if (e.target.closest('.ste-caret')) {
				e.stopPropagation();
				const isOpen = wrapper.classList.contains('is-open');
				this.closeDropdowns();
				if (!isOpen) wrapper.classList.add('is-open');
				return;
			}
			this.editor.chain().focus()[toggleCommand]().run();
		});

		const menu = document.createElement('div');
		menu.className = 'ste-toolbar-dropdown-menu';

		for (const style of styles) {
			const item = document.createElement('button');
			item.type = 'button';
			item.className = 'ste-toolbar-dropdown-item';
			item.textContent = style.label;
			item.dataset.listClass = style.value;

			item.addEventListener('click', (e) => {
				e.preventDefault();
				const chain = this.editor.chain().focus();
				// Ensure the list is active first
				if (!this.editor.isActive(name)) {
					chain[toggleCommand]();
				}
				chain[classCommand](style.value).run();
				this.closeDropdowns();
			});

			menu.appendChild(item);
		}

		document.addEventListener('click', () => this.closeDropdowns());

		wrapper.appendChild(toggle);
		wrapper.appendChild(menu);

		this.buttons.set(name, { element: wrapper, type: 'dropdown' });
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
				if (name === 'heading') this.updateHeadingDropdown(entry.element);
				if (name === 'align') this.updateAlignDropdown(entry.element);
				if (name === 'bulletList' || name === 'orderedList') this.updateListDropdown(name, entry.element);
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

	updateAlignDropdown(wrapper) {
		const toggle = wrapper.querySelector('.ste-toolbar-dropdown-toggle');
		const items = wrapper.querySelectorAll('.ste-toolbar-dropdown-item');
		let activeIcon = 'align-left';

		for (const item of items) {
			const value = item.dataset.align;
			const isActive = this.editor.isActive({ textAlign: value });
			item.classList.toggle('is-active', isActive);
			if (isActive) activeIcon = `align-${value}`;
		}

		if (toggle) {
			toggle.style.setProperty('--btn-icon', `var(--icon-ste-${activeIcon})`);
		}
	}

	updateListDropdown(name, wrapper) {
		const toggle = wrapper.querySelector('.ste-toolbar-dropdown-toggle');
		const isActive = this.editor.isActive(name);
		if (toggle) toggle.classList.toggle('is-active', isActive);

		const items = wrapper.querySelectorAll('.ste-toolbar-dropdown-item');
		for (const item of items) {
			const cls = item.dataset.listClass;
			// Active if list is active and class matches (empty = default/no class)
			const itemActive = isActive && (
				cls ? this.editor.isActive(name, { class: cls }) : !this.editor.getAttributes(name).class
			);
			item.classList.toggle('is-active', itemActive);
		}
	}

	destroy() {
		this.buttons.clear();
		this.element?.remove();
		this.element = null;
	}
}
