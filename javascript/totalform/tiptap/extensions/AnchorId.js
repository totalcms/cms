/**
 * AnchorId Extension
 * Adds an `id` attribute to block-level nodes (headings, paragraphs, etc.)
 * for use as anchor link targets.
 */

import { Extension } from '@tiptap/core';

const AnchorId = Extension.create({
	name: 'anchorId',

	addGlobalAttributes() {
		return [
			{
				types: ['heading', 'paragraph', 'blockquote', 'codeBlock', 'rawHtmlBlock'],
				attributes: {
					id: {
						default: null,
						parseHTML: (element) => element.getAttribute('id') || null,
						renderHTML: (attributes) => {
							if (!attributes.id) return {};
							return { id: attributes.id };
						},
					},
				},
			},
		];
	},

	addCommands() {
		return {
			setAnchorId: (id) => ({ tr, state, dispatch }) => {
				const { $from } = state.selection;
				const node = $from.node($from.depth);

				// Find the closest block node that supports the id attribute
				for (let depth = $from.depth; depth >= 1; depth--) {
					const pos = $from.before(depth);
					const blockNode = state.doc.nodeAt(pos);
					if (blockNode && blockNode.type.spec.attrs?.id !== undefined) {
						if (dispatch) {
							tr.setNodeMarkup(pos, null, {
								...blockNode.attrs,
								id: id || null,
							});
						}
						return true;
					}
				}
				return false;
			},

			removeAnchorId: () => ({ commands }) => {
				return commands.setAnchorId(null);
			},
		};
	},
});

export default AnchorId;
