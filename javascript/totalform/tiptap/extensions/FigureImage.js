/**
 * FigureImage Extension
 * Wraps an image in <figure> with optional <figcaption>.
 * Supports float (left/right/none) and size (25%/50%/100%) attributes.
 */

import { Node, mergeAttributes } from '@tiptap/core';
import { Plugin } from '@tiptap/pm/state';

const FigureImage = Node.create({
	name: 'figure',

	group: 'block',

	content: 'inline*',

	draggable: true,

	isolating: true,

	addAttributes() {
		return {
			src: { default: null },
			alt: { default: null },
			title: { default: null },
			float: { default: null },
			size: { default: null },
			caption: { default: null },
		};
	},

	parseHTML() {
		return [
			{
				tag: 'figure[data-type="figure-image"]',
				getAttrs(dom) {
					const img = dom.querySelector('img');
					const figcaption = dom.querySelector('figcaption');
					return {
						src: img?.getAttribute('src'),
						alt: img?.getAttribute('alt'),
						title: img?.getAttribute('title'),
						float: dom.getAttribute('data-float'),
						size: dom.getAttribute('data-size'),
						caption: figcaption?.textContent || null,
					};
				},
			},
			// Also parse plain <figure> containing an <img>
			{
				tag: 'figure',
				getAttrs(dom) {
					const img = dom.querySelector('img');
					if (!img) return false;
					const figcaption = dom.querySelector('figcaption');
					return {
						src: img.getAttribute('src'),
						alt: img.getAttribute('alt'),
						title: img.getAttribute('title'),
						float: dom.getAttribute('data-float'),
						size: dom.getAttribute('data-size'),
						caption: figcaption?.textContent || null,
					};
				},
			},
		];
	},

	renderHTML({ HTMLAttributes }) {
		const { src, alt, title, float: floatVal, size, caption, ...rest } = HTMLAttributes;

		const figureAttrs = {
			'data-type': 'figure-image',
			...rest,
		};
		if (floatVal) figureAttrs['data-float'] = floatVal;
		if (size) figureAttrs['data-size'] = size;

		// Build class string
		const classes = ['ste-figure'];
		if (floatVal) classes.push(`ste-figure--${floatVal}`);
		if (size) classes.push(`ste-figure--${size}`);
		figureAttrs.class = classes.join(' ');

		const imgAttrs = { src };
		if (alt) imgAttrs.alt = alt;
		if (title) imgAttrs.title = title;

		if (caption) {
			return ['figure', mergeAttributes(figureAttrs), ['img', imgAttrs], ['figcaption', {}, caption]];
		}

		return ['figure', mergeAttributes(figureAttrs), ['img', imgAttrs]];
	},

	addCommands() {
		return {
			/**
			 * Convert a plain image node to a figure node (or insert a new figure).
			 */
			setFigure: (attrs) => ({ chain }) => {
				return chain().insertContent({
					type: this.name,
					attrs,
				}).run();
			},

			/**
			 * Convert the selected image to a figure or update figure attributes.
			 */
			wrapImageInFigure: (attrs) => ({ tr, state, dispatch }) => {
				const { selection } = state;
				const node = state.doc.nodeAt(selection.from);

				if (!node) return false;

				// If it's a plain image, replace with figure
				if (node.type.name === 'image') {
					if (dispatch) {
						const figureNode = state.schema.nodes.figure.create({
							src: node.attrs.src,
							alt: node.attrs.alt,
							title: node.attrs.title,
							...attrs,
						});
						tr.replaceWith(selection.from, selection.from + node.nodeSize, figureNode);
					}
					return true;
				}

				// If it's already a figure, update attrs
				if (node.type.name === 'figure') {
					if (dispatch) {
						const newAttrs = { ...node.attrs, ...attrs };
						tr.setNodeMarkup(selection.from, undefined, newAttrs);
					}
					return true;
				}

				return false;
			},

			/**
			 * Unwrap a figure back to a plain image.
			 */
			unwrapFigureToImage: () => ({ tr, state, dispatch }) => {
				const { selection } = state;
				const node = state.doc.nodeAt(selection.from);

				if (!node || node.type.name !== 'figure') return false;

				if (dispatch) {
					const imageNode = state.schema.nodes.image.create({
						src: node.attrs.src,
						alt: node.attrs.alt,
						title: node.attrs.title,
					});
					tr.replaceWith(selection.from, selection.from + node.nodeSize, imageNode);
				}

				return true;
			},

			/**
			 * Update attributes on a figure or image node.
			 */
			updateImageAttrs: (attrs) => ({ tr, state, dispatch }) => {
				const { selection } = state;
				const pos = selection.from;
				const node = state.doc.nodeAt(pos);

				if (!node) return false;

				if (node.type.name === 'figure' || node.type.name === 'image') {
					if (dispatch) {
						tr.setNodeMarkup(pos, undefined, { ...node.attrs, ...attrs });
					}
					return true;
				}

				return false;
			},
		};
	},

	addProseMirrorPlugins() {
		return [
			new Plugin({
				props: {
					// Allow selecting figure nodes on click
					handleClickOn(view, pos, node) {
						if (node.type.name === 'figure') {
							return false;
						}
						return false;
					},
				},
			}),
		];
	},
});

export default FigureImage;
