/**
 * RawHTML Extension
 * Custom node for preserving unknown HTML elements with editable text content.
 * Block-level unknown elements get wrapped in a rawHtmlBlock node that preserves
 * the original tag name and all attributes.
 */

import { Node, mergeAttributes } from '@tiptap/core';

const RawHTML = Node.create({
	name: 'rawHtmlBlock',
	group: 'block',
	content: 'block+',
	defining: true,

	addAttributes() {
		return {
			tagName: {
				default: 'div',
			},
			htmlAttrs: {
				default: '{}',
				parseHTML: (element) => JSON.stringify(
					Array.from(element.attributes).reduce((attrs, attr) => {
						attrs[attr.name] = attr.value;
						return attrs;
					}, {})
				),
			},
		};
	},

	parseHTML() {
		// Match block-level elements that aren't handled by other extensions
		return [
			{ tag: 'section' },
			{ tag: 'aside' },
			{ tag: 'article' },
			{ tag: 'nav' },
			{ tag: 'header' },
			{ tag: 'footer' },
			{ tag: 'main' },
			{ tag: 'figure' },
			{ tag: 'figcaption' },
			{ tag: 'details' },
			{ tag: 'summary' },
			{ tag: 'div[class]' },
			{ tag: 'div[id]' },
		];
	},

	renderHTML({ node, HTMLAttributes }) {
		const tagName = HTMLAttributes.tagName || node.attrs.tagName || 'div';
		let attrs = {};

		try {
			attrs = JSON.parse(node.attrs.htmlAttrs || '{}');
		} catch {
			attrs = {};
		}

		// Remove our internal attrs
		delete attrs.tagName;

		return [tagName, mergeAttributes(attrs), 0];
	},

	addCommands() {
		return {
			unwrapRawHtmlBlock: () => ({ tr, state, dispatch }) => {
				const { $from } = state.selection;
				for (let depth = $from.depth; depth >= 1; depth--) {
					const node = $from.node(depth);
					if (node.type.name === 'rawHtmlBlock') {
						if (dispatch) {
							const pos = $from.before(depth);
							const end = pos + node.nodeSize;
							// Replace the wrapper with its children
							tr.replaceWith(pos, end, node.content);
						}
						return true;
					}
				}
				return false;
			},
		};
	},

	addNodeView() {
		return ({ node, editor, getPos }) => {
			const tagName = node.attrs.tagName || 'div';
			let attrs = {};

			try {
				attrs = JSON.parse(node.attrs.htmlAttrs || '{}');
			} catch {
				attrs = {};
			}

			const dom = document.createElement('div');
			dom.className = 'ste-raw-html-block';
			dom.dataset.rawTag = tagName;

			// Label bar
			const labelBar = document.createElement('div');
			labelBar.className = 'ste-raw-html-label';

			const snippetLabel = attrs['data-label'];
			const labelText = document.createElement('span');
			labelText.textContent = snippetLabel || `<${tagName}>`;
			labelBar.appendChild(labelText);

			// Remove/unwrap button
			const removeBtn = document.createElement('button');
			removeBtn.type = 'button';
			removeBtn.className = 'ste-raw-html-remove';
			removeBtn.title = 'Unwrap element';
			removeBtn.setAttribute('aria-label', 'Unwrap element');
			removeBtn.textContent = '\u00d7';
			removeBtn.addEventListener('click', (e) => {
				e.preventDefault();
				editor.commands.unwrapRawHtmlBlock();
			});
			labelBar.appendChild(removeBtn);

			dom.appendChild(labelBar);

			// Content area
			const contentDOM = document.createElement('div');
			contentDOM.className = 'ste-raw-html-content';
			dom.appendChild(contentDOM);

			return { dom, contentDOM };
		};
	},
});

export default RawHTML;
