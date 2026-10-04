import TotalForm from '../../javascript/totalform/totalform.js';
import MarkdownField from '../../javascript/totalform/markdown.js';

//-----------------------------------------------
// Duplicating a deck item clones its DOM, removes the cloned editor containers
// and clears `data-ste-initialized` so each editor field builds again
// (deck.js duplicateItem). The markdown field has to follow that convention,
// or the clone is left with a hidden textarea and no editor.
//-----------------------------------------------

function formWithMarkdown(value) {
	document.body.innerHTML = `<form class="totalform" data-api="/api" data-route="/collections/things" data-method="POST" data-form="object" data-collection="things">
		<div class="form-field markdown-field" data-type="markdown"><div class="styledtext-wrapper markdown-wrapper"><textarea name="body"></textarea></div></div>
	</form>`;
	document.querySelector('textarea[name=body]').value = value;

	return document.querySelector('form');
}

test('a real markdown field mounts its editor through the form', () => {
	TotalForm.registerBuiltInFieldTypes({ markdown: MarkdownField });
	const form = new TotalForm(formWithMarkdown('# Title'));

	expect(form.fields[0]).toBeInstanceOf(MarkdownField);
	expect(form.fields[0].getValue()).toBe('# Title');
	expect(document.querySelectorAll('.ste-editor-container')).toHaveLength(1);
});

test('a cloned markdown field builds its own editor', () => {
	TotalForm.registerBuiltInFieldTypes({ markdown: MarkdownField });
	const formEl = formWithMarkdown('# Title');
	const form   = new TotalForm(formEl);
	const source = formEl.querySelector('.form-field');

	// What deck.js duplicateItem() does to a cloned item.
	const clone = source.cloneNode(true);
	clone.querySelectorAll('.ste-editor-container').forEach((editor) => editor.remove());
	clone.querySelectorAll('[data-ste-initialized]').forEach((el) => { delete el.dataset.steInitialized; });
	clone.querySelector('textarea').name = 'copy';
	formEl.appendChild(clone);
	form.refreshFields();

	expect(clone.totalfield).toBeInstanceOf(MarkdownField);
	expect(clone.totalfield.editor).toBeDefined();
	expect(clone.querySelectorAll('.ste-editor-container')).toHaveLength(1);
	expect(() => clone.totalfield.setValue('changed')).not.toThrow();
	expect(clone.totalfield.getValue()).toBe('changed');
});
