/**
 * Public Depot Browser - file browser component for content pages
 */

import TreeView from './tree-view.js';

const previewTypes = {
	image: new Set(['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg']),
	video: new Set(['mp4', 'webm', 'ogg']),
	audio: new Set(['mp3', 'wav']),
	pdf:   new Set(['pdf']),
};

function isPreviewable(ext) {
	return Object.values(previewTypes).some(s => s.has(ext));
}

function createPreviewElement(ext, url, name) {
	if (previewTypes.image.has(ext)) {
		const img = document.createElement("img");
		img.src = url;
		img.alt = name;
		return img;
	}
	if (previewTypes.video.has(ext)) {
		const video = document.createElement("video");
		video.src = url;
		video.controls = true;
		video.autoplay = true;
		return video;
	}
	if (previewTypes.audio.has(ext)) {
		const audio = document.createElement("audio");
		audio.src = url;
		audio.controls = true;
		audio.autoplay = true;
		return audio;
	}
	if (previewTypes.pdf.has(ext)) {
		const obj = document.createElement("object");
		obj.data = url;
		obj.type = "application/pdf";
		obj.className = "preview-pdf";
		return obj;
	}
	return null;
}

function initBrowser(container) {
	const options     = JSON.parse(container.dataset.settings || "{}");
	const filterInput = container.querySelector(".depot-browser-filter input");
	const dialog      = container.querySelector(".depot-browser-preview");
	const preview     = dialog?.querySelector(".preview-content");

	new TreeView(container, {
		treeSelector     : ".depot-browser-tree",
		ownTextSelector  : ".file",
		searchableExtras : [".file-comments", ".file-tags"],
		filterInput,
	});

	if (options.preview && dialog) {
		container.querySelectorAll(".action-preview").forEach(btn => {
			const item = btn.closest(".depot-browser-item");
			const ext  = item?.dataset.ext;
			if (!ext || !isPreviewable(ext)) {
				btn.remove();
				return;
			}

			btn.addEventListener("click", () => {
				const url  = item.dataset.streamUrl;
				const name = item.querySelector(".file")?.textContent || "";
				preview.innerHTML = "";
				const el = createPreviewElement(ext, url, name);
				if (!el) return;
				preview.appendChild(el);
				dialog.showModal();
			});
		});

		dialog.addEventListener("close", () => {
			preview.innerHTML = "";
		});

		dialog.addEventListener("click", (e) => {
			if (e.target === dialog) dialog.close();
		});
	}
}

export default function initDepotBrowsers() {
	document.querySelectorAll(".cms-depot-browser").forEach(initBrowser);
}
