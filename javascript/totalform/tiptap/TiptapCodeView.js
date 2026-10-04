/**
 * TiptapCodeView - CodeMirror 6 integration for source editing (HTML for
 * styledtext, Markdown for the markdown field).
 * Toggle hides ProseMirror, shows CodeMirror instance.
 */

export default class TiptapCodeView {

	constructor(container, options = {}) {
		this.container = container;
		this.options = options;
		this.editor = null;
		this.editorContainer = null;
		this.active = false;
	}

	isActive() {
		return this.active;
	}

	open(html, wrapper) {
		if (this.active) return;
		this.active = true;

		// Capture the editor wrapper's rendered height before hiding
		// A field inside a closed dialog has no height yet.
		const editorHeight = wrapper.offsetHeight || 300;

		// Hide the editor wrapper
		wrapper.style.display = 'none';

		// Create container for CM6 editor
		this.editorContainer = document.createElement('div');
		this.editorContainer.className = 'ste-code-view';
		this.container.appendChild(this.editorContainer);

		// Initialize CodeMirror 6 via factory. `factory` names the
		// TotalCMSCodeMirror method to use; the rest are editor options.
		const { factory = 'createHtmlEditor', ...editorOptions } = this.options;

		if (window.TotalCMSCodeMirror) {
			this.editor = window.TotalCMSCodeMirror[factory](this.editorContainer, {
				value: html,
				tabSize: 2,
				...editorOptions,
			});

			// Use the same height as the editor wrapper so CodeMirror scrolls internally
			this.editor.setSize('100%', editorHeight);

			// Refresh after a tick to ensure proper rendering
			setTimeout(() => this.editor?.refresh(), 50);
		} else {
			// Plain textarea fallback
			this.editorContainer.style.height = `${editorHeight}px`;
			this.editorContainer.style.overflowY = 'auto';
			const textarea = document.createElement('textarea');
			textarea.value = html;
			textarea.style.width = '100%';
			textarea.style.height = '100%';
			this.editorContainer.appendChild(textarea);
		}
	}

	close(wrapper) {
		if (!this.active) return null;
		this.active = false;

		let html = null;

		if (this.editor) {
			html = this.editor.getValue();
			this.editor.destroy();
			this.editor = null;
		}

		if (this.editorContainer) {
			// If no editor (textarea fallback), grab value from textarea
			if (!html) {
				const textarea = this.editorContainer.querySelector('textarea');
				html = textarea?.value || '';
			}
			this.editorContainer.remove();
			this.editorContainer = null;
		}

		// Show editor wrapper
		wrapper.style.display = '';

		return html;
	}

	getValue() {
		if (this.editor) {
			return this.editor.getValue();
		}
		const textarea = this.editorContainer?.querySelector('textarea');
		return textarea?.value || '';
	}

	destroy() {
		if (this.editor) {
			this.editor.destroy();
			this.editor = null;
		}
		this.editorContainer?.remove();
		this.editorContainer = null;
		this.active = false;
	}
}
