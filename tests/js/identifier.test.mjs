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
