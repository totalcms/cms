/**
 * GlobalAttributes Extension
 * Adds class and id attributes to block-level nodes so they survive
 * the code-view round-trip instead of being stripped by the schema.
 */

import { Extension } from '@tiptap/core';

const BLOCK_TYPES = [
	'heading',
	'paragraph',
	'blockquote',
	'listItem',
	'bulletList',
	'orderedList',
	'codeBlock',
	'table',
	'tableRow',
	'tableCell',
	'tableHeader',
];

const GlobalAttributes = Extension.create({
	name: 'globalAttributes',

	addGlobalAttributes() {
		return [
			{
				types: BLOCK_TYPES,
				attributes: {
					class: {
						default: null,
						parseHTML: (element) => element.getAttribute('class') || null,
						renderHTML: (attributes) => {
							if (!attributes.class) return {};
							return { class: attributes.class };
						},
					},
					id: {
						default: null,
						parseHTML: (element) => element.getAttribute('id') || null,
						renderHTML: (attributes) => {
							if (!attributes.id) return {};
							return { id: attributes.id };
						},
					},
					dataAttrs: {
						default: null,
						parseHTML: (element) => {
							const data = {};
							for (const attr of element.attributes) {
								if (attr.name.startsWith('data-')) {
									data[attr.name] = attr.value;
								}
							}
							return Object.keys(data).length > 0 ? JSON.stringify(data) : null;
						},
						renderHTML: (attributes) => {
							if (!attributes.dataAttrs) return {};
							try {
								return JSON.parse(attributes.dataAttrs);
							} catch {
								return {};
							}
						},
					},
				},
			},
		];
	},
});

export default GlobalAttributes;
