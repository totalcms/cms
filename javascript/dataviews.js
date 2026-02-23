/**
 * Data Views admin interface JavaScript.
 * TotalForm handles form submission, save, and delete.
 * SimpleForm handles the rebuild button.
 * This file only handles the test button.
 */

document.addEventListener('DOMContentLoaded', () => {
	initTestButton();
});

/**
 * Handle test button click.
 */
function initTestButton() {
	const btn = document.getElementById('btn-test-view');
	if (!btn) return;

	btn.addEventListener('click', async () => {
		const definitionTextarea = document.querySelector('textarea[name="definition"]');
		if (!definitionTextarea) return;

		// Check if CodeMirror is active
		let definition = '';
		const cmWrapper = definitionTextarea.parentElement.querySelector('.CodeMirror');
		if (cmWrapper && cmWrapper.CodeMirror) {
			definition = cmWrapper.CodeMirror.getValue();
		} else {
			definition = definitionTextarea.value;
		}

		if (!definition) {
			alert('Please enter a Twig definition first');
			return;
		}

		btn.disabled = true;
		btn.textContent = 'Testing...';

		try {
			const response = await fetch('../dataviews/test', {
				method: 'POST',
				headers: { 'Content-Type': 'application/json' },
				body: JSON.stringify({ definition }),
			});

			const result = await response.json();
			const resultsDiv = document.getElementById('test-results');
			const outputDiv = document.getElementById('test-output');

			if (resultsDiv && outputDiv) {
				resultsDiv.style.display = 'block';

				if (result.success) {
					outputDiv.innerHTML = `<pre class="test-success">${escapeHtml(JSON.stringify(result.data, null, 2))}</pre>`;
				} else {
					outputDiv.innerHTML = `<div class="test-error">${escapeHtml(result.error || 'Unknown error')}</div>`;
				}
			}
		} catch (err) {
			alert('Error testing view: ' + err.message);
		} finally {
			btn.disabled = false;
			btn.textContent = 'Test Run';
		}
	});
}

/**
 * Escape HTML entities.
 */
function escapeHtml(text) {
	const div = document.createElement('div');
	div.textContent = text;
	return div.innerHTML;
}
