/**
 * TiptapCodeView - CodeMirror 5 integration for HTML source editing.
 * Toggle hides ProseMirror, shows CodeMirror instance.
 */

export default class TiptapCodeView {

	constructor(container, options = {}) {
		this.container = container;
		this.options = options;
		this.codeMirror = null;
		this.codeArea = null;
		this.active = false;
	}

	isActive() {
		return this.active;
	}

	open(html, wrapper) {
		if (this.active) return;
		this.active = true;

		// Hide the editor wrapper
		wrapper.style.display = 'none';

		// Create code view textarea
		this.codeArea = document.createElement('textarea');
		this.codeArea.className = 'ste-code-view';
		this.codeArea.value = html;
		this.container.appendChild(this.codeArea);

		// Match the editor wrapper height constraints
		const minH = wrapper.style.minHeight || '200px';
		const maxH = wrapper.style.maxHeight || '800px';
		const fixedH = wrapper.style.height;

		// Initialize CodeMirror if available
		if (window.CodeMirror) {
			this.codeMirror = window.CodeMirror.fromTextArea(this.codeArea, {
				indentWithTabs: true,
				lineNumbers: true,
				lineWrapping: true,
				readOnly: false,
				mode: 'text/html',
				tabMode: 'indent',
				tabSize: 2,
				...this.options,
			});

			// Let CodeMirror auto-size with scroll constraints
			const cmWrapper = this.codeMirror.getWrapperElement();
			if (fixedH) {
				cmWrapper.style.height = fixedH;
			} else {
				cmWrapper.style.minHeight = minH;
				cmWrapper.style.maxHeight = maxH;
			}
			cmWrapper.style.overflow = 'auto';
			this.codeMirror.setSize(null, '100%');

			// Refresh after a tick to ensure proper rendering
			setTimeout(() => this.codeMirror?.refresh(), 50);
		} else {
			// Plain textarea fallback
			if (fixedH) {
				this.codeArea.style.height = fixedH;
			} else {
				this.codeArea.style.minHeight = minH;
				this.codeArea.style.maxHeight = maxH;
			}
			this.codeArea.style.overflowY = 'auto';
		}
	}

	close(wrapper) {
		if (!this.active) return null;
		this.active = false;

		let html = null;

		if (this.codeMirror) {
			html = this.codeMirror.getValue();
			this.codeMirror.toTextArea();
			this.codeMirror = null;
		}

		if (this.codeArea) {
			if (!html) html = this.codeArea.value;
			this.codeArea.remove();
			this.codeArea = null;
		}

		// Show editor wrapper
		wrapper.style.display = '';

		return html;
	}

	getValue() {
		if (this.codeMirror) {
			return this.codeMirror.getValue();
		}
		return this.codeArea?.value || '';
	}

	destroy() {
		if (this.codeMirror) {
			this.codeMirror.toTextArea();
			this.codeMirror = null;
		}
		this.codeArea?.remove();
		this.codeArea = null;
		this.active = false;
	}
}
