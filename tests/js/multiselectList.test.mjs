import MultiSelectField, { drawList } from '../../javascript/totalform/multiselect.js';
import ListField from '../../javascript/totalform/list.js';

//-----------------------------------------------
// A multiselect is drawn by the form, not by the browser. The browser's own
// list box wants a key held down to pick more than one, and cannot be given
// taller rows or a tick. The <select> stays in the page as the field's
// value; what is shown is a list of rows, each one click to add or remove.
//-----------------------------------------------

function field(html = '<option value="a" selected>Alpha</option><option value="b">Bravo</option><option value="c">Charlie</option>') {
	document.body.innerHTML = `<div class="form-field multiselect-field"><label for="ms">Groups</label><div class="form-group"><select id="ms" multiple>${html}</select></div></div>`;
	const select = document.getElementById('ms');
	const list   = drawList(select);

	return { select, list, box: document.querySelector('.multiselect-list') };
}

const rows   = (box) => [...box.querySelectorAll('[role="option"]')];
const picked = (select) => [...select.options].filter((o) => o.selected).map((o) => o.value);
const ticked = (box) => rows(box).filter((r) => r.getAttribute('aria-selected') === 'true').map((r) => r.textContent.trim());
const press  = (box, key) => box.dispatchEvent(new KeyboardEvent('keydown', { key, bubbles: true, cancelable: true }));
const settle = () => new Promise((done) => setTimeout(done, 0));

describe('what is drawn', () => {
	test('a row for each option, ticked where the option is picked', () => {
		const { box } = field();

		expect(rows(box).map((r) => r.textContent.trim())).toEqual(['Alpha', 'Bravo', 'Charlie']);
		expect(ticked(box)).toEqual(['Alpha']);
	});

	test('the list is a list box that takes several, named by the field\'s label', () => {
		const { box } = field();

		expect(box.getAttribute('role')).toBe('listbox');
		expect(box.getAttribute('aria-multiselectable')).toBe('true');
		expect(document.getElementById(box.getAttribute('aria-labelledby')).textContent).toBe('Groups');
		expect(box.tabIndex).toBe(0);
	});

	test('the browser\'s own list is kept as the value, out of sight and out of the tab order', () => {
		const { select } = field();

		expect(select.classList.contains('multiselect-native')).toBe(true);
		expect(select.tabIndex).toBe(-1);
		expect(select.getAttribute('aria-hidden')).toBe('true');
	});

	// The form's styles pick a field's icon by what follows its <select>
	// (`select + .form-group-icon`), so nothing may come between the two.
	test('the list goes in front of the <select>, so what followed it still does', () => {
		document.body.innerHTML = '<div class="form-field"><div class="form-group"><select id="ms" multiple><option value="a">Alpha</option></select><div class="form-group-icon"></div></div></div>';
		const select = document.getElementById('ms');

		drawList(select);

		expect(select.nextElementSibling.className).toBe('form-group-icon');
		expect(select.previousElementSibling.className).toBe('multiselect-list');
	});

	test('a group heading is shown and cannot be picked', () => {
		const { box, select } = field('<optgroup label="Europe"><option value="de">Germany</option></optgroup><option value="us">United States</option>');

		const heading = box.querySelector('.multiselect-group');
		expect(heading.textContent).toBe('Europe');
		heading.click();
		expect(picked(select)).toEqual([]);
		expect(rows(box).map((r) => r.textContent.trim())).toEqual(['Germany', 'United States']);
	});

	test('a placeholder option is not a row', () => {
		const { box } = field('<option value="" disabled>Choose</option><option value="a">Alpha</option>');

		expect(rows(box).map((r) => r.textContent.trim())).toEqual(['Alpha']);
	});
});

describe('a click on a row', () => {
	test('adds it and keeps what was picked', () => {
		const { box, select } = field();

		rows(box)[2].click();

		expect(picked(select)).toEqual(['a', 'c']);
		expect(ticked(box)).toEqual(['Alpha', 'Charlie']);
	});

	test('takes off one that was picked', () => {
		const { box, select } = field();

		rows(box)[0].click();

		expect(picked(select)).toEqual([]);
		expect(ticked(box)).toEqual([]);
	});

	test('tells the form the field changed', () => {
		const { box, select } = field();
		const changes = [];
		document.querySelector('.form-field').addEventListener('change', () => changes.push(picked(select)));

		rows(box)[1].click();

		expect(changes).toEqual([['a', 'b']]);
	});

	test('does nothing on a disabled option, or in a disabled field', () => {
		const { box, select } = field('<option value="a">Alpha</option><option value="b" disabled>Bravo</option>');
		rows(box)[1].click();
		expect(picked(select)).toEqual([]);
		expect(rows(box)[1].getAttribute('aria-disabled')).toBe('true');

		const off = field();
		off.select.disabled = true;
		rows(off.box)[2].click();
		expect(picked(off.select)).toEqual(['a']);
	});
});

describe('the keyboard', () => {
	test('arrows move along the rows, and Space adds or removes the one they are on', () => {
		const { box, select } = field();

		press(box, 'ArrowDown');
		press(box, 'ArrowDown');
		press(box, ' ');

		expect(picked(select)).toEqual(['a', 'b']);
		expect(document.getElementById(box.getAttribute('aria-activedescendant')).textContent.trim()).toBe('Bravo');
	});

	test('Home and End go to the first and last row, and Enter picks like Space', () => {
		const { box, select } = field();

		press(box, 'End');
		press(box, 'Enter');
		press(box, 'Home');
		press(box, 'Enter');

		expect(picked(select)).toEqual(['c']);
	});

	test('the arrows stop at each end', () => {
		const { box } = field();

		press(box, 'ArrowUp');
		expect(document.getElementById(box.getAttribute('aria-activedescendant')).textContent.trim()).toBe('Alpha');

		press(box, 'End');
		press(box, 'ArrowDown');
		expect(document.getElementById(box.getAttribute('aria-activedescendant')).textContent.trim()).toBe('Charlie');
	});

	test('a key the list has no use for is left alone', () => {
		const { box } = field();
		const event = new KeyboardEvent('keydown', { key: 'Tab', bubbles: true, cancelable: true });

		box.dispatchEvent(event);

		expect(event.defaultPrevented).toBe(false);
	});
});

describe('keeping up with the field', () => {
	test('a value set by the form shows in the rows', () => {
		const { box, select, list } = field();

		select.options[0].selected = false;
		select.options[1].selected = true;
		list.sync();

		expect(ticked(box)).toEqual(['Bravo']);
	});

	test('a change made to the <select> by anything else shows too', () => {
		const { box, select } = field();

		select.options[2].selected = true;
		select.dispatchEvent(new Event('change', { bubbles: true }));

		expect(ticked(box)).toEqual(['Alpha', 'Charlie']);
	});

	test('options added later get rows', async () => {
		const { box, select } = field();

		const option = document.createElement('option');
		option.value = 'd';
		option.textContent = 'Delta';
		select.appendChild(option);
		await settle();

		expect(rows(box).map((r) => r.textContent.trim())).toEqual(['Alpha', 'Bravo', 'Charlie', 'Delta']);
	});

	test('drawing twice leaves one list', () => {
		const { select } = field();

		drawList(select);

		expect(document.querySelectorAll('.multiselect-list')).toHaveLength(1);
	});
});

// The list field is built on the multiselect field, and draws itself another
// way: a box of tags with a search. It must not be given this list as well.
describe('which fields draw the list', () => {
	function fieldOf(Type) {
		document.body.innerHTML = '<div class="form-field"><div class="form-group"><select id="ms" multiple><option value="a">Alpha</option></select><div class="form-group-icon"></div></div></div>';
		const instance = Object.create(Type.prototype);
		instance.input = document.getElementById('ms');
		instance.initList();

		return instance;
	}

	test('a multiselect does', () => {
		const instance = fieldOf(MultiSelectField);

		expect(document.querySelectorAll('.multiselect-list')).toHaveLength(1);
		expect(instance.list).not.toBeNull();
	});

	test('a list field does not, and its <select> is left as it was', () => {
		const instance = fieldOf(ListField);
		const select   = document.getElementById('ms');

		expect(document.querySelectorAll('.multiselect-list')).toHaveLength(0);
		expect(instance.list).toBeNull();
		expect(select.classList.contains('multiselect-native')).toBe(false);
		expect(select.hasAttribute('aria-hidden')).toBe(false);
		expect(select.tabIndex).not.toBe(-1);
	});
});
