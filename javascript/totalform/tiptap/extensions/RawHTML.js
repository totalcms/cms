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
	content: 'inline*',
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

	addNodeView() {
		return ({ node }) => {
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

			// Label showing the tag name
			const label = document.createElement('span');
			label.className = 'ste-raw-html-label';
			label.textContent = `<${tagName}>`;
			dom.appendChild(label);

			// Content area
			const contentDOM = document.createElement('div');
			contentDOM.className = 'ste-raw-html-content';
			dom.appendChild(contentDOM);

			return { dom, contentDOM };
		};
	},
});

export default RawHTML;
