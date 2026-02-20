/**
 * AudioNode - Custom TipTap node for HTML5 <audio> elements.
 * Registers the audio tag in the ProseMirror schema so insertContent works.
 */

import { Node, mergeAttributes } from '@tiptap/core';

const AudioNode = Node.create({
	name: 'audio',

	group: 'block',

	atom: true,

	draggable: true,

	addAttributes() {
		return {
			src: { default: null },
			controls: { default: true },
			class: { default: 'cms-audio-embed' },
		};
	},

	parseHTML() {
		return [{ tag: 'audio' }];
	},

	renderHTML({ HTMLAttributes }) {
		return ['audio', mergeAttributes(this.options.HTMLAttributes, HTMLAttributes)];
	},

	addCommands() {
		return {
			setAudio: (attrs) => ({ chain }) => {
				return chain().insertContent({
					type: this.name,
					attrs,
				}).run();
			},
		};
	},
});

export default AudioNode;
