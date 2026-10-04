import { markdownCommand } from '../../javascript/totalform/markdown/commands.js';

// Apply a command and return the new text with the selection marked by [ ].
function run(name, marked, args) {
	const from = marked.indexOf('[');
	const to   = marked.indexOf(']') - 1;
	const text = marked.replace('[', '').replace(']', '');
	const edit = markdownCommand(name, text, from, to, args);
	const out  = text.slice(0, edit.from) + edit.insert + text.slice(edit.to);
	const [a, b] = edit.select;

	return out.slice(0, a) + '[' + out.slice(a, b) + ']' + out.slice(b);
}

describe('inline commands', () => {
	test.each([
		['bold',       'a [word] b', 'a **[word]** b'],
		['italic',     'a [word] b', 'a *[word]* b'],
		['strike',     'a [word] b', 'a ~~[word]~~ b'],
		['inlineCode', 'a [word] b', 'a `[word]` b'],
	])('%s wraps the selection', (name, before, after) => {
		expect(run(name, before)).toBe(after);
	});

	test.each([
		['bold',       'a **[word]** b', 'a [word] b'],
		['italic',     'a *[word]* b',   'a [word] b'],
		['strike',     'a ~~[word]~~ b', 'a [word] b'],
		['inlineCode', 'a `[word]` b',   'a [word] b'],
		['bold',       'a [**word**] b', 'a [word] b'],
	])('%s unwraps what is already wrapped', (name, before, after) => {
		expect(run(name, before)).toBe(after);
	});

	test('with nothing selected the markers are inserted around the cursor', () => {
		expect(run('bold', 'a [] b')).toBe('a **[]** b');
	});

	test('italic inside bold adds a third star instead of removing one', () => {
		expect(run('italic', '**[word]**')).toBe('***[word]***');
	});

	test('bold on bold-italic text leaves the italic', () => {
		expect(run('bold', '***[word]***')).toBe('*[word]*');
	});

	test('works on an empty document and at the ends of the text', () => {
		expect(run('bold', '[]')).toBe('**[]**');
		expect(run('italic', '[all]')).toBe('*[all]*');
	});
});

describe('line commands', () => {
	test('heading sets, changes and removes the level', () => {
		expect(run('heading', 'Ti[]tle', { level: 2 })).toBe('## Title[]');
		expect(run('heading', '## Ti[]tle', { level: 3 })).toBe('### Title[]');
		expect(run('heading', '## Ti[]tle', { level: 2 })).toBe('Title[]');
		expect(run('heading', '### Ti[]tle', { level: 0 })).toBe('Title[]');
	});

	test('bullet list prefixes every selected line and toggles off', () => {
		expect(run('bulletList', '[one\ntwo]')).toBe('[- one\n- two]');
		expect(run('bulletList', '[- one\n- two]')).toBe('[one\ntwo]');
	});

	test('ordered list numbers the lines and replaces bullets', () => {
		expect(run('orderedList', '[one\ntwo\nthree]')).toBe('[1. one\n2. two\n3. three]');
		expect(run('orderedList', '[- one\n- two]')).toBe('[1. one\n2. two]');
		expect(run('orderedList', '[1. one\n2. two]')).toBe('[one\ntwo]');
	});

	test('a list command skips blank lines inside the selection', () => {
		expect(run('bulletList', '[one\n\ntwo]')).toBe('[- one\n\n- two]');
	});

	test('a list command on an empty line starts a list', () => {
		expect(run('bulletList', '[]')).toBe('- []');
		expect(run('orderedList', 'a\n[]')).toBe('a\n1. []');
	});

	test('blockquote prefixes and toggles off', () => {
		expect(run('blockquote', '[one\ntwo]')).toBe('[> one\n> two]');
		expect(run('blockquote', '[> one\n> two]')).toBe('[one\ntwo]');
	});

	test('only the lines the selection touches are changed', () => {
		expect(run('bulletList', 'before\nmi[]ddle\nafter')).toBe('before\n- middle[]\nafter');
	});

	test('a selection ending at the start of a line does not include that line', () => {
		expect(run('bulletList', '[one\n]two')).toBe('[- one]\ntwo');
	});
});

describe('line commands at awkward places', () => {
	// Review 2026-10-04: lastIndexOf('\n', -1) is clamped to 0, so a document
	// starting with a newline gave an inverted range (from 1, to 0). CodeMirror
	// threw on it and the textarea duplicated the newline.
	test('a document that starts with a newline, cursor at the very start', () => {
		for (const name of ['heading', 'bulletList', 'orderedList', 'blockquote', 'codeBlock']) {
			const edit = markdownCommand(name, '\nabc', 0, 0, { level: 2 });

			expect(edit.from, name).toBeLessThanOrEqual(edit.to);
			expect(edit.from, name).toBe(0);
		}
		expect(run('bulletList', '[]\nabc')).toBe('- []\nabc');
	});

	test('a list command over blank lines only keeps every line', () => {
		expect(run('bulletList', 'a\n[\n\n]\nb')).toBe('a\n[- \n]\n\nb');
		expect(run('orderedList', 'a\n[\n\n]\nb')).toBe('a\n[1. \n]\n\nb');
	});
});

describe('block commands', () => {
	test('code block fences the selected lines and toggles off', () => {
		expect(run('codeBlock', '[a\nb]')).toBe('[```\na\nb\n```]');
		expect(run('codeBlock', '[```\na\nb\n```]')).toBe('[a\nb]');
	});

	test('code block on an empty line leaves the cursor inside the fence', () => {
		expect(run('codeBlock', '[]')).toBe('```\n[]\n```');
	});

	test('horizontal rule goes after the current line', () => {
		expect(run('horizontalRule', 'te[]xt')).toBe('text\n\n---\n\n[]');
		expect(run('horizontalRule', '[]')).toBe('---\n\n[]');
	});

	test('table goes after the current line with the first header selected', () => {
		expect(run('table', 'te[]xt')).toBe('text\n\n| [Column 1] | Column 2 |\n| --- | --- |\n| Cell | Cell |\n\n');
	});
});

describe('insert commands', () => {
	test('link with a selection: text kept, url selected', () => {
		const text = 'see docs now';
		const edit = markdownCommand('link', text, 4, 8);
		const out  = text.slice(0, edit.from) + edit.insert + text.slice(edit.to);

		expect(out).toBe('see [docs](url) now');
		expect(out.slice(...edit.select)).toBe('url');
	});

	test('link with nothing selected selects the placeholder text', () => {
		const edit = markdownCommand('link', 'a  b', 2, 2);

		expect(edit.insert).toBe('[text](url)');
		expect('a [text](url) b'.slice(...edit.select)).toBe('text');
	});

	test('image inserts the syntax and selects the url', () => {
		const edit = markdownCommand('image', 'logo', 0, 4);
		const out  = edit.insert;

		expect(out).toBe('![logo](url)');
		expect(out.slice(...edit.select)).toBe('url');
	});

	test('insert replaces the selection and puts the cursor after it', () => {
		expect(run('insert', 'a [old] b', { text: '![x](y.png)' })).toBe('a ![x](y.png)[] b');
	});

	test('an unknown command returns null', () => {
		expect(markdownCommand('nope', 'text', 0, 0)).toBeNull();
	});
});
