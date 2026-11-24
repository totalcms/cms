import TotalField from './totalfield';

let drawn = 0;

/**
 * Draw a multiselect as a list of rows, one click to add or remove each.
 *
 * The browser's own list box wants Cmd, Ctrl or Shift held down to pick more
 * than one, and a plain click throws away everything else that was picked.
 * It also cannot be styled: Safari draws it itself and takes no row height,
 * spacing or tick from a stylesheet.
 *
 * So the <select> stays in the page as the field's value, out of sight, and
 * the form draws the list: a row for each option, a heading for each group.
 * A click, Space or Enter adds or removes a row and leaves the rest alone.
 *
 * @param {HTMLSelectElement} select
 * @returns {{sync: () => void, render: () => void}} `sync` after picking options in code; `render` after changing them
 */
export function drawList(select) {
	if (select.multiselectList) {
		return select.multiselectList;
	}

	const box = document.createElement('div');
	const uid = `multiselect-${++drawn}`;
	box.id = `${uid}-list`;
	box.className = 'multiselect-list';
	box.setAttribute('role', 'listbox');
	box.setAttribute('aria-multiselectable', 'true');

	const label = select.labels?.[0];
	if (label) {
		label.id ||= `${uid}-label`;
		box.setAttribute('aria-labelledby', label.id);
		// The label names the <select>, which cannot take focus any more.
		label.addEventListener('click', (event) => {
			event.preventDefault();
			box.focus();
		});
	}

	select.classList.add('multiselect-native');
	select.tabIndex = -1;
	select.setAttribute('aria-hidden', 'true');
	// In front of the <select>, not after it: the form's styles choose the
	// field's icon by what follows the <select>, and that must not change.
	select.before(box);

	/** @type {Map<HTMLElement, HTMLOptionElement>} */
	let options = new Map();
	let active  = null;

	const rows = () => [...options.keys()];

	const sync = () => {
		box.tabIndex = select.disabled ? -1 : 0;
		box.setAttribute('aria-disabled', String(select.disabled));
		for (const [row, option] of options) {
			row.setAttribute('aria-selected', String(option.selected));
			row.setAttribute('aria-disabled', String(option.disabled));
		}
	};

	const setActive = (row) => {
		active?.classList.remove('is-active');
		active = row ?? null;
		if (!active) {
			box.removeAttribute('aria-activedescendant');
			return;
		}
		active.classList.add('is-active');
		box.setAttribute('aria-activedescendant', active.id);
		active.scrollIntoView?.({ block: 'nearest' });
	};

	const render = () => {
		const kept = active ? options.get(active) : null;
		options = new Map();
		box.replaceChildren();

		const addRow = (option) => {
			// A placeholder is a hint for a list that drops down, not a choice.
			if (option.disabled && option.value === '') {
				return;
			}
			const row = document.createElement('div');
			row.id = `${uid}-option-${options.size}`;
			row.className = 'multiselect-option';
			row.setAttribute('role', 'option');
			row.textContent = option.label || option.textContent;
			options.set(row, option);
			box.appendChild(row);
		};

		for (const child of select.children) {
			if (child instanceof HTMLOptGroupElement) {
				const heading = document.createElement('div');
				heading.className = 'multiselect-group';
				heading.setAttribute('role', 'presentation');
				heading.textContent = child.label;
				box.appendChild(heading);
				[...child.children].forEach((option) => addRow(option));
			} else if (child instanceof HTMLOptionElement) {
				addRow(child);
			}
		}

		sync();
		setActive(rows().find((row) => options.get(row) === kept) ?? null);
	};

	const toggle = (row) => {
		const option = options.get(row);
		if (!option || option.disabled || select.disabled) {
			return;
		}
		option.selected = !option.selected;
		sync();
		// What the field, and the form's unsaved warning, listen for.
		select.dispatchEvent(new Event('change', { bubbles: true }));
	};

	box.addEventListener('click', (event) => {
		const row = event.target instanceof Element ? event.target.closest('[role="option"]') : null;
		if (row) {
			setActive(row);
			toggle(row);
		}
	});

	box.addEventListener('keydown', (event) => {
		const all = rows();
		const at  = all.indexOf(active);
		const to  = {
			ArrowDown : Math.min(all.length - 1, at + 1),
			ArrowUp   : Math.max(0, at - 1),
			Home      : 0,
			End       : all.length - 1,
		}[event.key];

		if (to !== undefined) {
			event.preventDefault();
			setActive(all[to]);
		} else if (event.key === ' ' || event.key === 'Enter') {
			event.preventDefault();
			if (active) {
				toggle(active);
			}
		}
	});

	// A value set on the <select> by anything else, and options that come or go.
	select.addEventListener('change', sync);
	new MutationObserver(render).observe(select, { childList: true, subtree: true, attributes: true, attributeFilter: ['disabled', 'label'] });

	render();
	select.multiselectList = { sync, render };

	return select.multiselectList;
}

//-----------------------------------------------
// Total CMS MultiSelect Field
//-----------------------------------------------
export default class MultiSelectField extends TotalField {

    constructor(container, settings) {
        super(...arguments);
        this.initList();
    }

    /**
     * Whether this field is shown as the list of rows drawn here. A field
     * built on this one that shows its options another way says no: the list
     * field is a box of tags with a search, drawn by its own library.
     */
    drawsList() {
        return true;
    }

    initList() {
        this.list = this.drawsList() ? drawList(this.input) : null;
    }

    getValue() {
        const data = [];
        // We have to grab each selected option and put them into an array.
        const options = this.input.querySelectorAll("option");
        for (const option of options) {
            if (option.selected) data.push(option.value);
        }
        // return array of data
        return data;
    }

    setValue(value) {
        if (typeof value !== "object") {
            console.error(`Unable to set value for multiselect: ${this.form.id}`);
        }
        // Select Options
        const options = Array.from(this.input.getElementsByTagName("option"));
        for (const option of options) {
			option.selected = (value.indexOf(option.value)>=0);
        }
        this.list?.sync();
        this.changed();
    }

	clearValue() {
		this.setValue([]);
	}

    schema() {
        return {
            "type":"array",
            "fieldset":"multiselect"
        };
    }
}
