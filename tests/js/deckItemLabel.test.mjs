import DeckItem from '../../javascript/totalform/deckItem.js';

// generateLabel interpolates the deck-item label pattern from the item's data,
// pads oid tokens, and falls back to the item id when it resolves to nothing.
function item(fieldData, { itemId = 'existing' } = {}) {
	const di = Object.create(DeckItem.prototype);
	const container = document.createElement('div');
	container.setAttribute('data-item-id', itemId); // existing item => deterministic (no special vars)
	di.container = container;
	di.getValue = () => fieldData;
	di.deck = { getNextOid: () => 1 };
	di.generateUuid = () => 'uuid';
	return di;
}

describe('DeckItem.generateLabel', () => {
	test('interpolates a field reference', () => {
		expect(item({ name: 'Star Dust', id: 'sd' }).generateLabel('${name}')).toBe('Star Dust');
	});
	test('interpolates within surrounding text', () => {
		expect(item({ name: 'Star Dust', id: 'sd' }).generateLabel('Item: ${name}')).toBe('Item: Star Dust');
	});
	test('pads an oid token', () => {
		expect(item({ id: 'sd' }).generateLabel('${oid-000}')).toBe('001');
	});
	test('renders a true toggle as a check mark and a false one as nothing', () => {
		// `${label}${done}` used to print "Mockup web pagestrue".
		expect(item({ label: 'Mockup', done: true, id: 'm' }).generateLabel('${label} ${done}')).toBe('Mockup ✓');
		expect(item({ label: 'Mockup', done: false, id: 'm' }).generateLabel('${label} ${done}')).toBe('Mockup');
	});
	test('falls back to the item id when the pattern resolves to empty', () => {
		expect(item({ id: 'sd' }).generateLabel('${missing}')).toBe('sd');
	});
});

// updateLabel() writes the label into the page as HTML. Fields that hold
// source (code, markdown) are not sanitized when saved, so the label is.
describe('DeckItem.updateLabel', () => {
	function labelled(fieldData, pattern) {
		const di = item(fieldData);
		di.container.setAttribute('data-deck-label-pattern', pattern);
		const button = document.createElement('button');
		button.className = 'deck-item-label';
		di.container.appendChild(button);
		di.updateLabel();
		return button;
	}

	test('removes script and event handlers from an interpolated value', () => {
		const button = labelled({ body: 'Hi <img src=x onerror="alert(1)"><script>alert(2)</script>', id: 'a' }, '${body}');

		expect(button.querySelector('script')).toBeNull();
		expect(button.innerHTML).not.toContain('onerror');
		expect(button.textContent).toContain('Hi');
	});

	test('keeps an svg icon and harmless formatting', () => {
		const svg = '<svg viewBox="0 0 4 4" onload="alert(1)"><circle cx="2" cy="2" r="2"></circle></svg>';
		const button = labelled({ icon: svg, name: '<strong>Bold</strong>', id: 'a' }, '${icon} ${name}');

		expect(button.querySelector('.deck-label-svg svg circle')).not.toBeNull();
		expect(button.innerHTML).not.toContain('onload');
		expect(button.querySelector('strong').textContent).toBe('Bold');
	});
});
