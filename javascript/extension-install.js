import Dropzone from "@deltablot/dropzone";
import { csrfHeaders } from "./csrf";

//-----------------------------------------------
// Extensions page: install from a zip
//-----------------------------------------------
// The "Install extension" panel holds one drop zone. Dropping a zip (or
// choosing one) uploads it at once to POST {api}/extensions/install, which
// answers with where to go next: the review page for a new extension (it
// forwards to this page, highlighting the extension, when there is nothing
// to review) or this page for an update. Errors, warnings and a "disabled
// for review" update are shown in the panel; all text is set with
// textContent, so server text is never parsed as HTML.

const RESET_DELAY = 1500;
const WARNING_DELAY = 4000;

function setupToggle(toggle, panel, drop) {
	toggle.addEventListener("click", () => {
		const open = panel.hasAttribute("hidden");
		panel.toggleAttribute("hidden", !open);
		toggle.setAttribute("aria-expanded", open ? "true" : "false");
		if (open) drop?.focus();
	});
}

function setupDropzone(panel, drop) {
	const preview  = panel.querySelector(".total-preview");
	const template = panel.querySelector("template.ext-install-template");
	const messages = panel.querySelector(".ext-install-messages");
	const api      = drop.dataset.api ?? panel.dataset.api ?? "";
	if (!preview || !template || !messages) return;

	// True from the moment a file is accepted until the page leaves or an
	// upload fails: a second drop meanwhile is ignored. The drop zone shows
	// the state (aria-disabled + muted style) so an ignored drop is not a
	// mystery.
	let busy = false;
	const setBusy = value => {
		busy = value;
		drop.setAttribute("aria-disabled", value ? "true" : "false");
	};

	// Bumped for every accepted file, so the delayed clean-up of a failed
	// upload never cancels a newer one dropped in the meantime.
	let generation = 0;

	const line = (className, text, role) => {
		const p = document.createElement("p");
		p.className = className;
		if (role) p.setAttribute("role", role);
		p.textContent = text;
		messages.appendChild(p);
	};

	const dropzone = new Dropzone(drop, {
		url                  : `${api}/extensions/install`,
		method               : "post",
		paramName            : "extension",
		acceptedFiles        : ".zip,application/zip",
		maxFiles             : 1,
		autoProcessQueue     : true,
		chunking             : false,
		parallelUploads      : 1,
		headers              : csrfHeaders(),
		clickable            : drop,
		previewsContainer    : preview,
		previewTemplate      : template.innerHTML,
		createImageThumbnails: false,
		addedfile            : file => addedFile(file),
		// Runs after Dropzone's own type and maxFiles checks; catches a file
		// dropped while an earlier one is still being failed or cleared.
		accept               : (file, done) => (file.ignored ? done("ignored") : done()),
		error                : (file, message, xhr) => failed(file, message, xhr),
	});

	// Single mode, like Droplet.event_addedfile: the previous preview goes,
	// the new one is appended. While an upload is running the new file is
	// flagged and dropped again in failed() when maxFiles rejects it.
	function addedFile(file) {
		if (busy) {
			file.ignored = true;
			return;
		}
		setBusy(true);
		generation++;

		dropzone.files.filter(f => f !== file).forEach(f => dropzone.removeFile(f));
		preview.replaceChildren();
		messages.replaceChildren();

		file.previewElement  = Dropzone.createElement(template.innerHTML.trim());
		file.previewTemplate = file.previewElement;
		const name = file.previewElement.querySelector("[data-dz-name]");
		const size = file.previewElement.querySelector("[data-dz-size]");
		if (name) name.textContent = file.name;
		if (size) size.textContent = dropzone.filesize(file.size).replace(/<[^>]*>/g, "");
		preview.appendChild(file.previewElement);
	}

	// When the server answered JSON, `message` is the parsed body
	// ({error, code}). A string is Dropzone's own (wrong file type, its
	// generic "Server responded with N code.") or the body of a non-JSON
	// page — a proxy or web-server error, which is markup, not a sentence.
	function errorText(message, xhr) {
		if (message && typeof message === "object") {
			const error = message.error;
			if (typeof error === "string" && error !== "") return error;
			if (error && typeof error.message === "string" && error.message !== "") return error.message;
			return xhr?.status ? `HTTP ${xhr.status}` : "The install failed.";
		}

		const text = typeof message === "string" ? message.trim() : "";
		if (xhr) {
			if (xhr.status === 0) return "The upload failed: the server could not be reached.";
			const generic = dropzone.options.dictResponseError.replace("{{statusCode}}", xhr.status);
			const isJson  = (xhr.getResponseHeader("content-type") || "").includes("application/json");
			if (text === "" || text === generic || (!isJson && (text.includes("<") || text.length > 300))) {
				return xhr.status === 413
					? "HTTP 413: the zip is larger than this server accepts."
					: `HTTP ${xhr.status}${xhr.statusText ? " " + xhr.statusText : ""}`;
			}
		}

		return text || "The install failed.";
	}

	function failed(file, message, xhr) {
		if (file.ignored) {
			dropzone.removeFile(file);
			return;
		}

		messages.replaceChildren();
		line("ext-install-error", errorText(message, xhr), "alert");

		if (file.previewElement) {
			// A non-JSON 2xx arrives here after Dropzone's own success
			// handler has already marked the card as a success.
			file.previewElement.classList.remove("dz-processing", "dz-success");
			file.previewElement.classList.add("dz-error");
		}

		// Clear the failed file shortly so Dropzone's maxFiles count is reset;
		// the drop zone takes a new file straight away (addedFile drops the
		// failed one itself). The message stays until the next drop.
		const failedGeneration = generation;
		setTimeout(() => {
			if (failedGeneration === generation) dropzone.removeAllFiles(true);
		}, RESET_DELAY);
		setBusy(false);
	}

	dropzone.on("success", (file, response) => {
		if (!response || typeof response !== "object") {
			failed(file, "The server sent an unexpected answer. Reload the page to see whether the extension was installed.", null);
			return;
		}

		messages.replaceChildren();
		const redirect = typeof response.redirect === "string" ? response.redirect : "";
		const warnings = Array.isArray(response.warnings) ? response.warnings.filter(w => typeof w === "string" && w !== "") : [];

		if (warnings.length === 0 && response.disabled !== true && redirect) {
			window.location.assign(redirect);
			return;
		}

		if (response.disabled === true) {
			const findings = Number(response.findings) || 0;
			line("ext-install-warning", "The update was installed but the extension has been disabled for review"
				+ (findings > 0 ? ` (${findings} ${findings === 1 ? "finding" : "findings"})` : "")
				+ ". Review it before enabling it again.", "status");
		}
		warnings.forEach(w => line("ext-install-warning", w, "status"));

		if (redirect) {
			line("ext-install-continue", `Continuing to ${redirect} …`);
			setTimeout(() => window.location.assign(redirect), WARNING_DELAY);
			return;
		}

		// The server always names where to go; if it ever does not, say what
		// happened and let the drop zone take another file.
		line("ext-install-continue", "The extension was installed. Reload the page to see it.", "status");
		setBusy(false);
	});

	// Keyboard: the drop zone is a role=button; Enter or Space opens the picker.
	drop.addEventListener("keydown", event => {
		if (event.key !== "Enter" && event.key !== " ") return;
		event.preventDefault();
		if (!busy) dropzone.hiddenFileInput?.click();
	});
}

function init() {
	const toggle = document.getElementById("ext-install-toggle");
	const panel  = document.getElementById("ext-install-panel");
	const drop   = panel?.querySelector(".ext-install-drop");

	if (toggle && panel) setupToggle(toggle, panel, drop);
	if (panel && drop) setupDropzone(panel, drop);

	// After a fresh install with nothing to review, the page arrives with
	// ?installed=vendor/name and that card highlighted.
	document.querySelector(".ext-installed")?.scrollIntoView({ block: "center" });
}

if (document.readyState === "loading") {
	document.addEventListener("DOMContentLoaded", init);
} else {
	init();
}
