import TiptapEditor from '../../javascript/totalform/tiptap/TiptapEditor.js';

//-----------------------------------------------
// Hand-authored HTML must survive a load-and-save through the editor.
//
// Customer report (2026-09-16): opening a help guide and saving it rewrote
// the body with no warning. Three mechanisms, all of them ProseMirror
// dropping what the schema did not model:
//   - a list item whose first child was not a paragraph was emptied and its
//     content ejected after the list, with the next items re-wrapped in a
//     stray <ul>;
//   - inline <svg> had no node and vanished wholesale;
//   - class and style attributes were replaced or dropped (a figure's
//     authored classes, an inline style on a link).
// Tag normalisation (<b> → <strong>) is deliberate and not covered here.
//-----------------------------------------------

function mount(html) {
	document.body.innerHTML = '';
	const ta = document.createElement('textarea');
	ta.name = 'body';
	ta.value = html;
	document.body.appendChild(ta);
	return new TiptapEditor(ta, {});
}

const roundTrip = (html) => mount(html).getHTML();

describe('Tiptap list fidelity', () => {
	test('a list item may start with a heading', () => {
		const out = roundTrip('<ol><li><h4>Prepare</h4><p>Connect the drive.</p></li><li><h4>Run</h4><p>Open Settings.</p></li></ol>');
		expect(out).toBe('<ol><li><h4>Prepare</h4><p>Connect the drive.</p></li><li><h4>Run</h4><p>Open Settings.</p></li></ol>');
	});

	test('a list item may start with a classed block', () => {
		const out = roundTrip('<ol><li><div class="step"><p>One</p></div></li><li><div class="step"><p>Two</p></div></li></ol>');
		expect(out).toBe('<ol><li><div class="step"><p>One</p></div></li><li><div class="step"><p>Two</p></div></li></ol>');
	});

	test('a list item may start with a figure', () => {
		const out = roundTrip('<ul><li><figure class="shot"><img src="/x.jpg" alt="Window"></figure><p>Caption text</p></li></ul>');
		expect(out).toMatch(/^<ul><li><figure [^>]*><img src="\/x\.jpg" alt="Window"><\/figure><p>Caption text<\/p><\/li><\/ul>$/);
	});

	test('plain items still unwrap to bare text', () => {
		expect(roundTrip('<ul><li><p>one</p></li><li>two</li></ul>')).toBe('<ul><li>one</li><li>two</li></ul>');
	});
});

describe('Tiptap SVG fidelity', () => {
	const icon = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="24" height="24" class="icon"><path d="M12 2L2 22h20L12 2z"></path></svg>';

	test('keeps an inline svg inside a paragraph', () => {
		const out = roundTrip(`<p>Warning ${icon} ahead</p>`);
		expect(out).toBe(`<p>Warning ${icon} ahead</p>`);
	});

	test('keeps a block svg as a direct child of a block', () => {
		const out = roundTrip(`<div class="callout">${icon}<p>Do not unplug.</p></div>`);
		expect(out).toBe(`<div class="callout">${icon}<p>Do not unplug.</p></div>`);
	});

	test('keeps a top-level svg', () => {
		expect(roundTrip(icon)).toBe(icon);
	});
});

describe('Tiptap class and style fidelity', () => {
	test('keeps authored classes and style on a figure', () => {
		const out = roundTrip('<figure class="figure figtruncate" style="max-width:40%"><img src="/x.jpg" alt="Shot"><figcaption>Shot</figcaption></figure>');
		expect(out).toContain('figtruncate');
		expect(out).toContain('class="figure figtruncate');
		expect(out).toMatch(/style="max-width: ?40%;?"/); // CSSOM reserialises the declaration
		expect(out).toContain('<figcaption>Shot</figcaption>');
	});

	test('keeps class and style on a link', () => {
		const out = roundTrip('<p>Call <a href="tel:+15555551234" class="cta" style="color:var(--green)">555-1234</a></p>');
		expect(out).toContain('class="cta"');
		expect(out).toMatch(/style="color: ?var\(--green\);?"/);
		expect(out).toContain('href="tel:+15555551234"');
	});

	test('keeps class and style together on a span', () => {
		const out = roundTrip('<p><span class="badge" style="color:red">new</span> <span style="color:blue" class="pill">old</span></p>');
		expect(out).toContain('class="badge"');
		expect(out).toMatch(/style="color: ?red;?"/);
		expect(out).toContain('class="pill"');
		expect(out).toMatch(/style="color: ?blue;?"/);
		expect(out.match(/color: ?red/g)).toHaveLength(1); // once, not duplicated across nested spans
	});

	test('a span with class and a modelled style does not grow a span per save', () => {
		const once = roundTrip('<p><span class="badge" style="color:red">new</span></p>');
		const twice = roundTrip(once);
		expect(twice).toBe(once);
		expect(once.match(/<span/g)).toHaveLength(2); // textStyle carrier + the classed span
	});

	test('a span keeps a style the editor does not model', () => {
		const out = roundTrip('<p><span class="tag" style="letter-spacing:2px">s</span></p>');
		expect(out).toMatch(/style="letter-spacing: ?2px;?"/);
		expect(out).toContain('class="tag"');
		expect(roundTrip(out)).toBe(out);
	});

	test('keeps style on block nodes', () => {
		const out = roundTrip('<p style="color:red">red</p><h2 style="margin-top:0">tight</h2><ul style="columns:2"><li>a</li></ul>');
		expect(out).toMatch(/<p style="color: ?red;?">red<\/p>/);
		expect(out).toMatch(/<h2 style="margin-top: ?0(px)?;?">tight<\/h2>/);
		expect(out).toMatch(/<ul style="columns: ?2;?">/);
	});

	test('keeps class and style on an image', () => {
		const out = roundTrip('<p>x</p><img src="/x.jpg" alt="" class="thumb" style="width:120px">');
		expect(out).toContain('class="thumb"');
		expect(out).toMatch(/style="width: ?120px;?"/);
	});
});

describe('Tiptap wrapper fidelity', () => {
	test('keeps the element name of a block wrapper', () => {
		const out = roundTrip('<section class="steps"><p>a</p></section><aside class="note" id="n1"><p>b</p></aside>');
		expect(out).toBe('<section class="steps"><p>a</p></section><aside class="note" id="n1"><p>b</p></aside>');
	});

	test('keeps details and summary', () => {
		const out = roundTrip('<details class="faq"><summary>Q</summary><p>A</p></details>');
		expect(out).toMatch(/^<details class="faq"><summary>(<p>)?Q(<\/p>)?<\/summary><p>A<\/p><\/details>$/);
		expect(roundTrip(out)).toBe(out);
	});
});

describe('Tiptap round-trip stability', () => {
	test('a second pass through the editor changes nothing', () => {
		const source = [
			'<h2>Steps</h2>',
			'<ol class="steps"><li><h4>Prepare</h4><p>Connect the drive.</p></li><li><div class="step"><p>Open <strong>Settings</strong>.</p></div></li></ol>',
			'<div class="callout callout-warn"><svg viewBox="0 0 24 24" class="icon"><path d="M12 2L2 22h20L12 2z"></path></svg><p>Do not unplug.</p></div>',
			'<figure class="figure figtruncate"><img src="/x.jpg" alt="Shot"><figcaption>Shot</figcaption></figure>',
			'<p style="text-align: center">Call <a href="tel:+1" style="color:var(--green)">now</a> or <span class="badge" style="color:red">wait</span></p>',
			'<p>x</p><img src="/y.jpg" alt="" class="thumb">',
		].join('');
		const once = roundTrip(source);
		const twice = roundTrip(once);
		expect(twice).toBe(once);
		expect(once).toContain('figtruncate');
		expect(once).toContain('<svg');
		expect(once).toContain('<li><h4>Prepare</h4>');
		expect(once).toMatch(/style="text-align: ?center;?"/);
	});
});
