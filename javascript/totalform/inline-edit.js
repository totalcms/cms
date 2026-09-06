//-----------------------------------------------
// Inline cell editing on the collection table
//-----------------------------------------------
// AdminCellAction swaps a one-field TotalForm into a table cell. htmx does
// the swapping; this module does what htmx cannot: boot TotalForm on the
// fragment (so a select, a date, a Tiptap editor all behave as they do on
// the object page), and send the value TotalForm computes — generateData()
// as JSON — rather than a naive serialization of the inputs. The server
// merges that one property and answers with the display cell.
//
// The submit goes through htmx.ajax so the CSRF header and the swap follow
// the same path as every other htmx request on the page.
import TotalForm from './totalform';

export function bootInlineEdit(container) {
	const form = container?.querySelector?.('form[data-inline-edit]');
	if (!form || form.totalform) return null;

	const tf     = new TotalForm(form);
	const cell   = form.closest('td');
	const action = form.dataset.action;

	const submit = () => {
		if (form.dataset.submitting) return;
		if (typeof tf.validate === 'function' && !tf.validate()) return;
		form.dataset.submitting = '1';
		window.htmx.ajax('PATCH', action, {
			target : cell,
			swap   : 'innerHTML',
			values : { data: JSON.stringify(tf.generateData()) },
		});
	};

	// TotalForm already prevents the default submit; we want it to mean
	// "save this cell" instead.
	form.addEventListener('submit', (e) => {
		e.preventDefault();
		submit();
	});

	form.addEventListener('keydown', (e) => {
		if (e.key === 'Escape') {
			e.preventDefault();
			form.querySelector('.inline-edit-cancel')?.click();
			return;
		}
		// Enter saves from a single-line input. Textareas and Tiptap keep
		// Enter for newlines; TotalFormManager's document listener swallows
		// Enter on totalforms anyway, so the save must happen here.
		if (e.key === 'Enter' && !e.shiftKey && e.target.matches('input:not([type=checkbox]):not([type=radio])')) {
			e.preventDefault();
			submit();
		}
	});

	// Land focus in the editor. Composite fields keep a hidden textarea or
	// select behind the visible control (Tiptap, Choices), so take the first
	// candidate that is actually rendered.
	const shown = el => { const cs = getComputedStyle(el); return cs.display !== 'none' && cs.visibility !== 'hidden'; };
	Array.from(form.querySelectorAll('[contenteditable="true"], input:not([type=hidden]), textarea, select')).find(shown)?.focus();
	return tf;
}
