import { test, before } from 'node:test';
import assert from 'node:assert/strict';
import { JSDOM } from 'jsdom';
import * as esbuild from 'esbuild';
import { fileURLToPath, pathToFileURL } from 'node:url';
import { dirname, join } from 'node:path';
import { writeFileSync, mkdtempSync } from 'node:fs';
import { tmpdir } from 'node:os';

const __dirname = dirname(fileURLToPath(import.meta.url));

//-----------------------------------------------
// CardField.getValue() must collect ONLY the card's own top-level sub-fields,
// not the internal sub-fields of composite children (an image's alt/name, a
// file's name/ext). Those live inside the child's own .form-field within the
// card's .card-fields, so without an isNestedField() guard they flatten into
// the card and collide with its properties — e.g. a file's `name` clobbering
// the card's own `name`, which then reverts on refresh.
//-----------------------------------------------

let CardField;

before(async () => {
	const dom = new JSDOM('<!DOCTYPE html><body></body>', { pretendToBeVisual: true });
	const w = dom.window;
	for (const k of ['window', 'document', 'Node', 'Element', 'HTMLElement', 'DocumentFragment', 'getComputedStyle', 'MutationObserver', 'Event', 'CustomEvent', 'KeyboardEvent']) {
		try { globalThis[k] = w[k]; } catch { /* read-only */ }
	}
	globalThis.requestAnimationFrame = (cb) => cb(0);
	globalThis.cancelAnimationFrame = () => {};

	const result = await esbuild.build({
		entryPoints: [join(__dirname, '../../javascript/totalform/card.js')],
		bundle: true,
		format: 'esm',
		platform: 'browser',
		write: false,
		logLevel: 'silent',
	});
	const bundlePath = join(mkdtempSync(join(tmpdir(), 'card-')), 'bundle.mjs');
	writeFileSync(bundlePath, result.outputFiles[0].text);
	CardField = (await import(pathToFileURL(bundlePath).href)).default;
});

// Build a card containing a name field plus an image and a file composite, each
// with internal sub-fields (including their own `name`) that must NOT leak.
function buildCard() {
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
	imageMeta.appendChild(wire(field('text', 'alt'), 'alt', 'astro'));        // internal — must skip
	imageMeta.appendChild(wire(field('text', 'name'), 'name', 'pic.jpg'));    // internal — would clobber card.name
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

test('getValue collects only the card\'s own fields, not composite internals', () => {
	const value = buildCard().getValue();

	assert.deepEqual(value, {
		id: 'card1',
		name: 'My Card', // NOT clobbered by the image/file `name` sub-fields
		image: { name: 'pic.jpg', alt: 'astro' },
		file: { name: 'cms-data.zip' },
	});
});

test('no composite internal keys leak to the card top level', () => {
	const value = buildCard().getValue();

	for (const leaked of ['alt', 'ext']) {
		assert.ok(!(leaked in value), `'${leaked}' must not leak into the card`);
	}
});
