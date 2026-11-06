import Identifier from '../../javascript/totalform/identifier.js';

//-----------------------------------------------
// Identifier.slugify() turns a raw value into a valid id, and autogenId()
// slugifies an autogen pattern result and switches to underscores for deck items
// / snakeCase schemas. Both read only `this.settings` (+ this.autogen/isInDeck),
// so we drive them via Object.create rather than the heavy field constructor.
//-----------------------------------------------

function identifier(settings = {}) {
	const id = Object.create(Identifier.prototype);
	id.settings = settings;
	return id;
}

describe('Identifier.slugify', () => {
	test('lowercases and hyphenates by default', () => {
		expect(identifier().slugify('Hello World')).toBe('hello-world');
	});

	test('uses underscores when snakeCase is set', () => {
		expect(identifier({ snakeCase: true }).slugify('Hello World')).toBe('hello_world');
	});

	test('expands @ to -at- and replaces dots with the separator', () => {
		expect(identifier().slugify('me@example.com')).toBe('me-at-example-com');
		expect(identifier({ snakeCase: true }).slugify('me@example.com')).toBe('me_at_example_com');
	});
});

describe('Identifier.autogenId', () => {
	function withAutogen({ raw, settings = {}, isInDeck = false }) {
		const id = Object.create(Identifier.prototype);
		id.settings = settings;
		id.isInDeck = isInDeck;
		id.autogen = { generate: () => raw };
		return id;
	}

	test('slugifies the generated value (hyphens by default)', () => {
		expect(withAutogen({ raw: 'My Post' }).autogenId()).toBe('my-post');
	});

	test('switches hyphens to underscores for a deck item', () => {
		expect(withAutogen({ raw: 'My Post', isInDeck: true }).autogenId()).toBe('my_post');
	});

	test('uses underscores for a snakeCase schema', () => {
		expect(withAutogen({ raw: 'My Post', settings: { snakeCase: true } }).autogenId()).toBe('my_post');
	});
});

describe('Identifier.isLocked', () => {
	function withState({ locked = false, disabled = false, readonly = false } = {}) {
		const id = Object.create(Identifier.prototype);
		id.container = { classList: { contains: cls => cls === 'locked' && locked } };
		id.input = { hasAttribute: attr => (attr === 'disabled' && disabled) || (attr === 'readonly' && readonly) };
		return id;
	}

	test('unlocked when nothing is set (new / editable ID — autogen may run)', () => {
		expect(withState().isLocked()).toBe(false);
	});

	test('locked via the disabled attribute', () => {
		expect(withState({ disabled: true }).isLocked()).toBe(true);
	});

	test('locked via the locked class', () => {
		expect(withState({ locked: true }).isLocked()).toBe(true);
	});

	// readonly intentionally does NOT lock: a schema can render an ID readonly
	// (non-editable) while still letting autogen update it. Existing deck items
	// are disabled (not just readonly) to block autogen — see the constructor.
	test('readonly alone does not lock (a readonly-but-autogen ID stays autogen-updatable)', () => {
		expect(withState({ readonly: true }).isLocked()).toBe(false);
	});
});

describe('Identifier.changed', () => {
	function field(property, { isInDeck = false, value = 'abc' } = {}) {
		const id = Object.create(Identifier.prototype);
		id.property = property;
		id.isInDeck = isInDeck;
		const setIdCalls = [];
		id.form = { setId: v => setIdCalls.push(v) };
		id.getValue = () => value;
		return { id, setIdCalls };
	}

	test('the identity field (property "id") drives the form id', () => {
		const { id, setIdCalls } = field('id', { value: 'my-post' });
		id.changed();
		expect(setIdCalls).toEqual(['my-post']);
	});

	// The fix: a second id-type field (user_id) is an ordinary property and must
	// not overwrite the form's id when edited.
	test('a non-id id-type field (user_id) does not touch the form id', () => {
		const { id, setIdCalls } = field('user_id', { value: 'user-123' });
		id.changed();
		expect(setIdCalls).toEqual([]);
	});

	test('a deck id field does not touch the form id', () => {
		const { id, setIdCalls } = field('id', { isInDeck: true });
		id.changed();
		expect(setIdCalls).toEqual([]);
	});
});

//-----------------------------------------------
// A deck item needs an id, and a beginner should not have to type one or write
// an autogen rule. The real constructor runs here: a new item's id field fills
// itself in, in the shape DeckData accepts.
//-----------------------------------------------
describe('Identifier in a deck item', () => {
	const NEW_ITEM_ID = /^item_[a-z0-9]+$/;

	function field({ container = 'deck-item', name = 'id', value = '', settings = {} } = {}) {
		const wrapper = document.createElement('div');
		wrapper.className = container;
		wrapper.innerHTML = `<div class="form-field id-field" data-type="id"><input type="text" name="${name}" value="${value}"></div>`;
		document.body.appendChild(wrapper);
		const form = { id: '', form: document.createElement('form'), isTemplateForm: () => false, setId: () => {}, unsaved: () => {} };
		return new Identifier(wrapper.querySelector('.form-field'), { form, ...settings });
	}

	test('fills in an item id when the schema has no autogen rule', () => {
		expect(field().getValue()).toMatch(NEW_ITEM_ID);
	});

	test('does the same in a table-layout deck', () => {
		expect(field({ container: 'deck-table-row' }).getValue()).toMatch(NEW_ITEM_ID);
	});

	test('keeps the schema autogen rule', () => {
		expect(field({ settings: { autogen: 'entry_${uid}' } }).getValue()).toMatch(/^entry_[a-z0-9]+$/);
	});

	function type(id, value) {
		id.input.value = value;
		id.input.dispatchEvent(new Event('input'));
		id.input.dispatchEvent(new Event('change'));
	}

	test('keeps an id typed over the default', () => {
		const id = field();
		type(id, 'myitem');
		expect(id.getValue()).toBe('myitem');
	});

	test('keeps an id typed over a schema rule, even when the rule\'s field changes later', () => {
		const wrapper = document.createElement('div');
		wrapper.className = 'deck-item';
		wrapper.innerHTML = `
			<div class="form-field text-field" data-type="text"><input type="text" name="title" value="First"></div>
			<div class="form-field id-field" data-type="id"><input type="text" name="id" value=""></div>`;
		document.body.appendChild(wrapper);
		const form = { id: '', form: document.createElement('form'), isTemplateForm: () => false, setId: () => {}, unsaved: () => {} };
		const id = new Identifier(wrapper.querySelector('.id-field'), { form, autogen: '${title}' });
		const title = wrapper.querySelector('.text-field');
		const editTitle = value => {
			title.querySelector('input').value = value;
			title.dispatchEvent(new CustomEvent('field-change'));
		};

		// The rule is live until someone types an id.
		editTitle('Second');
		expect(id.getValue()).toBe('second');

		type(id, 'myitem');
		editTitle('Third');
		expect(id.getValue()).toBe('myitem');
	});

	test('keeps the id of a saved item', () => {
		const id = field({ value: 'first_item' });
		expect(id.getValue()).toBe('first_item');
		expect(id.isLocked()).toBe(true);
	});

	test('leaves another id-type property in the item blank', () => {
		expect(field({ name: 'sku' }).getValue()).toBe('');
	});

	test('leaves an id field outside a deck blank', () => {
		expect(field({ container: 'not-a-deck' }).getValue()).toBe('');
	});
});
