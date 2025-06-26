/**
 * Documentation Syntax Highlighting
 * Auto-applies syntax highlighting to code blocks in documentation
 */

import TotalCMSCodeMirror from './codemirror-bundle.js';

// Auto-initialize syntax highlighting for documentation
document.addEventListener('DOMContentLoaded', function() {
	initializeDocumentationHighlighting();
});

function initializeDocumentationHighlighting() {
	// Find all code blocks that need syntax highlighting
	const codeBlocks = document.querySelectorAll('pre code[data-lang], pre[data-lang], code[data-syntax]');
	
	codeBlocks.forEach(block => {
		const lang = block.dataset.lang || block.dataset.syntax || 'text';
		const content = block.textContent || block.innerText;
		
		// Skip if already processed
		if (block.classList.contains('syntax-highlighted')) {
			return;
		}
		
		// Create a div to replace the code block
		const editorDiv = document.createElement('div');
		editorDiv.className = 'documentation-code-block';
		
		// Insert the new div after the code block
		block.parentNode.insertBefore(editorDiv, block.nextSibling);
		
		// Hide the original code block
		block.style.display = 'none';
		block.classList.add('syntax-highlighted');
		
		// Map language names to CodeMirror modes
		const modeMap = {
			'twig': 'twig',
			'html': 'htmlmixed',
			'xml': 'xml',
			'php': 'php',
			'js': 'javascript',
			'javascript': 'javascript',
			'css': 'css',
			'scss': 'css',
			'sass': 'css',
			'yaml': 'yaml',
			'yml': 'yaml',
			'sql': 'sql',
			'shell': 'shell',
			'bash': 'shell',
			'markdown': 'markdown',
			'md': 'markdown',
			'json': 'javascript'
		};
		
		const mode = modeMap[lang.toLowerCase()] || 'text';
		
		// Create the CodeMirror editor
		const editor = window.CodeMirror(editorDiv, {
			value: content,
			mode: mode,
			theme: 'elegant',
			readOnly: true,
			lineNumbers: true,
			lineWrapping: true,
			foldGutter: false,
			gutters: ["CodeMirror-linenumbers"],
			matchBrackets: true
		});
		
		// Auto-size to content
		const lineHeight = editor.defaultTextHeight();
		const lineCount = editor.lineCount();
		const totalHeight = Math.max((lineCount * lineHeight) + 20, 60);
		
		editor.setSize(null, totalHeight);
		
		// Add a label showing the language
		if (lang && lang !== 'text') {
			const label = document.createElement('div');
			label.className = 'code-language-label';
			label.textContent = lang.toUpperCase();
			editorDiv.appendChild(label);
		}
	});
}

// Export for manual use
window.initializeDocumentationHighlighting = initializeDocumentationHighlighting;