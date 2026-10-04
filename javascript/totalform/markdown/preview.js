import DOMPurify from 'dompurify';
import { Marked } from 'marked';

// The preview both markdown fields show. An approximation: marked renders
// here, ParsedownExtra renders the site. `breaks` matches the |markdown
// filter, where a single newline is a line break.
const parser = new Marked({ gfm: true, breaks: true });

// The preview sits inside the form being edited. A button or input in the
// previewed content would be a live control of that form. The one input kept
// is a task list's checkbox, always disabled.
const FORBIDDEN_TAGS = ['form', 'button', 'select', 'textarea', 'style'];

/**
 * @param {string|null|undefined} markdown
 * @returns {string} sanitized HTML
 */
export function renderMarkdownPreview(markdown) {
	const source = String(markdown ?? '');
	if (source === '') return '';

	const holder = document.createElement('template');
	holder.innerHTML = DOMPurify.sanitize(parser.parse(source), { FORBID_TAGS: FORBIDDEN_TAGS });

	for (const input of holder.content.querySelectorAll('input')) {
		if (input.type === 'checkbox') {
			input.disabled = true;
			input.removeAttribute('name');
		} else {
			input.remove();
		}
	}

	return holder.innerHTML;
}
