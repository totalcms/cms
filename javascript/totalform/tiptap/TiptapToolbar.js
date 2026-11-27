/**
 * TiptapToolbar - Builds and manages the toolbar for the Tiptap editor.
 * Each button uses CSS mask-image via --icon-ste-* CSS variables from icons.css.
 * Subscribes to editor selectionUpdate to toggle .is-active class.
 */

import { DOMSerializer } from '@tiptap/pm/model';
import { BULLET_STYLES, ORDERED_STYLES } from './extensions/ListStyle.js';

/* Maps button names to their --icon-ste-* CSS variable suffix */
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
	indent:          'indent',
	outdent:         'outdent',
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
	textColor:       'text-color',
	textBgColor:     'text-bgcolor',
	inlineStyles:    'inline-style',
	inlineClasses:   'inline-class',
	htmlSnippets:    'add-element',
	anchor:          'anchor',
	blockAttributes: 'code',
	inlineCode:      'inline-code',
	preview:         'preview',
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
	indent:          { command: 'indent',             label: 'Indent' },
	outdent:         { command: 'outdent',            label: 'Outdent' },
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
	anchor:          { command: 'openAnchorDialog',          label: 'Anchor ID' },
	blockAttributes: { command: 'openBlockAttributesDialog', label: 'Element Attributes' },
	inlineCode:      { command: 'toggleCode',                label: 'Inline Code' },
	preview:         { command: 'togglePreview',             label: 'Preview' },
};

// Commands that need to be handled by TiptapEditor (not direct editor commands)
const DELEGATED_COMMANDS = [
	'toggleCodeView', 'openLinkDialog', 'openImageDialog',
	'openVideoDialog', 'openFileDialog', 'insertTable',
	'setColor', 'setHighlight', 'setFontFamily', 'toggleFullscreen',
	'openAnchorDialog', 'openBlockAttributesDialog', 'togglePreview',
];

const DEFAULT_HEADING_LEVELS = [2, 3, 4];

const ALIGN_OPTIONS = [
	{ value: 'left',    icon: 'align-left',    label: 'Align Left' },
	{ value: 'center',  icon: 'align-center',  label: 'Align Center' },
	{ value: 'right',   icon: 'align-right',   label: 'Align Right' },
	{ value: 'justify', icon: 'align-justify',  label: 'Align Justify' },
];

const DEFAULT_INLINE_STYLES = {
	'Large':     'font-size: 1.25em',
	'Small':     'font-size: 0.85em',
	'Uppercase': 'text-transform: uppercase; letter-spacing: 0.05em',
};

const DEFAULT_INLINE_CLASSES = {
	'Code':        'cms-inline-code',
	'Highlighted': 'cms-highlighted',
	'Badge':       'cms-badge',
};

const DEFAULT_HTML_SNIPPETS = {
	'Button':  '<button class="cms-button">{content}</button>',
	'Callout': '<div class="cms-callout">{content}</div>',
	'Badge':   '<span class="cms-badge">{content}</span>',
};

const DEFAULT_COLORS = [
	'#000000', '#434343', '#666666', '#999999', '#b7b7b7', '#cccccc', '#d9d9d9', '#ffffff',
	'#980000', '#ff0000', '#ff9900', '#ffff00', '#00ff00', '#00ffff', '#4a86e8', '#0000ff',
	'#9900ff', '#ff00ff', '#e6b8af', '#f4cccc', '#fce5cd', '#fff2cc', '#d9ead3', '#d0e0e3',
	'#c9daf8', '#cfe2f3', '#d9d2e9', '#ead1dc', '#dd7e6b', '#ea9999', '#f9cb9c', '#ffe599',
	'#b6d7a8', '#a2c4c9', '#a4c2f4', '#9fc5e8', '#b4a7d6', '#d5a6bd', '#cc4125', '#e06666',
	'#f6b26b', '#ffd966', '#93c47d', '#76a5af', '#6d9eeb', '#6fa8dc', '#8e7cc3', '#c27ba0',
];

export default class TiptapToolbar {

	constructor(editor, config, options = {}) {
		this.editor = editor;
		this.config = config || this.defaultConfig();
		this.options = options;
		this.element = null;
		this.buttons = new Map();
		this.build();
	}

	defaultConfig() {
		return [
			{ name: 'text', buttons: ['bold', 'italic', 'underline'] },
			{ name: 'paragraph', buttons: ['heading', 'bulletList', 'orderedList', 'blockquote', 'outdent', 'indent'] },
			{ name: 'insert', buttons: ['link', 'image', 'horizontalRule'] },
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
				if (buttonName === 'bulletList' && !this.options.plainLists) {
					groupEl.appendChild(this.buildListDropdown('bulletList', BULLET_STYLES));
					continue;
				}
				if (buttonName === 'orderedList' && !this.options.plainLists) {
					groupEl.appendChild(this.buildListDropdown('orderedList', ORDERED_STYLES));
					continue;
				}
				if (buttonName === 'table') {
					groupEl.appendChild(this.buildTableGridPicker());
					continue;
				}
				if (buttonName === 'textColor') {
					groupEl.appendChild(this.buildColorPicker('textColor', 'Text Color', 'setColor'));
					continue;
				}
				if (buttonName === 'textBgColor') {
					groupEl.appendChild(this.buildColorPicker('textBgColor', 'Background Color', 'setHighlight'));
					continue;
				}
				if (buttonName === 'inlineStyles') {
					const styles = this.options.inlineStyles || DEFAULT_INLINE_STYLES;
					groupEl.appendChild(this.buildInlineDropdown('inlineStyles', 'Inline Styles', styles));
					continue;
				}
				if (buttonName === 'inlineClasses') {
					const classes = this.options.inlineClasses || DEFAULT_INLINE_CLASSES;
					groupEl.appendChild(this.buildInlineDropdown('inlineClasses', 'Inline Classes', classes));
					continue;
				}
				if (buttonName === 'htmlSnippets') {
					const snippets = this.options.htmlSnippets || DEFAULT_HTML_SNIPPETS;
					groupEl.appendChild(this.buildHtmlSnippetDropdown(snippets));
					continue;
				}

				const def = BUTTON_DEFS[buttonName];
				if (!def) continue;
				// A toolbarConfig can name a button whose extension this editor
				// does not load (indent or underline on a styled markdown field);
				// clicking it would call a command that does not exist.
				if (!DELEGATED_COMMANDS.includes(def.command) && !this.editor.commands[def.command]) continue;

				const btn = this.createButton(buttonName, def);
				groupEl.appendChild(btn);
			}

			// Push first item to the right for right-aligned groups
			if (group.align === 'right' && groupEl.firstChild) {
				groupEl.firstChild.style.marginLeft = 'auto';
			}

			this.element.appendChild(groupEl);
		}

		// A table can reach a field whose toolbar has no Table button: pasted
		// in, typed in the code view, or imported. Its controls still need a
		// home, so the Table menu is added anyway and shows only while the
		// cursor is in a table (.ste-table-contextual in styledtext.scss).
		if (!this.buttons.has('table') && this.editor.commands.addRowAfter) {
			const groupEl = document.createElement('div');
			groupEl.className = 'ste-toolbar-group ste-table-contextual';
			groupEl.appendChild(this.buildTableGridPicker());
			this.element.insertBefore(groupEl, this.element.querySelector('.ste-toolbar-group--right'));
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

		const levels = this.options.headingLevels || DEFAULT_HEADING_LEVELS;
		const headingOptions = [
			{ level: 0, label: 'Normal' },
			...levels.map(l => ({ level: l, label: `Heading ${l}` })),
		];

		for (const opt of headingOptions) {
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
		document.addEventListener('click', (e) => this.closeDropdownsOnOutsideClick(e));

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

		document.addEventListener('click', (e) => this.closeDropdownsOnOutsideClick(e));

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

		document.addEventListener('click', (e) => this.closeDropdownsOnOutsideClick(e));

		wrapper.appendChild(toggle);
		wrapper.appendChild(menu);

		this.buttons.set(name, { element: wrapper, type: 'dropdown' });
		return wrapper;
	}

	buildTableGridPicker() {
		const GRID_SIZE = 8;
		const wrapper = document.createElement('div');
		wrapper.className = 'ste-toolbar-dropdown';

		const toggle = document.createElement('button');
		toggle.type = 'button';
		toggle.className = 'ste-toolbar-btn ste-toolbar-dropdown-toggle ste-toolbar-dropdown-toggle--icon';
		toggle.title = 'Table';
		toggle.setAttribute('aria-label', 'Table');
		toggle.style.setProperty('--btn-icon', 'var(--icon-ste-table)');
		toggle.innerHTML = `<span class="ste-caret"></span>`;

		const menu = document.createElement('div');
		menu.className = 'ste-toolbar-dropdown-menu ste-table-grid-menu';

		// Outside a table the menu inserts one; inside a table it holds the
		// row, column and header controls instead (updateTableDropdown). They
		// used to float over the table and covered the cells being typed in:
		// the editor is only as tall as its content, so there was seldom room
		// for them anywhere else.
		const insert = document.createElement('div');
		insert.className = 'ste-table-insert';
		menu.appendChild(insert);

		const label = document.createElement('div');
		label.className = 'ste-table-grid-label';
		label.textContent = 'Insert Table';
		insert.appendChild(label);

		const grid = document.createElement('div');
		grid.className = 'ste-table-grid';
		grid.style.setProperty('--grid-cols', GRID_SIZE);

		const cells = [];
		for (let r = 0; r < GRID_SIZE; r++) {
			for (let c = 0; c < GRID_SIZE; c++) {
				const cell = document.createElement('div');
				cell.className = 'ste-table-grid__cell';
				cell.dataset.row = r + 1;
				cell.dataset.col = c + 1;
				cells.push(cell);
				grid.appendChild(cell);
			}
		}

		const sizeLabel = document.createElement('div');
		sizeLabel.className = 'ste-table-grid-size';
		sizeLabel.textContent = '';

		grid.addEventListener('mouseover', (e) => {
			const cell = e.target.closest('.ste-table-grid__cell');
			if (!cell) return;
			const row = Number(cell.dataset.row);
			const col = Number(cell.dataset.col);
			sizeLabel.textContent = `${row} × ${col}`;
			for (const c of cells) {
				const cr = Number(c.dataset.row);
				const cc = Number(c.dataset.col);
				c.classList.toggle('is-highlighted', cr <= row && cc <= col);
			}
		});

		grid.addEventListener('mouseleave', () => {
			sizeLabel.textContent = '';
			for (const c of cells) c.classList.remove('is-highlighted');
		});

		grid.addEventListener('click', (e) => {
			const cell = e.target.closest('.ste-table-grid__cell');
			if (!cell) return;
			const rows = Number(cell.dataset.row);
			const cols = Number(cell.dataset.col);
			this.editor.chain().focus().insertTable({ rows, cols, withHeaderRow: true }).run();
			this.closeDropdowns();
		});

		insert.appendChild(grid);
		insert.appendChild(sizeLabel);
		menu.appendChild(this.buildTableTools());

		toggle.addEventListener('click', (e) => {
			e.preventDefault();
			e.stopPropagation();
			const isOpen = wrapper.classList.contains('is-open');
			this.closeDropdowns();
			if (!isOpen) wrapper.classList.add('is-open');
		});

		document.addEventListener('click', (e) => this.closeDropdownsOnOutsideClick(e));

		wrapper.appendChild(toggle);
		wrapper.appendChild(menu);

		this.buttons.set('table', { element: wrapper, type: 'dropdown' });
		return wrapper;
	}

	// The controls for the table the cursor is in. The menu stays open after
	// one, so several rows can be added in a row; a click back in the content
	// closes it.
	buildTableTools() {
		const tools = document.createElement('div');
		tools.className = 'ste-table-tools';
		tools.innerHTML = `
			<div class="ste-table-tools__row">
				<span class="ste-table-tools__label">Row</span>
				<button type="button" class="ste-table-tools__btn" data-action="addRowBefore" title="Add row above" style="--btn-icon: var(--icon-ste-add-top)"></button>
				<button type="button" class="ste-table-tools__btn" data-action="addRowAfter" title="Add row below" style="--btn-icon: var(--icon-ste-add-bottom)"></button>
				<button type="button" class="ste-table-tools__btn ste-table-tools__btn--danger" data-action="deleteRow" title="Delete row" style="--btn-icon: var(--icon-ste-trash)"></button>
			</div>
			<div class="ste-table-tools__row">
				<span class="ste-table-tools__label">Col</span>
				<button type="button" class="ste-table-tools__btn" data-action="addColumnBefore" title="Add column left" style="--btn-icon: var(--icon-ste-add-left)"></button>
				<button type="button" class="ste-table-tools__btn" data-action="addColumnAfter" title="Add column right" style="--btn-icon: var(--icon-ste-add-right)"></button>
				<button type="button" class="ste-table-tools__btn ste-table-tools__btn--danger" data-action="deleteColumn" title="Delete column" style="--btn-icon: var(--icon-ste-trash)"></button>
			</div>
			<div class="ste-table-tools__sep"></div>
			<div class="ste-table-tools__row">
				<span class="ste-table-tools__label">Header</span>
				<button type="button" class="ste-table-tools__btn" data-action="toggleHeaderRow" title="Toggle header row" style="--btn-icon: var(--icon-ste-header-row)"></button>
				<button type="button" class="ste-table-tools__btn" data-action="toggleHeaderColumn" title="Toggle header column" style="--btn-icon: var(--icon-ste-header-col)"></button>
			</div>
		`;

		tools.addEventListener('click', (e) => {
			const btn = e.target.closest('[data-action]');
			if (!btn) return;
			e.preventDefault();
			// Each data-action is the name of a Tiptap table command.
			this.editor.chain().focus()[btn.dataset.action]().run();
			// The toolbar refreshes on selection changes, and a header toggle
			// changes the table without moving the selection.
			this.updateTableDropdown(tools.closest('.ste-toolbar-dropdown'));
		});

		return tools;
	}

	buildColorPicker(name, label, command) {
		const wrapper = document.createElement('div');
		wrapper.className = 'ste-toolbar-dropdown';

		const toggle = document.createElement('button');
		toggle.type = 'button';
		toggle.className = 'ste-toolbar-btn ste-toolbar-dropdown-toggle ste-toolbar-dropdown-toggle--icon';
		toggle.title = label;
		toggle.setAttribute('aria-label', label);
		toggle.style.setProperty('--btn-icon', `var(--icon-ste-${ICON_MAP[name]})`);
		toggle.innerHTML = `<span class="ste-caret"></span>`;

		const menu = document.createElement('div');
		menu.className = 'ste-toolbar-dropdown-menu ste-color-picker';

		// Get colors from config or use defaults
		const colorConfig = this.options[name] || {};
		const colors = colorConfig.colors || DEFAULT_COLORS;
		const allowCustom = colorConfig.allowCustom !== false;

		// Color grid
		const grid = document.createElement('div');
		grid.className = 'ste-color-grid';

		for (const color of colors) {
			const swatch = document.createElement('button');
			swatch.type = 'button';
			swatch.className = 'ste-color-swatch';
			swatch.style.backgroundColor = color;
			swatch.title = color;
			swatch.dataset.color = color;

			swatch.addEventListener('click', (e) => {
				e.preventDefault();
				this.dispatchColorCommand(command, color);
				this.closeDropdowns();
			});

			grid.appendChild(swatch);
		}

		menu.appendChild(grid);

		// Custom color picker (system native)
		if (allowCustom) {
			const colorInput = document.createElement('input');
			colorInput.type = 'color';
			colorInput.className = 'ste-color-input';
			colorInput.value = '#000000';
			colorInput.title = 'Custom color';

			colorInput.addEventListener('input', () => {
				this.dispatchColorCommand(command, colorInput.value);
			});

			colorInput.addEventListener('change', () => {
				this.closeDropdowns();
			});

			menu.appendChild(colorInput);
		}

		// Remove color button (eraser icon)
		const removeBtn = document.createElement('button');
		removeBtn.type = 'button';
		removeBtn.className = 'ste-color-remove';
		removeBtn.title = 'Remove';
		removeBtn.setAttribute('aria-label', 'Remove');
		removeBtn.style.setProperty('--btn-icon', 'var(--icon-ste-eraser)');
		removeBtn.addEventListener('click', (e) => {
			e.preventDefault();
			this.dispatchColorCommand(command, null);
			this.closeDropdowns();
		});
		menu.appendChild(removeBtn);

		toggle.addEventListener('click', (e) => {
			e.preventDefault();
			e.stopPropagation();
			const isOpen = wrapper.classList.contains('is-open');
			this.closeDropdowns();
			if (!isOpen) wrapper.classList.add('is-open');
		});

		document.addEventListener('click', (e) => this.closeDropdownsOnOutsideClick(e));

		wrapper.appendChild(toggle);
		wrapper.appendChild(menu);

		this.buttons.set(name, { element: wrapper, type: 'dropdown', colorCommand: command });
		return wrapper;
	}

	buildInlineDropdown(name, label, items) {
		const isStyle = name === 'inlineStyles';
		const wrapper = document.createElement('div');
		wrapper.className = 'ste-toolbar-dropdown';

		const toggle = document.createElement('button');
		toggle.type = 'button';
		toggle.className = 'ste-toolbar-btn ste-toolbar-dropdown-toggle ste-toolbar-dropdown-toggle--icon';
		toggle.title = label;
		toggle.setAttribute('aria-label', label);
		toggle.style.setProperty('--btn-icon', `var(--icon-ste-${ICON_MAP[name]})`);
		toggle.innerHTML = `<span class="ste-caret"></span>`;

		const menu = document.createElement('div');
		menu.className = 'ste-toolbar-dropdown-menu';

		// "Normal" item to remove the mark
		const normalItem = document.createElement('button');
		normalItem.type = 'button';
		normalItem.className = 'ste-toolbar-dropdown-item';
		normalItem.textContent = 'Normal';
		normalItem.addEventListener('click', (e) => {
			e.preventDefault();
			if (isStyle) {
				this.editor.chain().focus().unsetInlineStyle().run();
			} else {
				this.editor.chain().focus().unsetInlineClass().run();
			}
			this.closeDropdowns();
		});
		menu.appendChild(normalItem);

		// User-defined items
		for (const [itemLabel, value] of Object.entries(items)) {
			const item = document.createElement('button');
			item.type = 'button';
			item.className = 'ste-toolbar-dropdown-item';
			item.textContent = itemLabel;
			item.dataset.value = value;

			// Preview the style/class on the label
			if (isStyle) {
				item.setAttribute('style', value);
			} else if (value) {
				item.classList.add(...value.split(/\s+/));
			}

			item.addEventListener('click', (e) => {
				e.preventDefault();
				if (isStyle) {
					this.editor.chain().focus().toggleInlineStyle(value).run();
				} else {
					this.editor.chain().focus().toggleInlineClass(value).run();
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

		document.addEventListener('click', (e) => this.closeDropdownsOnOutsideClick(e));

		wrapper.appendChild(toggle);
		wrapper.appendChild(menu);

		this.buttons.set(name, { element: wrapper, type: 'dropdown', inlineType: isStyle ? 'style' : 'class', items });
		return wrapper;
	}

	buildHtmlSnippetDropdown(snippets) {
		const wrapper = document.createElement('div');
		wrapper.className = 'ste-toolbar-dropdown';

		const toggle = document.createElement('button');
		toggle.type = 'button';
		toggle.className = 'ste-toolbar-btn ste-toolbar-dropdown-toggle ste-toolbar-dropdown-toggle--icon';
		toggle.title = 'HTML Snippets';
		toggle.setAttribute('aria-label', 'HTML Snippets');
		toggle.style.setProperty('--btn-icon', `var(--icon-ste-${ICON_MAP.htmlSnippets})`);
		toggle.innerHTML = `<span class="ste-caret"></span>`;

		const menu = document.createElement('div');
		menu.className = 'ste-toolbar-dropdown-menu';

		for (const [label, template] of Object.entries(snippets)) {
			const item = document.createElement('button');
			item.type = 'button';
			item.className = 'ste-toolbar-dropdown-item';
			item.textContent = label;

			item.addEventListener('click', (e) => {
				e.preventDefault();
				this.insertHtmlSnippet(template, label);
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

		document.addEventListener('click', (e) => this.closeDropdownsOnOutsideClick(e));

		wrapper.appendChild(toggle);
		wrapper.appendChild(menu);

		this.buttons.set('htmlSnippets', { element: wrapper, type: 'dropdown' });
		return wrapper;
	}

	insertHtmlSnippet(template, label) {
		if (typeof template !== 'string') return;

		const BLOCK_TAGS = [
			'div', 'section', 'aside', 'article', 'nav', 'header', 'footer',
			'main', 'figure', 'details', 'blockquote', 'pre', 'table',
		];

		// Detect if the snippet is block-level by checking the opening tag
		const tagMatch = template.match(/^<(\w+)/);
		const tagName = tagMatch ? tagMatch[1].toLowerCase() : '';
		const isBlock = BLOCK_TAGS.includes(tagName);

		// Add data-label to the first opening tag
		const htmlTemplate = template.replace(/^<(\w+)/, `<$1 data-label="${label}"`);

		const { from, to } = this.editor.state.selection;
		const hasSelection = from !== to;

		if (hasSelection && isBlock) {
			// Block with selection: serialize selected content preserving formatting
			const slice = this.editor.state.selection.content();
			const serializer = DOMSerializer.fromSchema(this.editor.schema);
			const fragment = serializer.serializeFragment(slice.content);
			const div = document.createElement('div');
			div.appendChild(fragment);
			const selectedHtml = div.innerHTML;

			const html = htmlTemplate.replace(/\{content\}/g, selectedHtml);
			this.editor.chain().focus().deleteSelection().insertContent(html).run();
		} else if (hasSelection) {
			// Inline with selection: replace in-place to stay within the paragraph
			const selectedText = this.editor.state.doc.textBetween(from, to);
			const html = htmlTemplate.replace(/\{content\}/g, selectedText);
			this.editor.chain().focus().insertContentAt({ from, to }, html).run();
		} else if (isBlock) {
			// Block without selection: insert with empty paragraph for cursor
			const html = htmlTemplate.replace(/\{content\}/g, '<p></p>');
			this.editor.chain().focus().insertContent(html).run();
		} else {
			// Inline without selection: insert with zero-width space
			const html = htmlTemplate.replace(/\{content\}/g, '\u200B');
			this.editor.chain().focus().insertContent(html).run();
		}
	}

	dispatchColorCommand(command, color) {
		this.element.dispatchEvent(new CustomEvent('toolbar-command', {
			detail: { command, args: color },
			bubbles: true,
		}));
	}

	closeDropdowns() {
		this.element.querySelectorAll('.ste-toolbar-dropdown.is-open')
			.forEach(el => el.classList.remove('is-open'));
	}

	// Outside-click closer for dropdown menus. A click that originates INSIDE a
	// dropdown must not close it here — most importantly the native
	// <input type="color"> custom-color button: its click bubbles to document,
	// and closing the menu tears down the input the OS color picker is anchored
	// to, so the picker flashes open and immediately vanishes. Toggles already
	// stopPropagation; swatches and the eraser close the menu via their own
	// handlers after acting.
	closeDropdownsOnOutsideClick(e) {
		const target = e.target;
		if (target instanceof Element && target.closest('.ste-toolbar-dropdown')) {
			return;
		}
		this.closeDropdowns();
	}

	executeCommand(def) {
		// The editor can be torn down (e.g. a deck/dialog styledtext field
		// closing) while a toolbar click is still in flight; reading
		// editor.commands then throws "null is not an object (commandManager.
		// commands)". Bail when the editor is gone or destroyed.
		if (!this.editor || this.editor.isDestroyed) {
			return;
		}

		const { command, args } = def;

		if (DELEGATED_COMMANDS.includes(command)) {
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
				if (name === 'textColor' || name === 'textBgColor') this.updateColorPicker(name, entry);
				if (name === 'inlineStyles' || name === 'inlineClasses') this.updateInlineDropdown(name, entry);
				if (name === 'table') this.updateTableDropdown(entry.element);
				continue;
			}

			const { element: btn } = entry;
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
				// Plain list buttons (the markdown toolbar); the list dropdowns are handled above.
				case 'inlineCode':     isActive = this.editor.isActive('code'); break;
				case 'bulletList':     isActive = this.editor.isActive('bulletList'); break;
				case 'orderedList':    isActive = this.editor.isActive('orderedList'); break;
			}

			// A link needs a selection (or an existing link to edit). Disable the
			// button when there's nothing to act on, so it isn't a dead click that
			// opens a dialog which can't apply anything.
			if (name === 'link') {
				btn.disabled = this.editor.state.selection.empty && !isActive;
			}

			btn.classList.toggle('is-active', isActive);
		}
	}

	// Inside a table the Table menu holds that table's controls, and the
	// button is lit so it reads as the place to find them.
	updateTableDropdown(wrapper) {
		const inTable = this.editor.isActive('table');
		wrapper.classList.toggle('in-table', inTable);
		wrapper.querySelector('.ste-toolbar-dropdown-toggle')?.classList.toggle('is-active', inTable);
		if (!inTable) return;

		const rowBtn = wrapper.querySelector('[data-action="toggleHeaderRow"]');
		const colBtn = wrapper.querySelector('[data-action="toggleHeaderColumn"]');
		const { $from } = this.editor.state.selection;

		for (let d = $from.depth; d >= 0; d--) {
			const table = $from.node(d);
			if (table.type.name !== 'table') continue;
			// Guard every child() access: while ProseMirror reconciles a DOM
			// change, this can run against a transient state where a table or
			// row momentarily has no children, and child(0) would throw.
			if (table.childCount === 0) break;

			// Header row: the first row's first cell is a header.
			const firstRow = table.child(0);
			if (rowBtn && firstRow.childCount > 0) {
				rowBtn.classList.toggle('is-active', firstRow.child(0).type.name === 'tableHeader');
			}
			// Header column: the second row's first cell is a header.
			if (colBtn && table.childCount > 1 && table.child(1).childCount > 0) {
				colBtn.classList.toggle('is-active', table.child(1).child(0).type.name === 'tableHeader');
			}
			break;
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

	updateInlineDropdown(name, entry) {
		const markName = name === 'inlineStyles' ? 'inlineStyle' : 'inlineClass';
		const attrName = name === 'inlineStyles' ? 'style' : 'class';
		const isActive = this.editor.isActive(markName);
		const currentValue = this.editor.getAttributes(markName)[attrName] || '';

		const items = entry.element.querySelectorAll('.ste-toolbar-dropdown-item');
		items.forEach(item => {
			const value = item.dataset.value;
			if (value === undefined) {
				// "Normal" item — active when mark is not set
				item.classList.toggle('is-active', !isActive);
			} else {
				item.classList.toggle('is-active', currentValue === value);
			}
		});

		const toggle = entry.element.querySelector('.ste-toolbar-dropdown-toggle');
		if (toggle) toggle.classList.toggle('is-active', isActive);
	}

	updateColorPicker(name, entry) {
		const toggle = entry.element.querySelector('.ste-toolbar-dropdown-toggle');
		if (!toggle) return;

		if (name === 'textColor') {
			toggle.classList.toggle('is-active', !!this.editor.getAttributes('textStyle').color);
		} else {
			toggle.classList.toggle('is-active', this.editor.isActive('highlight'));
		}
	}

	destroy() {
		this.buttons.clear();
		this.element?.remove();
		this.element = null;
	}
}
