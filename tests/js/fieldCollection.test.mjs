import { test } from 'node:test';
import assert from 'node:assert/strict';
import { JSDOM } from 'jsdom';

import {
	isNestedField,
	collectScopedFieldValues,
	collectScopedInputValues,
} from '../../javascript/totalform/fieldCollection.mjs';

//-----------------------------------------------
// Builds a deck-item dialog that reproduces the real collision:
// a deck item with its own `name` text field PLUS an image field whose
// composite markup includes a readonly `name` ("Filename") sub-field and
// other meta sub-fields (width, size). The image's sub-fields are nested
// inside the image's own `.form-field`, exactly as ImageField renders them.
//
//   .form-field[deck]            <- the outer `mydeck` field (OUTSIDE the dialog)
//     .deck-item
//       <dialog>                 <- the deck item scope (this.dialog.dialog)
//         .form-field id
//         .form-field name       <- deck item's OWN name  ("Star Dust")
//         .form-field image      <- composite field (myimage)
//           input[name=myimage]
//           <details>
//             .form-field name   <- image filename sub-field ("beach.jpg")
//             .form-field width
//             .form-field size
//-----------------------------------------------
function buildDeckItemDom() {
	const dom = new JSDOM(`<!DOCTYPE html><body>
		<div class="form-field" data-type="deck">
			<div class="deck-item">
				<dialog>
					<div class="form-field" data-type="id"><input name="id" value="star_dust"></div>
					<div class="form-field" data-type="text"><input name="name" value="Star Dust"></div>
					<div class="form-field" data-type="image">
						<input name="myimage" type="hidden">
						<details>
							<div class="form-field" data-type="text"><input name="name" value="beach.jpg" readonly></div>
							<div class="form-field" data-type="number"><input name="width" value="800" readonly></div>
							<div class="form-field" data-type="number"><input name="size" value="1234" readonly></div>
						</details>
					</div>
				</dialog>
			</div>
		</div>
	</body>`);

	const doc = dom.window.document;
	const dialog = doc.querySelector('dialog');
	const deckItem = doc.querySelector('.deck-item');

	// Attach TotalField-like stubs to each .form-field, mirroring runtime wiring.
	const wire = (selector, name, value) => {
		const container = selector;
		container.totalfield = { input: container.querySelector('input'), getValue: () => value };
	};
	const fields = dialog.querySelectorAll('.form-field');
	// id, name, image(myimage), [image-name, image-width, image-size]
	wire(fields[0], 'id', 'star_dust');
	wire(fields[1], 'name', 'Star Dust');
	wire(fields[2], 'myimage', { name: 'beach.jpg', width: 800, size: 1234 });
	wire(fields[3], 'name', 'beach.jpg'); // image's filename sub-field
	wire(fields[4], 'width', 800);
	wire(fields[5], 'size', 1234);

	return { dialog, deckItem, doc };
}

// Replicates the PRE-FIX deckItem.getValue() primary pass (no scoping guard)
// to prove the collision reproduces.
function legacyCollect(scopeEl) {
	const data = {};
	for (const container of scopeEl.querySelectorAll('.form-field')) {
		const tf = container.totalfield;
		if (tf && tf.input && tf.input.name) {
			data[tf.input.name] = tf.getValue();
		}
	}
	return data;
}

test('REPRODUCES the bug: legacy unscoped collection lets the image name overwrite the deck name', () => {
	const { dialog } = buildDeckItemDom();
	const data = legacyCollect(dialog);

	// The bug: the deck item's own name is clobbered by the image's filename,
	// and image meta sub-fields leak to the deck item's top level.
	assert.equal(data.name, 'beach.jpg', 'demonstrates the collision (image name wins)');
	assert.equal(data.width, 800, 'demonstrates stray image meta leaking up');
	assert.equal(data.size, 1234, 'demonstrates stray image meta leaking up');
});

test('collectScopedFieldValues keeps the deck item name and drops image sub-fields', () => {
	const { dialog } = buildDeckItemDom();
	const data = collectScopedFieldValues(dialog);

	assert.equal(data.name, 'Star Dust', 'deck item keeps its own name');
	assert.equal(data.id, 'star_dust');
	assert.deepEqual(data.myimage, { name: 'beach.jpg', width: 800, size: 1234 }, 'image value stays nested under its own property');
	assert.ok(!('width' in data), 'no stray image meta leaked to top level');
	assert.ok(!('size' in data), 'no stray image meta leaked to top level');
});

test('collectScopedInputValues (autogen) reads the deck name, not the image filename', () => {
	const { deckItem } = buildDeckItemDom();
	// Autogen scopes to the .deck-item element at runtime.
	const data = collectScopedInputValues(deckItem);

	assert.equal(data.name, 'Star Dust', '${name} autogen resolves to the deck item name');
	assert.equal(data.id, 'star_dust');
});

test('isNestedField distinguishes composite sub-fields from a scope\'s own fields', () => {
	const { dialog } = buildDeckItemDom();
	const fields = dialog.querySelectorAll('.form-field');

	assert.equal(isNestedField(fields[1], dialog), false, 'deck item own name is a top-level field');
	assert.equal(isNestedField(fields[2], dialog), false, 'image composite field is a top-level field');
	assert.equal(isNestedField(fields[3], dialog), true, 'image filename sub-field is nested');
	assert.equal(isNestedField(fields[4], dialog), true, 'image width sub-field is nested');
});
