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
// TiptapEditor mounts a real ProseMirror editor. To exercise it under Node we
// install a jsdom global environment, then bundle the editor (with its Tiptap
// extension graph) via esbuild so the test always runs against current source.
//
// Regression target: a transaction can dispatch onUpdate during the editor's
// initial mount, before `this.editor` is assigned (and the old code destroyed
// and recreated the editor, leaving a torn-down instance in `this.editor`).
// syncToTextarea() then called `this.editor.getHTML()` on a null/destroyed
// editor → DOMSerializer.fromSchema(null) → "Cannot read properties of null".
//-----------------------------------------------

let TiptapEditor;

before(async () => {
	const dom = new JSDOM('<!DOCTYPE html><body></body>', { pretendToBeVisual: true });
	const w = dom.window;
	for (const k of ['window', 'document', 'Node', 'Element', 'HTMLElement', 'DocumentFragment', 'getComputedStyle', 'MutationObserver', 'DOMParser', 'Event', 'CustomEvent']) {
		try { globalThis[k] = w[k]; } catch { /* read-only global (e.g. navigator) — leave Node's */ }
	}

	const result = await esbuild.build({
		entryPoints: [join(__dirname, '../../javascript/totalform/tiptap/TiptapEditor.js')],
		bundle: true,
		format: 'esm',
		platform: 'browser',
		write: false,
		logLevel: 'silent',
	});
	const bundlePath = join(mkdtempSync(join(tmpdir(), 'tiptap-test-')), 'bundle.mjs');
	writeFileSync(bundlePath, result.outputFiles[0].text);
	TiptapEditor = (await import(pathToFileURL(bundlePath).href)).default;
});

function makeTextarea(value) {
	const ta = document.createElement('textarea');
	ta.name = 'notes';
	ta.value = value;
	document.body.appendChild(ta);
	return ta;
}

test('mounts list content without throwing and syncs the textarea (single-creation path)', () => {
	const ta = makeTextarea('<p>Intro</p><ul><li>one</li><li>two</li></ul>');

	let editor;
	assert.doesNotThrow(() => { editor = new TiptapEditor(ta, {}); });
	assert.match(editor.getHTML(), /<ul><li>one<\/li>/);
	assert.match(ta.value, /<ul>/);
});

test('syncToTextarea uses the editor handed to onUpdate even before this.editor is assigned', () => {
	const ta = makeTextarea('<p>hi</p>');
	const editor = new TiptapEditor(ta, {});
	const live = editor.editor;

	// Simulate a mount-time onUpdate: the instance property isn't set yet.
	editor.editor = null;
	ta.value = 'STALE';

	assert.doesNotThrow(() => editor.syncToTextarea(live));
	assert.match(ta.value, /<p>hi<\/p>/, 'synced from the live editor passed to the callback');
});

test('syncToTextarea is a no-op (no throw) when the editor is null or destroyed', () => {
	const ta = makeTextarea('<p>hi</p>');
	const editor = new TiptapEditor(ta, {});
	const live = editor.editor;

	ta.value = 'KEEP';
	editor.editor = null;
	assert.doesNotThrow(() => editor.syncToTextarea(), 'null editor guarded');
	assert.equal(ta.value, 'KEEP', 'textarea left untouched');

	live.destroy();
	assert.doesNotThrow(() => editor.syncToTextarea(live), 'destroyed editor guarded');
});

test('getHTML falls back to the textarea value when the editor is unavailable', () => {
	const ta = makeTextarea('<p>fallback</p>');
	const editor = new TiptapEditor(ta, {});

	editor.editor.destroy();
	assert.doesNotThrow(() => editor.getHTML());
	assert.equal(editor.getHTML(), ta.value, 'returns the last-synced textarea value');
});
