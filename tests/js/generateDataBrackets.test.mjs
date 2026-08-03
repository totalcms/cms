import SimpleForm from '../../javascript/totalform/simpleform.js';

//-----------------------------------------------
// A SimpleForm serializes raw DOM input names, so a group of related inputs has
// only their names to say they belong together — the Import RSS mapping panel
// posts eight of them as `fieldMap[title]` etc. Posting JSON means those
// brackets have to be expanded here or the keys reach the server intact and are
// ignored by code reading `fieldMap`.
//
// TotalForm deliberately does NOT do this: each of its fields owns the shape of
// its own value (a checklist returns an array), so a bracketed name would be
// redundant there. See ChecklistFieldNamingTest.
//
// The expansion lives inside simpleform.js rather than a module of its own —
// SimpleForm is its only consumer — so it is exercised through generateData().
//-----------------------------------------------

// generateData() reads only `this.form`, so a bare form element is enough — no
// constructor, no event wiring.
function simpleFormWith(html) {
	const form = document.createElement('form');
	form.innerHTML = html;
	const instance = Object.create(SimpleForm.prototype);
	instance.form = form;
	return instance;
}

describe('SimpleForm.generateData bracket expansion', () => {
	test('mapping rows collapse into a single nested object', () => {
		const form = simpleFormWith(`
			<input name="url" value="https://example.com/feed.xml">
			<input name="fieldMap[title]" value="source_title">
			<input name="fieldMap[summary]" value="raw_summary">
		`);

		const data = form.generateData();

		expect(data.fieldMap).toEqual({
			title: 'source_title',
			summary: 'raw_summary',
		});
		expect(data.url).toBe('https://example.com/feed.xml');
	});

	test('a blank mapping row still reaches the server', () => {
		// Blank means "do not map this field"; the importer implements that by
		// skipping empty targets, so the key has to arrive rather than vanish.
		const form = simpleFormWith('<input name="fieldMap[content]" value="">');

		expect(form.generateData().fieldMap).toEqual({ content: '' });
	});

	test('an ordinary unchecked checkbox is still reported as false', () => {
		const form = simpleFormWith('<input type="checkbox" name="draft">');

		expect(form.generateData().draft).toBe(false);
	});

	test('ordinary field names are untouched', () => {
		const form = simpleFormWith('<input name="collection" value="blog">');

		expect(form.generateData()).toEqual({ collection: 'blog' });
	});

	test('a bare name[] key is left alone rather than guessed at', () => {
		// Not a supported shape — nothing posts `name[]`. Passing it through
		// unchanged beats inventing a nesting rule for a name that should not
		// exist; ChecklistFieldNamingTest is what stops one appearing.
		const form = simpleFormWith('<input name="scopes[]" value="a">');

		expect(form.generateData()).toEqual({ 'scopes[]': 'a' });
	});
});
