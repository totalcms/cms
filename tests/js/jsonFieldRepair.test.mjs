import JSONField, { repairJson } from '../../javascript/totalform/json.js';

//-----------------------------------------------
// The JSON field is where operators type JSON Schema fragments into a
// property's "Extra Schema Definitions" box. Regex patterns are the common
// case, and a regex is nearly all backslashes:
//
//     {"pattern": "^\d+\.\d+\.\d+$"}
//
// That is not valid JSON — \d and \. are not legal escapes — so JSON.parse
// throws "Bad escaped character". The field already forgave trailing commas;
// this extends the same forgiveness to backslashes.
//-----------------------------------------------

// getValue() reads only `editor` and `input`, so a stub is enough.
const fieldWith = value => {
	const field = Object.create(JSONField.prototype);
	field.editor = null;
	field.input  = { value };
	return field;
};

describe('repairJson escapes', () => {
	test('escapes a backslash that is not a legal JSON escape', () => {
		expect(repairJson(String.raw`{"pattern": "^\d+$"}`)).toBe(String.raw`{"pattern": "^\\d+$"}`);
	});

	test('escapes an escaped dot — the other half of a regex', () => {
		expect(repairJson(String.raw`{"p": "\."}`)).toBe(String.raw`{"p": "\\."}`);
	});

	test.each([
		['newline',      String.raw`{"p": "a\nb"}`],
		['tab',          String.raw`{"p": "a\tb"}`],
		['quote',        String.raw`{"p": "a\"b"}`],
		['solidus',      String.raw`{"p": "a\/b"}`],
		['backspace',    String.raw`{"p": "a\bb"}`],
		['formfeed',     String.raw`{"p": "a\fb"}`],
		['return',       String.raw`{"p": "a\rb"}`],
		['unicode',      String.raw`{"p": "caf\u00e9"}`],
		['escaped slash', String.raw`{"p": "a\\b"}`],
	])('leaves a legal %s escape untouched', (_label, json) => {
		expect(repairJson(json)).toBe(json);
	});

	test('is idempotent — repairing twice changes nothing further', () => {
		const once  = repairJson(String.raw`{"pattern": "^\d+\.\d+$"}`);
		const twice = repairJson(once);

		expect(twice).toBe(once);
	});

	test('still strips a trailing comma before a brace', () => {
		expect(JSON.parse(repairJson('{"a": 1,}'))).toEqual({ a: 1 });
	});

	test('still strips a trailing comma before a bracket', () => {
		expect(JSON.parse(repairJson('{"a": [1, 2,]}'))).toEqual({ a: [1, 2] });
	});

	test('repairs backslashes and trailing commas together', () => {
		const repaired = repairJson(String.raw`{"pattern": "^\d+$",}`);

		expect(JSON.parse(repaired)).toEqual({ pattern: String.raw`^\d+$` });
	});
});

describe('JSONField.getValue', () => {
	test('accepts an unescaped regex and yields the intended pattern', () => {
		const field = fieldWith(String.raw`{"pattern": "^\d+\.\d+\.\d+$"}`);

		expect(field.getValue()).toEqual({ pattern: String.raw`^\d+\.\d+\.\d+$` });
	});

	test('a correctly escaped regex still yields the same pattern', () => {
		// Someone who already knows to double their backslashes must not have
		// them doubled a second time into a literal backslash.
		const field = fieldWith(String.raw`{"pattern": "^\\d+\\.\\d+\\.\\d+$"}`);

		expect(field.getValue()).toEqual({ pattern: String.raw`^\d+\.\d+\.\d+$` });
	});

	test('valid JSON is returned untouched', () => {
		const field = fieldWith('{"minLength": 3, "maxLength": 20}');

		expect(field.getValue()).toEqual({ minLength: 3, maxLength: 20 });
	});

	test('multi-line valid JSON keeps its parsed shape', () => {
		const field = fieldWith('{\n  "a": 1,\n  "b": "two"\n}');

		expect(field.getValue()).toEqual({ a: 1, b: 'two' });
	});

	test('an empty field is an empty value, not a parse error', () => {
		expect(fieldWith('').getValue()).toBe('');
		expect(fieldWith('   \n  ').getValue()).toBe('');
	});

	test('genuinely broken JSON still throws so the form reports it', () => {
		// The save() guard turns this into a visible error; repair must not
		// swallow input that no amount of escaping can rescue.
		const field = fieldWith('{"a": ');

		expect(() => field.getValue()).toThrow(SyntaxError);
	});

	test('a bare word is still a parse error', () => {
		expect(() => fieldWith('{not json at all}').getValue()).toThrow(SyntaxError);
	});
});
