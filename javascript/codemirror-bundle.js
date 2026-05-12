/**
 * CodeMirror 6 Bundle - Complete syntax highlighting solution
 * Includes all languages, extensions, and theme integration for TotalCMS
 */

import { EditorView, keymap, lineNumbers, highlightActiveLine, placeholder as placeholderExt, drawSelection, rectangularSelection } from '@codemirror/view';
import { EditorState, Compartment } from '@codemirror/state';
import { syntaxHighlighting, bracketMatching, foldGutter, foldKeymap, indentOnInput, HighlightStyle } from '@codemirror/language';
import { closeBrackets, closeBracketsKeymap, autocompletion } from '@codemirror/autocomplete';
import { defaultKeymap, history, historyKeymap, indentWithTab } from '@codemirror/commands';
import { searchKeymap, highlightSelectionMatches } from '@codemirror/search';
import { tags } from '@lezer/highlight';

// Language imports
import { html } from '@codemirror/lang-html';
import { css } from '@codemirror/lang-css';
import { javascript } from '@codemirror/lang-javascript';
import { json } from '@codemirror/lang-json';
import { markdown } from '@codemirror/lang-markdown';
import { xml } from '@codemirror/lang-xml';
import { php } from '@codemirror/lang-php';
import { jinja } from '@codemirror/lang-jinja';

// Legacy modes for languages without native CM6 support
import { StreamLanguage } from '@codemirror/language';
import { yaml } from '@codemirror/legacy-modes/mode/yaml';
import { sql } from '@codemirror/legacy-modes/mode/sql';
import { shell } from '@codemirror/legacy-modes/mode/shell';

// Import js-beautify for HTML formatting
import { html as beautifyHtml } from 'js-beautify';

// ─────────────────────────────────────────────────────────────────────────────
// Highlight style using CSS custom properties (works with .twig-theme-* classes)
// ─────────────────────────────────────────────────────────────────────────────
const twigHighlightStyle = HighlightStyle.define([
	{ tag: tags.tagName,                          color: 'var(--twig-delimiter)',  fontWeight: '600' },
	{ tag: tags.angleBracket,                     color: 'var(--twig-delimiter)' },
	{ tag: tags.attributeName,                    color: 'var(--twig-variable-2)' },
	{ tag: tags.variableName,                     color: 'var(--twig-variable)' },
	{ tag: [tags.special(tags.variableName)],     color: 'var(--twig-variable-2)' },
	{ tag: tags.typeName,                         color: 'var(--twig-variable-3)' },
	{ tag: tags.string,                           color: 'var(--twig-string)' },
	{ tag: tags.number,                           color: 'var(--twig-number)' },
	{ tag: tags.keyword,                          color: 'var(--twig-keyword)', fontWeight: '600' },
	{ tag: tags.function(tags.variableName),      color: 'var(--twig-builtin)' },
	{ tag: [tags.atom, tags.bool],                color: 'var(--twig-atom)' },
	{ tag: tags.operator,                         color: 'var(--twig-operator)' },
	{ tag: [tags.bracket, tags.paren],            color: 'var(--twig-bracket)' },
	{ tag: tags.comment,                          color: 'var(--twig-comment)', fontStyle: 'italic' },
	{ tag: tags.propertyName,                     color: 'var(--twig-property)' },
	{ tag: tags.meta,                             color: 'var(--twig-meta)' },
	{ tag: tags.className,                        color: 'var(--twig-qualifier)' },
	{ tag: tags.invalid,                          backgroundColor: 'var(--twig-error-bg)', color: 'var(--twig-error-color)' },
	{ tag: tags.attributeValue,                   color: 'var(--twig-string)' },
	{ tag: tags.definition(tags.variableName),    color: 'var(--twig-variable)' },
	{ tag: tags.definition(tags.propertyName),    color: 'var(--twig-property)' },
	{ tag: tags.processingInstruction,            color: 'var(--twig-meta)' },
	{ tag: tags.self,                             color: 'var(--twig-keyword)', fontWeight: '600' },
	{ tag: tags.null,                             color: 'var(--twig-atom)' },
]);

// ─────────────────────────────────────────────────────────────────────────────
// Language mode resolver
// ─────────────────────────────────────────────────────────────────────────────
function getLanguageExtension(mode) {
	if (typeof mode === 'object') {
		// Handle { name: 'twig', base: 'text/html' } etc.
		mode = mode.name || 'text';
	}

	switch (mode) {
		case 'twig':       return jinja();
		case 'htmlmixed':
		case 'text/html':
		case 'html':       return html();
		case 'css':        return css();
		case 'javascript':
		case 'js':         return javascript();
		case 'json':
		case 'application/json': return json();
		case 'markdown':
		case 'md':         return markdown();
		case 'xml':        return xml();
		case 'php':        return php();
		case 'yaml':
		case 'yml':        return StreamLanguage.define(yaml);
		case 'sql':        return StreamLanguage.define(sql);
		case 'shell':
		case 'bash':       return StreamLanguage.define(shell);
		default:           return [];
	}
}

// ─────────────────────────────────────────────────────────────────────────────
// TotalCMSEditorView — CM5-compatible wrapper around CM6 EditorView
// ─────────────────────────────────────────────────────────────────────────────
class TotalCMSEditorView {

	constructor(parent, options = {}) {
		this._changeListeners = [];
		this._changesListeners = [];

		const langExt = getLanguageExtension(options.mode || 'text');
		const readOnly = options.readOnly || false;

		// Build extensions
		const extensions = [
			history(),
			drawSelection(),
			rectangularSelection(),
			indentOnInput(),
			syntaxHighlighting(twigHighlightStyle),
			highlightSelectionMatches(),
			keymap.of([
				...closeBracketsKeymap,
				...defaultKeymap,
				...searchKeymap,
				...historyKeymap,
				...foldKeymap,
				indentWithTab,
			]),
			// Update listener to fire change callbacks
			EditorView.updateListener.of(update => {
				if (update.docChanged) {
					this._changeListeners.forEach(cb => cb());
					this._changesListeners.forEach(cb => cb());
				}
			}),
		];

		// Configurable options
		if (options.lineNumbers !== false) {
			extensions.push(lineNumbers());
		}
		if (options.foldGutter !== false && options.lineNumbers !== false) {
			extensions.push(foldGutter());
		}
		if (options.matchBrackets !== false) {
			// Use a compartment so bracket matching only activates on focus
			// Exclude <> from bracket matching — HTML/Twig tag matching handles those
			const bracketCompartment = new Compartment();
			extensions.push(bracketCompartment.of([]));
			extensions.push(EditorView.focusChangeEffect.of((state, focusing) => {
				return focusing
					? bracketCompartment.reconfigure(bracketMatching())
					: bracketCompartment.reconfigure([]);
			}));
		}
		if (options.autoCloseBrackets !== false) {
			extensions.push(closeBrackets());
		}
		if (options.styleActiveLine !== false && !readOnly) {
			extensions.push(highlightActiveLine());
		}
		if (options.placeholder) {
			extensions.push(placeholderExt(options.placeholder));
		}
		if (options.lineWrapping !== false) {
			extensions.push(EditorView.lineWrapping);
		}
		if (readOnly) {
			extensions.push(EditorState.readOnly.of(true));
		}
		if (options.tabSize) {
			extensions.push(EditorState.tabSize.of(options.tabSize));
		}

		// Language extension
		if (langExt && (Array.isArray(langExt) ? langExt.length > 0 : true)) {
			extensions.push(langExt);
		}

		// Base theme for consistent sizing
		extensions.push(EditorView.theme({
			'&': {
				fontSize: '14px',
			},
			'.cm-scroller': {
				fontFamily: "'Fira Code', 'Source Code Pro', 'Consolas', monospace",
			},
		}));

		this.view = new EditorView({
			doc: options.value || options.doc || '',
			extensions,
			parent,
		});

		// Store reference on DOM for schemaProperties cloning
		this.view.dom._totalcmsEditor = this;
	}

	getValue() {
		return this.view.state.doc.toString();
	}

	setValue(value) {
		this.view.dispatch({
			changes: { from: 0, to: this.view.state.doc.length, insert: value },
		});
	}

	on(event, callback) {
		if (event === 'change') {
			this._changeListeners.push(callback);
		} else if (event === 'changes') {
			this._changesListeners.push(callback);
		}
	}

	getWrapperElement() {
		return this.view.dom;
	}

	refresh() {
		this.view.requestMeasure();
	}

	focus() {
		this.view.focus();
	}

	destroy() {
		this.view.destroy();
	}

	defaultTextHeight() {
		return this.view.defaultLineHeight;
	}

	lineCount() {
		return this.view.state.doc.lines;
	}

	setSize(width, height) {
		const dom = this.view.dom;
		if (width !== null && width !== undefined) {
			dom.style.width = typeof width === 'number' ? width + 'px' : width;
		}
		if (height !== null && height !== undefined) {
			dom.style.height = typeof height === 'number' ? height + 'px' : height;
		}
	}

	setCursor(line, ch) {
		const lineInfo = this.view.state.doc.line(line + 1); // CM6 lines are 1-based
		const pos = lineInfo.from + (ch || 0);
		this.view.dispatch({ selection: { anchor: pos } });
	}

	getScrollInfo() {
		return {
			height: this.view.contentDOM.scrollHeight,
			clientHeight: this.view.dom.clientHeight,
			top: this.view.scrollDOM.scrollTop,
		};
	}

	// Natural document height, independent of any container min-height/max-height.
	// Use this for auto-resize so the wrapper sizing doesn't feed back into the measurement.
	getContentHeight() {
		return this.view.contentHeight;
	}
}

// ─────────────────────────────────────────────────────────────────────────────
// TotalCMS CodeMirror Configuration Presets
// ─────────────────────────────────────────────────────────────────────────────
const TotalCMSCodeMirror = {

	/**
	 * Create a Twig editor with proper configuration
	 */
	createTwigEditor(parent, options = {}) {
		const editor = new TotalCMSEditorView(parent, {
			mode: 'twig',
			placeholder: options.placeholder || '',
			...options
		});
		editor.getWrapperElement().classList.add('twig-editor');
		return editor;
	},

	/**
	 * Create an HTML editor
	 */
	createHtmlEditor(parent, options = {}) {
		const editor = new TotalCMSEditorView(parent, {
			mode: 'html',
			placeholder: options.placeholder || 'HTML output will appear here...',
			...options
		});
		editor.getWrapperElement().classList.add('html-output-editor');
		return editor;
	},

	/**
	 * Create a CSS editor
	 */
	createCssEditor(parent, options = {}) {
		return new TotalCMSEditorView(parent, {
			mode: 'css',
			placeholder: options.placeholder || 'Enter CSS here...',
			...options
		});
	},

	/**
	 * Create a JavaScript editor
	 */
	createJsEditor(parent, options = {}) {
		return new TotalCMSEditorView(parent, {
			mode: 'javascript',
			placeholder: options.placeholder || 'Enter JavaScript here...',
			...options
		});
	},

	/**
	 * Create a PHP editor
	 */
	createPhpEditor(parent, options = {}) {
		return new TotalCMSEditorView(parent, {
			mode: 'php',
			placeholder: options.placeholder || 'Enter PHP code here...',
			...options
		});
	},

	/**
	 * Create a Markdown editor
	 */
	createMarkdownEditor(parent, options = {}) {
		return new TotalCMSEditorView(parent, {
			mode: 'markdown',
			placeholder: options.placeholder || 'Enter Markdown here...',
			lineWrapping: true,
			...options
		});
	},

	/**
	 * Create a JSON editor (no line numbers or gutters)
	 */
	createJsonEditor(parent, options = {}) {
		const editor = new TotalCMSEditorView(parent, {
			mode: 'json',
			lineNumbers: false,
			foldGutter: false,
			styleActiveLine: false,
			placeholder: options.placeholder || '',
			...options
		});
		editor.getWrapperElement().classList.add('totalform-json-editor');
		return editor;
	},

	/**
	 * Create an XML editor
	 */
	createXmlEditor(parent, options = {}) {
		return new TotalCMSEditorView(parent, {
			mode: 'xml',
			...options
		});
	},

	/**
	 * Create a generic editor with specified mode
	 */
	createEditor(parent, options = {}) {
		return new TotalCMSEditorView(parent, options);
	},

	/**
	 * Create a read-only editor for documentation
	 */
	createReadOnlyEditor(parent, options = {}) {
		return new TotalCMSEditorView(parent, {
			readOnly: true,
			lineWrapping: true,
			styleActiveLine: false,
			...options
		});
	},

	/**
	 * Format HTML using js-beautify
	 */
	formatHtml(html) {
		return beautifyHtml(html, {
			indent_size: 2,
			indent_char: ' ',
			max_preserve_newlines: 2,
			preserve_newlines: true,
			keep_array_indentation: false,
			break_chained_methods: false,
			indent_scripts: 'normal',
			brace_style: 'collapse',
			space_before_conditional: true,
			unescape_strings: false,
			jslint_happy: false,
			end_with_newline: false,
			wrap_line_length: 0,
			indent_inner_html: false,
			comma_first: false,
			e4x: false,
			indent_empty_lines: false
		});
	},

	/**
	 * Save editor content to localStorage
	 */
	saveToStorage(key, content) {
		try {
			localStorage.setItem(key, content);
		} catch (e) {
			console.warn('Could not save to localStorage:', e);
		}
	},

	/**
	 * Load editor content from localStorage
	 */
	loadFromStorage(key, defaultValue = '') {
		try {
			return localStorage.getItem(key) || defaultValue;
		} catch (e) {
			console.warn('Could not load from localStorage:', e);
			return defaultValue;
		}
	}
};

// Export for global use
window.TotalCMSCodeMirror = TotalCMSCodeMirror;
window.beautifyHtml = beautifyHtml;

export default TotalCMSCodeMirror;
export { TotalCMSEditorView };
