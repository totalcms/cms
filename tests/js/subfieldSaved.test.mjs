import TotalField from '../../javascript/totalform/totalfield.js';
import TotalDispatcher from '../../javascript/totalform/dispatcher.js';
import DeckTableField from '../../javascript/totalform/deckTable.js';
import ImagePreview from '../../javascript/totalform/image-preview.js';
import FilePreview from '../../javascript/totalform/file-preview.js';

//-----------------------------------------------
// Deleting an image or file, editing its info (autosaved on dialog close), or
// starring it as featured all persist by their own request. The field marks
// itself saved afterwards — but the composite it sits in (a deck-table row, a
// deck item, a card) had already been marked unsaved by the edits that led
// there, and nothing told it the work was done. So the form stayed dirty,
// asked "Leave site?", and re-sent the field on the next Save.
//
// Two halves: a field that is marked saved announces it (`subfield-saved`,
// bubbling), and a composite that hears it drops its own unsaved flag and
// moves its baseline when no descendant is still unsaved. The delete path
// also clears the sub-fields silently, so it never dirties anything to begin
// with.
//-----------------------------------------------

function subField(value) {
	const parent = document.createElement('div');
	parent.className = 'form-field';
	const container = document.createElement('div');
	container.className = 'form-field unsaved';
	const input = document.createElement('input');
	input.name = 'alt';
	container.appendChild(input);
	parent.appendChild(container);
	document.body.appendChild(parent);

	const f = Object.create(TotalField.prototype);
	f.container = container;
	f.input = input;
	f.dispatcher = new TotalDispatcher(container);
	f.getValue = () => value;
	f.isHidden = () => false;
	f.isSubField = () => true; // nesting detection has its own rules; the announcement is what is under test
	return { f, parent };
}

describe('TotalField.saved()', () => {
	test('announces subfield-saved to its ancestors', () => {
		// The dispatcher debounces every event by 300ms, so the announcement
		// lands on the next tick; the flag comes off at once.
		vi.useFakeTimers();
		const { f, parent } = subField('x');
		const heard = [];
		parent.addEventListener('subfield-saved', e => heard.push(e.detail.field));

		f.saved();
		expect(f.container.classList.contains('unsaved')).toBe(false);
		expect(heard).toEqual([]);

		vi.runAllTimers();
		expect(heard).toEqual([f]);
		vi.useRealTimers();
	});
});

function deckTable() {
	const container = document.createElement('div');
	container.className = 'form-field unsaved';
	container.dataset.type = 'deckTable';
	const body = document.createElement('div');
	body.className = 'deck-table-body';
	container.appendChild(body);
	document.body.appendChild(container);

	const field = Object.create(DeckTableField.prototype);
	field.container = container;
	field.tableBody = body;
	field.input = document.createElement('input');
	field.value = { r1: { id: 'r1', photo: { name: 'old.jpg' } } };
	field.getValue = () => field.value;
	field.storedValue = { r1: { id: 'r1', photo: { name: 'old.jpg' } } };
	field.listenForSubFieldSaves();
	return field;
}

function rowWithChild(field, unsavedSibling = false) {
	const row = document.createElement('div');
	row.className = 'deck-table-row';
	const child = document.createElement('div');
	child.className = 'form-field';
	child.dataset.type = 'image';
	row.appendChild(child);
	if (unsavedSibling) {
		const sib = document.createElement('div');
		sib.className = 'form-field unsaved';
		row.appendChild(sib);
	}
	field.tableBody.appendChild(row);
	return child;
}

describe('composite fields hearing subfield-saved', () => {
	test('a deck table drops its unsaved flag and moves its baseline once nothing below is unsaved', () => {
		const field = deckTable();
		const child = rowWithChild(field);
		field.value = { r1: { id: 'r1', photo: {} } }; // the image was deleted, by its own request

		child.dispatchEvent(new CustomEvent('subfield-saved', { bubbles: true, detail: {} }));

		expect(field.container.classList.contains('unsaved')).toBe(false);
		expect(field.storedValue).toEqual({ r1: { id: 'r1', photo: {} } });
		expect(field.isUnsaved()).toBe(false);
	});

	test('a deck table stays unsaved while a sibling still has unsaved edits', () => {
		const field = deckTable();
		const child = rowWithChild(field, true);
		const baseline = field.storedValue;

		child.dispatchEvent(new CustomEvent('subfield-saved', { bubbles: true, detail: {} }));

		expect(field.container.classList.contains('unsaved')).toBe(true);
		expect(field.storedValue).toBe(baseline);
	});

	test('ignores its own saved announcement', () => {
		const field = deckTable();
		field.container.classList.add('unsaved');
		field.container.dispatchEvent(new CustomEvent('subfield-saved', { bubbles: true, detail: {} }));
		expect(field.container.classList.contains('unsaved')).toBe(true);
	});
});

function previewOf(Klass) {
	const p = Object.create(Klass.prototype);
	const calls = [];
	const sub = name => ({ totalfield: {
		property: name,
		setSavedValue: v => calls.push(['setSavedValue', name, v]),
		clearValue: () => calls.push(['clearValue', name]),
		setValue: v => calls.push(['setValue', name, v]),
	} });
	p.fields = [sub('name'), sub('alt')];
	p.totalfield = { saved: () => calls.push(['field.saved']) };
	return { p, calls };
}

describe('clearing a deleted file', () => {
	test.each([['ImagePreview', ImagePreview], ['FilePreview', FilePreview]])('%s.clearValue() clears silently and marks the field saved', (_, Klass) => {
		const { p, calls } = previewOf(Klass);
		p.clearValue();

		// No setValue/clearValue (those dispatch subfield-change and dirty the parent).
		expect(calls.filter(c => c[0] === 'setValue' || c[0] === 'clearValue')).toEqual([]);
		expect(calls.filter(c => c[0] === 'setSavedValue').map(c => c[1])).toEqual(['name', 'alt']);
		expect(calls.at(-1)).toEqual(['field.saved']);
	});
});

describe('filling a preview from the server', () => {
	// A fresh upload answers with `count: 0`. `0||""` blanked the number
	// input, which serialized as null and failed the parent's next save.
	test('FilePreview.setValue() keeps zero and false', () => {
		const { p, calls } = previewOf(FilePreview);
		p.updatePreview = () => {};
		p.fields = ['count', 'protected', 'alt'].map(name => ({ totalfield: {
			property: name,
			setValue: v => calls.push([name, v]),
			saved: () => {},
		} }));
		p.setValue({ count: 0, protected: false });

		expect(calls).toEqual([['count', 0], ['protected', false], ['alt', '']]);
	});
});
