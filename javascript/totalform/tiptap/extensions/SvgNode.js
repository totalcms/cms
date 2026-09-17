/**
 * SvgInline / SvgBlock
 * Pass hand-authored <svg> through the editor untouched.
 *
 * ProseMirror drops any element no node or mark claims, so inline SVG icons
 * vanished on the first save (customer report, 2026-09-16). The markup is kept
 * verbatim as a string attribute and rendered back as a real element; the
 * editor never edits inside it. Two node types because a ProseMirror node is
 * either inline or block: the inline rule wins inside a paragraph or heading,
 * the block rule everywhere else, so a callout's icon stays a direct child of
 * its <div> instead of being wrapped in a paragraph.
 */

import { Node } from '@tiptap/core';

const INLINE_PARENTS = 'paragraph/|heading/';

function svgAttrs(element) {
	return { html: element.outerHTML };
}

function renderSvg(node) {
	const template = document.createElement('template');
	template.innerHTML = node.attrs.html || '<svg></svg>';
	return template.content.firstElementChild || document.createElement('span');
}

const attributes = () => ({
	html: {
		default: null,
		// Serialised by renderHTML from the stored markup; never as an attribute.
		renderHTML: () => ({}),
	},
});

export const SvgInline = Node.create({
	name: 'svgInline',
	group: 'inline',
	inline: true,
	atom: true,
	selectable: true,

	addAttributes: attributes,

	parseHTML() {
		return [{ tag: 'svg', context: INLINE_PARENTS, getAttrs: svgAttrs }];
	},

	renderHTML({ node }) {
		return renderSvg(node);
	},
});

export const SvgBlock = Node.create({
	name: 'svgBlock',
	group: 'block',
	atom: true,
	selectable: true,
	draggable: true,

	addAttributes: attributes,

	parseHTML() {
		return [{ tag: 'svg', getAttrs: svgAttrs }];
	},

	renderHTML({ node }) {
		return renderSvg(node);
	},
});
