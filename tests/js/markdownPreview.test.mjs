import { renderMarkdownPreview } from '../../javascript/totalform/markdown/preview.js';

function render(markdown) {
	const el = document.createElement('div');
	el.innerHTML = renderMarkdownPreview(markdown);

	return el;
}

describe('renderMarkdownPreview', () => {
	test('renders markdown, with a single newline as a line break', () => {
		const el = render('# Title\n\nline one\nline two\n\n- a\n- b');

		expect(el.querySelector('h1').textContent).toBe('Title');
		expect(el.querySelector('p br')).not.toBeNull();
		expect(el.querySelectorAll('li')).toHaveLength(2);
	});

	test('renders tables and task lists', () => {
		const el = render('| A | B |\n|---|---|\n| 1 | 2 |\n\n- [x] done');

		expect(el.querySelector('table td').textContent).toBe('1');
		expect(el.querySelector('input[type="checkbox"]')).not.toBeNull();
	});

	test('removes scripts and event handlers', () => {
		const el = render('Hi\n\n<script>alert(1)</script>\n\n<img src=x onerror="alert(2)">\n\n[x](javascript:alert(3))');

		expect(el.querySelector('script')).toBeNull();
		expect(el.innerHTML).not.toContain('onerror');
		expect(el.innerHTML).not.toContain('javascript:');
	});

	test('removes form controls, so nothing in a preview can submit the form around it', () => {
		const el = render('<form action="/x"><input name="a"><button type="submit">Go</button><select><option>1</option></select><textarea>t</textarea></form>\n\ntext');

		expect(el.querySelector('form, input, button, select, textarea')).toBeNull();

		const boxes = render('- [x] done\n\n<input type="checkbox" name="agree">');
		for (const box of boxes.querySelectorAll('input')) {
			expect(box.type).toBe('checkbox');
			expect(box.disabled).toBe(true);
			expect(box.hasAttribute('name')).toBe(false);
		}
		expect(boxes.querySelectorAll('input').length).toBeGreaterThan(0);
		expect(el.textContent).toContain('text');
	});

	test('an empty or missing value renders nothing', () => {
		expect(renderMarkdownPreview('')).toBe('');
		expect(renderMarkdownPreview(null)).toBe('');
	});
});
