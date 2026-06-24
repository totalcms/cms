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
//-----------------------------------------------
function buildDeckItemDom() {
	document.body.innerHTML = `
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
		</div>`;

	const dialog = document.querySelector('dialog');
	const deckItem = document.querySelector('.deck-item');

	// Attach TotalField-like stubs to each .form-field, mirroring runtime wiring.
	const wire = (container, value) => {
		container.totalfield = { input: container.querySelector('input'), getValue: () => value };
	};
	const fields = dialog.querySelectorAll('.form-field');
	wire(fields[0], 'star_dust');
	wire(fields[1], 'Star Dust');
	wire(fields[2], { name: 'beach.jpg', width: 800, size: 1234 });
	wire(fields[3], 'beach.jpg'); // image's filename sub-field
	wire(fields[4], 800);
	wire(fields[5], 1234);

	return { dialog, deckItem };
}

// Replicates the PRE-FIX deckItem.getValue() primary pass (no scoping guard).
function legacyCollect(scopeEl) {
	const data = {};
	for (const container of scopeEl.querySelectorAll('.form-field')) {
		const tf = container.totalfield;
		if (tf && tf.input && tf.input.name) data[tf.input.name] = tf.getValue();
	}
	return data;
}

describe('fieldCollection', () => {
	test('REPRODUCES the bug: legacy unscoped collection lets the image name overwrite the deck name', () => {
		const data = legacyCollect(buildDeckItemDom().dialog);

		expect(data.name).toBe('beach.jpg'); // collision: image name wins
		expect(data.width).toBe(800);         // stray image meta leaks up
		expect(data.size).toBe(1234);
	});

	test('collectScopedFieldValues keeps the deck item name and drops image sub-fields', () => {
		const data = collectScopedFieldValues(buildDeckItemDom().dialog);

		expect(data.name).toBe('Star Dust');
		expect(data.id).toBe('star_dust');
		expect(data.myimage).toEqual({ name: 'beach.jpg', width: 800, size: 1234 });
		expect('width' in data).toBe(false);
		expect('size' in data).toBe(false);
	});

	test('collectScopedInputValues (autogen) reads the deck name, not the image filename', () => {
		const data = collectScopedInputValues(buildDeckItemDom().deckItem);

		expect(data.name).toBe('Star Dust');
		expect(data.id).toBe('star_dust');
	});

	test('isNestedField distinguishes composite sub-fields from a scope\'s own fields', () => {
		const { dialog } = buildDeckItemDom();
		const fields = dialog.querySelectorAll('.form-field');

		expect(isNestedField(fields[1], dialog)).toBe(false); // deck item's own name
		expect(isNestedField(fields[2], dialog)).toBe(false); // image composite field
		expect(isNestedField(fields[3], dialog)).toBe(true);  // image filename sub-field
		expect(isNestedField(fields[4], dialog)).toBe(true);  // image width sub-field
	});
});
