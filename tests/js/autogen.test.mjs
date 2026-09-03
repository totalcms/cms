import { readFileSync } from 'node:fs';

import { vi } from 'vitest';

import Autogen from '../../javascript/totalform/autogen.js';

//-----------------------------------------------
// Autogen interpolates a pattern like "${title}-${oid-0000}" from form data plus
// special variables (uuid, timestamps, current date, padded oid). It reads only
// `this.field`, so we drive it with a stub field.
//-----------------------------------------------

function makeAutogen(pattern, { fields = {}, count = null } = {}) {
	const formEl = document.createElement('div');
	if (count !== null) formEl.setAttribute('data-collection-count', String(count));
	const field = {
		settings: { autogen: pattern },
		isInDeck: false,
		form: { form: formEl, generateData: () => fields },
	};
	return new Autogen(field);
}

describe('Autogen.getFieldNames', () => {
	test('returns referenced field names, excluding reserved + oid- tokens', () => {
		expect(makeAutogen('${title}-${oid-00000}').getFieldNames()).toEqual(['title']);
		expect(makeAutogen('${first}-${last}').getFieldNames()).toEqual(['first', 'last']);
	});

	test('returns nothing when the pattern is only reserved variables', () => {
		expect(makeAutogen('${currentyear}-${uuid}-${oid}').getFieldNames()).toEqual([]);
	});

	test('excludes sized uid- tokens (they are special variables, not fields)', () => {
		expect(makeAutogen('${title}-${uid-8}').getFieldNames()).toEqual(['title']);
	});
});

describe('Autogen.generate', () => {
	test('interpolates referenced field values', () => {
		expect(makeAutogen('${name}', { fields: { name: 'Hello World' } }).generate()).toBe('Hello World');
		expect(makeAutogen('${a}-${b}', { fields: { a: 'x', b: 'y' } }).generate()).toBe('x-y');
	});

	test('coerces numbers and ignores non-string/number field values', () => {
		const out = makeAutogen('${title}-${count}-${obj}', {
			fields: { title: 'post', count: 5, obj: { nested: true } },
		}).generate();
		// obj is filtered out by collectFormData → interpolates to ''
		expect(out).toBe('post-5-');
	});

	test('pads an oid token with leading zeros from the collection count', () => {
		// count 6 → next oid 7 → padded to width 3
		expect(makeAutogen('${oid-000}', { count: 6 }).generate()).toBe('007');
	});

	test('resolves the current-year special variable', () => {
		const year = String(new Date().getFullYear());
		expect(makeAutogen('${currentyear}', { count: 0 }).generate()).toBe(year);
	});

	test('produces a v4-shaped uuid for the uuid special variable', () => {
		expect(makeAutogen('${uuid}').generate()).toMatch(
			/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/,
		);
	});

	test('bare ${uid} produces exactly 7 base-36 chars', () => {
		expect(makeAutogen('${uid}').generate()).toMatch(/^[0-9a-z]{7}$/);
	});

	test('${uid-N} produces exactly N base-36 chars (including lengths beyond one draw)', () => {
		expect(makeAutogen('${uid-12}').generate()).toMatch(/^[0-9a-z]{12}$/);
		expect(makeAutogen('${uid-3}').generate()).toMatch(/^[0-9a-z]{3}$/);
		expect(makeAutogen('${uid-40}').generate()).toMatch(/^[0-9a-z]{40}$/);
	});

	test('${uid-N} composes with other tokens', () => {
		const out = makeAutogen('${title}-${uid-5}', { fields: { title: 'post' } }).generate();
		expect(out).toMatch(/^post-[0-9a-z]{5}$/);
	});
});

//-----------------------------------------------
// The Designer Token is generated from the pattern in the shipped template
// schema. It must carry its full advertised entropy — a pattern that repeats
// one ${uid} draw looks 14 chars long but is only 7 chars of randomness.
//-----------------------------------------------
describe('designer token pattern', () => {
	const schema = JSON.parse(
		readFileSync('resources/schemas/template.json', 'utf8'),
	);
	const pattern = schema.properties.designerToken.settings.autogen;

	test('generates a token that is random across its whole length', () => {
		// Deterministic draws so "both halves are identical" can only mean the
		// pattern reused a single interpolated value.
		const random = vi.spyOn(Math, 'random');
		let n = 0;
		random.mockImplementation(() => ((n++ * 0.0137) % 1));

		const token = makeAutogen(pattern).generate();
		random.mockRestore();

		expect(token).toHaveLength(14);
		expect(token.slice(0, 7)).not.toBe(token.slice(7));
	});
});
