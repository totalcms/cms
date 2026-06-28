import ListField from '../../javascript/totalform/list.js';

//-----------------------------------------------
// ListField is Choices.js-backed. Regression target: setValue() repopulates the
// field (the gallery's shared edit dialog calls it for every image). It used to
// call choices.clearStore(), which wipes the whole choice pool — so any
// propertyOptions-driven suggestions vanished after the first image. The fix
// clears only the selection (removeActiveItems) and re-selects, preserving the
// suggestion pool.
//-----------------------------------------------

function makeListField(options) {
	document.body.innerHTML = '';
	const container = document.createElement('div');
	container.className = 'form-field';
	const select = document.createElement('select');
	select.name = 'tags';
	select.setAttribute('multiple', '');
	for (const o of options) {
		const opt = document.createElement('option');
		opt.value = o;
		opt.textContent = o;
		select.appendChild(opt);
	}
	container.appendChild(select);
	document.body.appendChild(container);
	return new ListField(container, { form: {} });
}

// Available (unselected) suggestions still in the pool.
function available(field) {
	return field.choices._store.choices.filter((c) => !c.selected).map((c) => c.value);
}

describe('ListField', () => {
	test('setValue selects the given values without wiping the suggestion pool', () => {
		const field = makeListField(['a', 'b', 'c', 'd', 'e']);

		field.setValue(['b']);

		expect(field.getValue()).toEqual(['b']);
		expect(available(field).sort()).toEqual(['a', 'c', 'd', 'e']);
	});

	test('repopulating for another image swaps the selection and keeps suggestions', () => {
		const field = makeListField(['a', 'b', 'c', 'd', 'e']);

		field.setValue(['b']);      // image 1
		field.setValue(['d', 'e']); // switch to image 2 (gallery shared dialog)

		expect(field.getValue().sort()).toEqual(['d', 'e']);
		// The previously-selected 'b' returns to the pool; suggestions are intact.
		expect(available(field).sort()).toEqual(['a', 'b', 'c']);
	});
});
