/**
 * MarkdownToolbar - the toolbar of the markdown source editor.
 *
 * It uses the styledtext toolbar's markup, classes and icons, but none of its
 * code: TiptapToolbar drives a Tiptap editor directly. This one only reports
 * which button was pressed.
 */

/* name -> label and --icon-ste-* suffix */
const BUTTONS = {
	bold:           { label: 'Bold',            icon: 'bold' },
	italic:         { label: 'Italic',          icon: 'italic' },
	strike:         { label: 'Strikethrough',   icon: 'strikethrough' },
	inlineCode:     { label: 'Inline Code',     icon: 'inline-code' },
	bulletList:     { label: 'Bullet List',     icon: 'unordered-list' },
	orderedList:    { label: 'Ordered List',    icon: 'ordered-list' },
	blockquote:     { label: 'Blockquote',      icon: 'blockquote' },
	codeBlock:      { label: 'Code Block',      icon: 'code' },
	horizontalRule: { label: 'Horizontal Rule', icon: 'hr' },
	link:           { label: 'Link',            icon: 'link' },
	image:          { label: 'Image',           icon: 'image' },
	file:           { label: 'File',            icon: 'file' },
	table:          { label: 'Table',           icon: 'table' },
	preview:        { label: 'Preview',         icon: 'preview' },
	fullscreen:     { label: 'Fullscreen',      icon: 'fullscreen' },
};

// These change the view, so they stay usable while the source is hidden.
const VIEW_BUTTONS = ['preview', 'fullscreen'];

const DEFAULT_HEADING_LEVELS = [2, 3, 4];

export default class MarkdownToolbar {

	constructor(config, { headingLevels, onCommand } = {}) {
		this.config        = config;
		this.headingLevels = headingLevels || DEFAULT_HEADING_LEVELS;
		this.onCommand     = onCommand || (() => {});
		this.buttons       = new Map();
		this.dropdown      = null;
		this.closeOnOutsideClick = (event) => {
			if (this.dropdown && !this.dropdown.contains(event.target)) this.dropdown.classList.remove('is-open');
		};

		this.build();
		document.addEventListener('click', this.closeOnOutsideClick);
	}

	build() {
		this.element = document.createElement('div');
		this.element.className = 'ste-toolbar';

		for (const group of this.config) {
			const groupEl = document.createElement('div');
			groupEl.className = 'ste-toolbar-group';
			if (group.align === 'right') groupEl.classList.add('ste-toolbar-group--right');

			for (const name of group.buttons) {
				if (name === 'heading') {
					groupEl.appendChild(this.buildHeadingDropdown());
				} else if (BUTTONS[name]) {
					groupEl.appendChild(this.buildButton(name, BUTTONS[name]));
				}
			}

			// Push first item to the right for right-aligned groups
			if (group.align === 'right' && groupEl.firstChild) {
				groupEl.firstChild.style.marginLeft = 'auto';
			}

			this.element.appendChild(groupEl);
		}
	}

	buildButton(name, { label, icon }) {
		const button = document.createElement('button');
		button.type = 'button';
		button.className = 'ste-toolbar-btn';
		button.dataset.command = name;
		button.title = label;
		button.setAttribute('aria-label', label);
		button.style.setProperty('--btn-icon', `var(--icon-ste-${icon})`);

		button.addEventListener('click', (event) => {
			event.preventDefault();
			this.onCommand(name);
		});

		this.buttons.set(name, button);

		return button;
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
		toggle.innerHTML = '<span class="ste-caret"></span>';

		const menu = document.createElement('div');
		menu.className = 'ste-toolbar-dropdown-menu';

		const options = [
			{ level: 0, label: 'Normal' },
			...this.headingLevels.map((level) => ({ level, label: `Heading ${level}` })),
		];

		for (const option of options) {
			const item = document.createElement('button');
			item.type = 'button';
			item.className = 'ste-toolbar-dropdown-item';
			item.textContent = option.label;
			item.dataset.level = option.level;

			item.addEventListener('click', (event) => {
				event.preventDefault();
				wrapper.classList.remove('is-open');
				this.onCommand('heading', { level: option.level });
			});

			menu.appendChild(item);
		}

		toggle.addEventListener('click', (event) => {
			event.preventDefault();
			event.stopPropagation();
			wrapper.classList.toggle('is-open');
		});

		wrapper.appendChild(toggle);
		wrapper.appendChild(menu);

		this.dropdown = wrapper;
		this.buttons.set('heading', toggle);

		return wrapper;
	}

	setActive(name, on) {
		this.buttons.get(name)?.classList.toggle('is-active', on);
	}

	// Disable the buttons that edit the source; the view buttons stay.
	setLocked(locked) {
		for (const [name, button] of this.buttons) {
			button.disabled = locked && !VIEW_BUTTONS.includes(name);
		}
		if (locked) this.dropdown?.classList.remove('is-open');
	}

	destroy() {
		document.removeEventListener('click', this.closeOnOutsideClick);
		this.element.remove();
	}
}
