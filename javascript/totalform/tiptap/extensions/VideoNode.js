/**
 * VideoNode - Custom TipTap node for HTML5 <video> elements.
 * Registers the video tag in the ProseMirror schema so insertContent works.
 */

import { Node, mergeAttributes } from '@tiptap/core';

const VideoNode = Node.create({
	name: 'video',

	group: 'block',

	atom: true,

	draggable: true,

	addAttributes() {
		return {
			src: { default: null },
			controls: { default: true },
			class: { default: 'cms-video-embed' },
		};
	},

	parseHTML() {
		return [{ tag: 'video' }];
	},

	renderHTML({ HTMLAttributes }) {
		return ['video', mergeAttributes(this.options.HTMLAttributes, HTMLAttributes)];
	},

	addCommands() {
		return {
			setVideo: (attrs) => ({ chain }) => {
				return chain().insertContent({
					type: this.name,
					attrs,
				}).run();
			},
		};
	},
});

export default VideoNode;
