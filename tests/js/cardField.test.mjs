import CardField from '../../javascript/totalform/card.js';

//-----------------------------------------------
// CardField.getValue() must collect ONLY the card's own top-level sub-fields,
// not the internal sub-fields of composite children (an image's alt/name, a
// file's name/ext). Those live inside the child's own .form-field within the
// card's .card-fields, so without an isNestedField() guard they flatten into
// the card and collide with its properties — e.g. a file's `name` clobbering
// the card's own `name`, which then reverts on refresh.
//-----------------------------------------------

// Build a card containing a name field plus an image and a file composite, each
// with internal sub-fields (including their own `name`) that must NOT leak.
function buildCard() {
	document.body.innerHTML = '';

	const field = (type, name) => {
		const el = document.createElement('div');
		el.className = 'form-field';
		el.dataset.type = type;
		const input = document.createElement('input');
		input.name = name;
		el.appendChild(input);
		return el;
	};
	const wire = (el, property, value) => { el.totalfield = { property, getValue: () => value }; return el; };

	const card = field('card', 'mycard'); // card container (its own input must be first)
	const cardFields = document.createElement('div');
	cardFields.className = 'card-fields';
	card.appendChild(cardFields);

	cardFields.appendChild(wire(field('text', 'id'), 'id', 'card1'));
	cardFields.appendChild(wire(field('text', 'name'), 'name', 'My Card'));

	const image = wire(field('image', 'image'), 'image', { name: 'pic.jpg', alt: 'astro' });
	const imageMeta = document.createElement('details');
	imageMeta.appendChild(wire(field('text', 'alt'), 'alt', 'astro'));     // internal — must skip
	imageMeta.appendChild(wire(field('text', 'name'), 'name', 'pic.jpg')); // internal — would clobber card.name
	image.appendChild(imageMeta);
	cardFields.appendChild(image);

	const fileField = wire(field('file', 'file'), 'file', { name: 'cms-data.zip' });
	const fileMeta = document.createElement('details');
	fileMeta.appendChild(wire(field('text', 'name'), 'name', 'cms-data.zip')); // internal — would clobber card.name
	fileMeta.appendChild(wire(field('text', 'ext'), 'ext', 'zip'));            // internal — would leak
	fileField.appendChild(fileMeta);
	cardFields.appendChild(fileField);

	document.body.appendChild(card);
	return new CardField(card, { form: { form: document.body } });
}

describe('CardField', () => {
	test('getValue collects only the card\'s own fields, not composite internals', () => {
		expect(buildCard().getValue()).toEqual({
			id: 'card1',
			name: 'My Card', // NOT clobbered by the image/file `name` sub-fields
			image: { name: 'pic.jpg', alt: 'astro' },
			file: { name: 'cms-data.zip' },
		});
	});

	test('no composite internal keys leak to the card top level', () => {
		const value = buildCard().getValue();

		expect('alt' in value).toBe(false);
		expect('ext' in value).toBe(false);
	});

	// A composite's getValue() returns a fresh object each call, so changed() must
	// compare by value — otherwise a stray no-op change event (e.g. a field's native
	// `change` firing on blur after a successful save) re-marks the card unsaved.
	test('changed() does not re-mark a composite field unsaved when nothing changed', () => {
		const card = buildCard();
		card.container.classList.remove('unsaved');

		card.changed(); // value identical to storedValue captured at construction

		expect(card.container.classList.contains('unsaved')).toBe(false);
	});

	test('changed() still marks the card unsaved when a value actually changes', () => {
		const card = buildCard();
		card.subFields().find(f => f.property === 'name').getValue = () => 'Renamed';

		card.changed();

		expect(card.container.classList.contains('unsaved')).toBe(true);
	});
});
