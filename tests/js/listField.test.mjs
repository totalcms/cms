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
// ListField is Choices.js-backed. Regression target: setValue() repopulates the
// field (the gallery's shared edit dialog calls it for every image). It used to
// call choices.clearStore(), which wipes the whole choice pool — so any
// propertyOptions-driven suggestions vanished after the first image. The fix
// clears only the selection (removeActiveItems) and re-selects, preserving the
// suggestion pool.
//-----------------------------------------------

let ListField;

before(async () => {
	const dom = new JSDOM('<!DOCTYPE html><body></body>', { pretendToBeVisual: true });
	const w = dom.window;
	for (const k of ['window', 'document', 'Node', 'Element', 'HTMLElement', 'HTMLOptionElement', 'Option', 'DocumentFragment', 'getComputedStyle', 'MutationObserver', 'Event', 'CustomEvent', 'KeyboardEvent']) {
		try { globalThis[k] = w[k]; } catch { /* read-only */ }
	}
	globalThis.requestAnimationFrame = (cb) => cb(0);
	globalThis.cancelAnimationFrame = () => {};

	const result = await esbuild.build({
		entryPoints: [join(__dirname, '../../javascript/totalform/list.js')],
		bundle: true,
		format: 'esm',
		platform: 'browser',
		write: false,
		logLevel: 'silent',
	});
	const bundlePath = join(mkdtempSync(join(tmpdir(), 'list-')), 'bundle.mjs');
	writeFileSync(bundlePath, result.outputFiles[0].text);
	ListField = (await import(pathToFileURL(bundlePath).href)).default;
});

function makeListField(options) {
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

test('setValue selects the given values without wiping the suggestion pool', () => {
	const field = makeListField(['a', 'b', 'c', 'd', 'e']);

	field.setValue(['b']);
	assert.deepEqual(field.getValue(), ['b']);
	assert.deepEqual(available(field).sort(), ['a', 'c', 'd', 'e']);
});

test('repopulating for another image swaps the selection and keeps suggestions', () => {
	const field = makeListField(['a', 'b', 'c', 'd', 'e']);

	field.setValue(['b']);          // image 1
	field.setValue(['d', 'e']);     // switch to image 2 (gallery shared dialog)

	assert.deepEqual(field.getValue().sort(), ['d', 'e']);
	// The previously-selected 'b' returns to the pool; suggestions are intact.
	assert.deepEqual(available(field).sort(), ['a', 'b', 'c']);
});
