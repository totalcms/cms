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

function filterBrowser(container, input, resetBtn) {
	const query = input.value.toLowerCase();
	resetBtn?.classList.toggle("cms-hide", query.length === 0);

	const tree  = container.querySelector(".depot-browser-tree");
	const allLi = tree.querySelectorAll("li");

	if (query.length === 0) {
		allLi.forEach(li => li.classList.remove("filtered-out"));
		return;
	}

	// First pass: filter file items
	allLi.forEach(li => {
		if (li.querySelector("details")) return; // folder
		const fileEl = li.querySelector(".file");
		if (!fileEl) return;
		const match = fileEl.textContent.toLowerCase().includes(query);
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
}

function initBrowser(container) {
	const options     = JSON.parse(container.dataset.options || "{}");
	const filterInput = container.querySelector(".depot-browser-filter input");
	const filterReset = container.querySelector(".depot-browser-filter-reset");
	const dialog      = container.querySelector(".depot-browser-preview");
	const preview     = dialog?.querySelector(".preview-content");

	// Filter
	if (filterInput) {
		filterInput.addEventListener("input", () => filterBrowser(container, filterInput, filterReset));
		filterReset?.addEventListener("click", () => {
			filterInput.value = "";
			filterBrowser(container, filterInput, filterReset);
		});
	}

	// Preview
	if (options.preview && dialog) {
		container.querySelectorAll(".depot-browser-item").forEach(item => {
			const ext = item.dataset.ext;
			if (!isPreviewable(ext)) return;

			item.classList.add("previewable");
			item.addEventListener("click", (e) => {
				if (e.target.closest("a[download]")) return;
				e.preventDefault();
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
