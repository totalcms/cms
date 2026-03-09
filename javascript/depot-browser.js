/**
 * Public Depot Browser - file browser component for content pages
 */

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

function applyStripes(container) {
	const tree = container.querySelector(".depot-browser-tree");
	if (!tree) return;

	let index = 0;
	const walk = (ul) => {
		for (const li of ul.children) {
			if (li.tagName !== "LI") continue;
			if (li.classList.contains("filtered-out")) {
				li.classList.remove("stripe");
				continue;
			}
			li.classList.toggle("stripe", index % 2 === 1);
			index++;

			// Only walk into open folder contents
			const details = li.querySelector(":scope > details");
			if (details?.open) {
				const nested = details.querySelector(":scope > .depot-browser-tree");
				if (nested) walk(nested);
			}
		}
	};
	walk(tree);
}

function filterBrowser(container, input) {
	const query = input.value.toLowerCase();
	const tree  = container.querySelector(".depot-browser-tree");
	const allLi = tree.querySelectorAll("li");

	if (query.length === 0) {
		allLi.forEach(li => li.classList.remove("filtered-out"));
		applyStripes(container);
		return;
	}

	// First pass: filter file items by name, comments, and tags
	allLi.forEach(li => {
		if (li.querySelector("details")) return; // folder
		const fileEl = li.querySelector(".file");
		if (!fileEl) return;

		let text = fileEl.textContent;
		const comments = li.querySelector(".file-comments");
		if (comments) text += ' ' + comments.textContent;
		const tags = li.querySelector(".file-tags");
		if (tags) text += ' ' + tags.textContent;

		const match = text.toLowerCase().includes(query);
		li.classList.toggle("filtered-out", !match);
	});

	// Second pass: filter folders based on visible children
	const filterFolders = (ul) => {
		for (const li of ul.children) {
			if (li.tagName !== "LI" || !li.querySelector("details")) continue;
			const contents = li.querySelector(".depot-browser-tree");
			if (contents) filterFolders(contents);

			const hasVisible = contents && Array.from(contents.children).some(
				child => child.tagName === "LI" && !child.classList.contains("filtered-out")
			);
			li.classList.toggle("filtered-out", !hasVisible);
			if (hasVisible) {
				li.querySelector("details")?.setAttribute("open", "");
			}
		}
	};
	filterFolders(tree);
	applyStripes(container);
}

function initBrowser(container) {
	const options     = JSON.parse(container.dataset.settings || "{}");
	const filterInput = container.querySelector(".depot-browser-filter input");
	const dialog      = container.querySelector(".depot-browser-preview");
	const preview     = dialog?.querySelector(".preview-content");

	// Alternating row stripes
	applyStripes(container);

	// Re-stripe when folders are toggled
	container.querySelectorAll("details").forEach(details => {
		details.addEventListener("toggle", () => applyStripes(container));
	});

	// Filter — "input" for typing, "search" for native clear button
	if (filterInput) {
		const onFilter = () => filterBrowser(container, filterInput);
		filterInput.addEventListener("input", onFilter);
		filterInput.addEventListener("search", onFilter);
	}

	// Preview
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
