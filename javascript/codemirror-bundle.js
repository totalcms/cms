/**
 * CodeMirror Bundle - Complete syntax highlighting solution
 * Includes all modes, themes, and addons needed for TotalCMS
 */

// Core CodeMirror
import CodeMirror from 'codemirror';
import 'codemirror/lib/codemirror.css';

// Themes
import 'codemirror/theme/monokai.css';
import 'codemirror/theme/material.css';
import 'codemirror/theme/material-darker.css';
import 'codemirror/theme/material-palenight.css';
import 'codemirror/theme/dracula.css';
import 'codemirror/theme/darcula.css';
import 'codemirror/theme/eclipse.css';
import 'codemirror/theme/elegant.css';
import 'codemirror/theme/neat.css';

// Language modes
import 'codemirror/mode/xml/xml.js';           // HTML/XML
import 'codemirror/mode/javascript/javascript.js';
import 'codemirror/mode/css/css.js';
import 'codemirror/mode/htmlmixed/htmlmixed.js';
import 'codemirror/mode/twig/twig.js';         // Twig templates
import 'codemirror/mode/php/php.js';           // PHP
import 'codemirror/mode/markdown/markdown.js'; // Markdown
import 'codemirror/mode/yaml/yaml.js';         // YAML
import 'codemirror/mode/sql/sql.js';           // SQL
import 'codemirror/mode/shell/shell.js';       // Shell scripts

// Addons for enhanced functionality
import 'codemirror/addon/edit/closebrackets.js';
import 'codemirror/addon/edit/matchbrackets.js';
import 'codemirror/addon/edit/closetag.js';
import 'codemirror/addon/mode/multiplex.js';

// Code folding
import 'codemirror/addon/fold/foldcode.js';
import 'codemirror/addon/fold/foldgutter.js';
import 'codemirror/addon/fold/foldgutter.css';
import 'codemirror/addon/fold/xml-fold.js';
import 'codemirror/addon/fold/markdown-fold.js';
import 'codemirror/addon/fold/comment-fold.js';

// Search and replace
import 'codemirror/addon/search/search.js';
import 'codemirror/addon/search/searchcursor.js';
import 'codemirror/addon/dialog/dialog.js';
import 'codemirror/addon/dialog/dialog.css';

// Selection enhancements
import 'codemirror/addon/selection/active-line.js';
import 'codemirror/addon/selection/mark-selection.js';

// Display enhancements
import 'codemirror/addon/display/fullscreen.js';
import 'codemirror/addon/display/fullscreen.css';
import 'codemirror/addon/display/placeholder.js';

// Import js-beautify for HTML formatting
import { html as beautifyHtml } from 'js-beautify';

// Export everything for global use
window.CodeMirror = CodeMirror;
window.beautifyHtml = beautifyHtml;

/**
 * TotalCMS CodeMirror Configuration Presets
 */
const TotalCMSCodeMirror = {
	/**
	 * Default configuration for editors
	 */
	defaultConfig: {
		lineNumbers: true,
		autoCloseBrackets: true,
		matchBrackets: true,
		styleActiveLine: true,
		foldGutter: true,
		gutters: ['CodeMirror-linenumbers', 'CodeMirror-foldgutter'],
		extraKeys: {
			'Ctrl-Space': 'autocomplete',
			'F11': function(cm) {
				cm.setOption('fullScreen', !cm.getOption('fullScreen'));
			},
			'Esc': function(cm) {
				if (cm.getOption('fullScreen')) cm.setOption('fullScreen', false);
			}
		}
	},

	/**
	 * Create a Twig editor with proper configuration
	 */
	createTwigEditor: function(element, options = {}) {
		const config = {
			...this.defaultConfig,
			mode: 'twig',
			theme: options.theme || 'elegant',
			placeholder: options.placeholder || 'Enter your Twig template here...',
			...options
		};
		const editor = CodeMirror.fromTextArea(element, config);
		
		// Add twig-editor class for custom styling
		editor.getWrapperElement().classList.add('twig-editor');
		
		return editor;
	},

	/**
	 * Create an HTML editor with proper configuration
	 */
	createHtmlEditor: function(element, options = {}) {
		const config = {
			...this.defaultConfig,
			mode: 'htmlmixed',
			theme: options.theme || 'elegant',
			autoCloseTags: true,
			placeholder: options.placeholder || 'HTML output will appear here...',
			readOnly: options.readOnly || false,
			...options
		};
		const editor = CodeMirror.fromTextArea(element, config);
		
		// Add html-output-editor class for custom styling
		editor.getWrapperElement().classList.add('html-output-editor');
		
		return editor;
	},

	/**
	 * Create a CSS editor
	 */
	createCssEditor: function(element, options = {}) {
		const config = {
			...this.defaultConfig,
			mode: 'css',
			theme: options.theme || 'elegant',
			placeholder: options.placeholder || 'Enter CSS here...',
			...options
		};
		return CodeMirror.fromTextArea(element, config);
	},

	/**
	 * Create a JavaScript editor
	 */
	createJsEditor: function(element, options = {}) {
		const config = {
			...this.defaultConfig,
			mode: 'javascript',
			theme: options.theme || 'elegant',
			placeholder: options.placeholder || 'Enter JavaScript here...',
			...options
		};
		return CodeMirror.fromTextArea(element, config);
	},

	/**
	 * Create a PHP editor
	 */
	createPhpEditor: function(element, options = {}) {
		const config = {
			...this.defaultConfig,
			mode: 'php',
			theme: options.theme || 'elegant',
			placeholder: options.placeholder || 'Enter PHP code here...',
			...options
		};
		return CodeMirror.fromTextArea(element, config);
	},

	/**
	 * Create a Markdown editor
	 */
	createMarkdownEditor: function(element, options = {}) {
		const config = {
			...this.defaultConfig,
			mode: 'markdown',
			theme: options.theme || 'elegant',
			placeholder: options.placeholder || 'Enter Markdown here...',
			lineWrapping: true,
			...options
		};
		return CodeMirror.fromTextArea(element, config);
	},

	/**
	 * Format HTML using js-beautify
	 */
	formatHtml: function(html) {
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
	saveToStorage: function(key, content) {
		try {
			localStorage.setItem(key, content);
		} catch (e) {
			console.warn('Could not save to localStorage:', e);
		}
	},

	/**
	 * Load editor content from localStorage
	 */
	loadFromStorage: function(key, defaultValue = '') {
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

export default TotalCMSCodeMirror;