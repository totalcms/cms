//-----------------------------------------------
// S3 Uploads: the s3file and s3depot fields
//
// The file goes from the browser straight to the bucket; Total CMS only ever
// sees a description of it. Three requests per file:
//
//   1. POST ext/totalcms/s3-uploads/presign   → { url, headers, token }
//   2. PUT  url (the bucket)                   the file itself, with progress
//   3. POST ext/totalcms/s3-uploads/commit    → { value }  the stored pointer
//
// The server writes the pointer to the object in step 3, so by the time the
// field shows the file it is already saved.
//
// The field is a "droplet" in TotalForm's sense. On an existing object a
// dropped file uploads at once. On a new object there is no id to upload
// against yet, so the file waits; the form saves the object first and then
// asks every droplet to flush its queue (TotalForm.saveDroplets).
//-----------------------------------------------

const ROUTE = "ext/totalcms/s3-uploads/";

const MAX_SINGLE_PUT = 5368709120; // 5 GiB: the most one PUT can carry

const BLOCKED = "The upload was blocked before it reached the bucket. This is almost always missing CORS rules on the bucket. See the S3 Uploads docs.";

// Admin pages set <base href> to /admin/, so this resolves under the admin.
const endpoint = name => new URL(ROUTE + name, document.baseURI);

function csrfToken() {
	return document.querySelector('meta[name="csrf-token"]')?.getAttribute("content")
		|| document.querySelector('input[name="csrf_token"]')?.value
		|| "";
}

//-----------------------------------------------
// Pure helpers (exported for tests)
//-----------------------------------------------

/**
 * "7 downloads", or "7 downloads · 40 all time" when the property's total says
 * more than the current file's count: the file replaced one that had its own.
 * Empty when the site has counted nothing.
 */
export function downloadsLabel(count, total) {
	const known = Number.isInteger(count);
	const all   = Number.isInteger(total) ? total : 0;
	if (!known && all === 0) return "";

	const current = known ? count : 0;
	const label   = `${current} download${current === 1 ? "" : "s"}`;

	return all > current ? `${label} · ${all} all time` : label;
}

/** "46 MB", "1.5 KB", "999 B": 1024 steps, one decimal unless it is zero. Same as the server. */
export function formatSize(bytes) {
	const units = ["B", "KB", "MB", "GB"];
	let value = Math.max(0, Number(bytes) || 0);
	let unit  = 0;
	while (value >= 1024 && unit < units.length - 1) {
		value /= 1024;
		unit++;
	}
	const text = unit === 0 ? String(Math.floor(value)) : value.toFixed(1).replace(/\.0$/, "");
	return `${text} ${units[unit]}`;
}

function matchesAny(patterns, value) {
	const list = (typeof patterns === "string" ? [patterns] : Array.isArray(patterns) ? patterns : [])
		.filter(pattern => typeof pattern === "string" && pattern !== "");
	if (list.length === 0) return true;

	return list.some(pattern => {
		try {
			return new RegExp(pattern).test(value);
		} catch {
			return false; // a rule that does not compile refuses, as on the server
		}
	});
}

/**
 * The property's rules, checked before anything is sent, for a fast answer.
 * The server checks again. Sizes are in KB; file types and file names are
 * regular expressions where any one may match.
 *
 * @returns {string[]} what is wrong with the file; empty when it is acceptable
 */
export function checkRules(rules, file, count = 0) {
	const { size, type, name } = file;
	const errors = [];
	const number = value => (value === null || value === undefined || value === "" || isNaN(Number(value)) ? null : Number(value));

	if (size > MAX_SINGLE_PUT) {
		return [`${name} is larger than 5 GB, the most a single upload can carry.`];
	}

	const max = number(rules?.size?.max);
	const min = number(rules?.size?.min);
	if (max !== null && size / 1024 > max) {
		errors.push(`${name} is ${formatSize(size)}. The limit for this field is ${formatSize(Math.round(max * 1024))}.`);
	}
	if (min !== null && size / 1024 < min) {
		errors.push(`${name} is ${formatSize(size)}. Files for this field must be at least ${formatSize(Math.round(min * 1024))}.`);
	}
	if (!matchesAny(rules?.filetype, type || "application/octet-stream")) {
		errors.push(`${name} is not an accepted file type for this field.`);
	}
	if (!matchesAny(rules?.filename, name)) {
		errors.push(`${name} is not an accepted file name for this field.`);
	}

	const countMax = number(rules?.count?.max);
	if (countMax !== null && count >= countMax) {
		errors.push(`This field holds at most ${countMax} files.`);
	}

	return errors;
}

/**
 * The length of an audio or video file in seconds, read in the browser.
 * Null for anything else, and null when the browser cannot decode the file
 * or takes too long: a missing duration never holds up an upload.
 */
export function probeDuration(file, timeoutMs = 5000) {
	const kind = /^(audio|video)\//.exec(file?.type || "")?.[1];
	if (!kind || typeof URL.createObjectURL !== "function") return Promise.resolve(null);

	return new Promise(resolve => {
		const media = document.createElement(kind);
		const url   = URL.createObjectURL(file);
		let timer   = null;

		const finish = value => {
			clearTimeout(timer);
			media.removeAttribute("src");
			URL.revokeObjectURL(url);
			resolve(value);
		};

		timer = setTimeout(() => finish(null), timeoutMs);
		media.preload = "metadata";
		media.addEventListener("loadedmetadata", () => finish(Number.isFinite(media.duration) && media.duration > 0 ? media.duration : null));
		media.addEventListener("error", () => finish(null));
		media.src = url;
	});
}

/**
 * PUT the file to the bucket. No cookies and no CSRF header: this request
 * goes to another origin, and the signed URL is its only credential.
 *
 * Rejects with { status } (0 when the browser blocked the request or the
 * network failed) or { aborted: true }.
 *
 * @param {(fraction: number) => void} onProgress 0 to 1
 * @param {{ xhr?: XMLHttpRequest }} handle receives the request, so the caller can abort it
 */
export function putWithProgress(url, file, headers, onProgress, handle = {}) {
	return new Promise((resolve, reject) => {
		const xhr = new XMLHttpRequest();
		handle.xhr = xhr;

		xhr.open("PUT", url);
		xhr.withCredentials = false;
		Object.entries(headers || {}).forEach(([name, value]) => xhr.setRequestHeader(name, value));

		xhr.upload.addEventListener("progress", event => {
			// An empty file has a total of 0: there is no fraction to report.
			if (event.lengthComputable && event.total > 0 && typeof onProgress === "function") onProgress(event.loaded / event.total);
		});
		xhr.addEventListener("load", () => (xhr.status >= 200 && xhr.status < 300 ? resolve() : reject({ status: xhr.status })));
		xhr.addEventListener("error", () => reject({ status: 0 }));
		xhr.addEventListener("abort", () => reject({ aborted: true }));

		xhr.send(file);
	});
}

/** What to tell the editor when the PUT to the bucket fails. */
export function putErrorMessage(failure) {
	if (!failure?.status) return BLOCKED;
	if (failure.status === 403) {
		return "The bucket refused the upload (403). The upload link may have expired, or the key lacks write permission.";
	}
	return `The bucket refused the upload (${failure.status}).`;
}

/** POST JSON to one of this extension's admin routes. Rejects with an Error carrying the server's message. */
async function post(name, body) {
	let response;
	try {
		response = await fetch(endpoint(name), {
			method      : "POST",
			credentials : "same-origin",
			headers     : { "Content-Type": "application/json", Accept: "application/json", "X-CSRF-Token": csrfToken() },
			body        : JSON.stringify(body),
		});
	} catch {
		throw new Error("Could not reach the site. Check your connection and try again.");
	}

	const data = await response.json().catch(() => null);
	if (!response.ok) {
		throw new Error(data?.error || data?.message || `The upload could not be started (${response.status}).`);
	}
	return data || {};
}

/**
 * Upload one file for a property of a saved object.
 *
 * @param {{ collection: string, id: string, property: string }} target
 * Setting `options.handle.cancelled` stops the upload at the next step, and
 * aborts the PUT if it is running. Once the commit has been sent it is too
 * late: the file is on the object.
 *
 * @param {{ onProgress?: (fraction: number) => void, handle?: { cancelled?: boolean, xhr?: XMLHttpRequest } }} options
 * @returns {Promise<{ value: any, item: object }>} the property's new stored value
 */
export async function uploadOne(target, file, options = {}) {
	const stopIfCancelled = () => {
		if (options.handle?.cancelled) throw { aborted: true };
	};

	const duration = await probeDuration(file);
	const mime     = file.type || "application/octet-stream";
	stopIfCancelled();

	const upload = await post("presign", {
		collection : target.collection,
		id         : target.id,
		property   : target.property,
		filename   : file.name,
		size       : file.size,
		mime,
	});

	stopIfCancelled();

	try {
		await putWithProgress(upload.url, file, upload.headers || { "Content-Type": mime }, options.onProgress, options.handle);
	} catch (failure) {
		if (failure?.aborted) throw failure;
		throw new Error(putErrorMessage(failure));
	}
	stopIfCancelled();

	return post("commit", duration === null ? { token: upload.token } : { token: upload.token, duration });
}

//-----------------------------------------------
// The field
//-----------------------------------------------

export function defineS3FileField(TotalField) {
	return class S3FileField extends TotalField {
		constructor(container, settings) {
			super(container, settings);

			this.root     = container.querySelector(".s3-field");
			this.readOnly = this.root?.dataset.uploads === "off";

			this.queued    = null;  // the File waiting to upload, or being uploaded
			this.uploading = false;
			this.baseline  = JSON.stringify(this.getValue()); // the value as last saved
			this.handle    = {};    // holds the running XMLHttpRequest
			this.callbacks = [];    // the form's "queue is empty" listeners

			// What TotalForm drives a deferred upload through.
			this.droplet = {
				isComplete       : () => this.queued === null,
				onQueueComplete  : callback => { if (typeof callback === "function") this.callbacks.push(callback); },
				autoProcessQueue : () => {},  // whether to start is decided per file, from the form's state
				processQueue     : () => this.start(),
				updateUrl        : () => {},  // the upload address is asked for per file
			};

			if (this.root) {
				this.bind();
				this.render();
				this.loadDownloads();
			}
		}

		// TotalForm calls this before flushing; the presign request reads the id fresh.
		updateAPIUrl() {}

		//-------------------------
		// Value: the stored pointer, kept as JSON on the hidden input
		//-------------------------

		getValue() {
			const raw = (this.input.value || "").trim();
			if (raw === "") return null;
			try {
				return JSON.parse(raw);
			} catch {
				return raw; // not ours to repair: hand back what is stored
			}
		}

		setValue(value) {
			this.input.value = value === null || value === undefined || value === "" ? "" : (typeof value === "string" ? value : JSON.stringify(value));
			if (this.root) this.render();
			this.changed();
		}

		clearValue() {
			this.setValue(null);
		}

		/** The stored value when it is a pointer; null for anything else. */
		pointer() {
			const value = this.getValue();
			return value && typeof value === "object" && typeof value.key === "string" && value.key !== "" ? value : null;
		}

		//-------------------------
		// Events
		//-------------------------

		bind() {
			const find = selector => this.root.querySelector(selector);
			this.drop      = find(".s3-drop");
			this.fileInput = find(".s3-input");
			this.card      = find(".s3-card");
			this.progress  = find(".s3-progress");
			this.message   = find(".s3-error");

			const on = (selector, handler) => find(selector)?.addEventListener("click", event => {
				event.preventDefault();
				handler();
			});
			on(".s3-choose", () => this.choose());
			on(".s3-replace", () => this.choose());
			on(".s3-remove", () => this.remove());
			on(".s3-cancel", () => this.cancel());
			on(".s3-retry", () => this.start());

			this.fileInput?.addEventListener("change", event => {
				event.stopPropagation(); // not a change of the field's value
				const file = this.fileInput.files?.[0];
				this.fileInput.value = "";
				if (file) this.selectFile(file);
			});

			// The whole field takes a drop, so Replace needs no second target.
			this.root.addEventListener("dragover", event => {
				if (this.readOnly || !event.dataTransfer?.types?.includes("Files")) return;
				event.preventDefault();
				this.root.classList.add("s3-dragover");
			});
			this.root.addEventListener("dragleave", () => this.root.classList.remove("s3-dragover"));
			this.root.addEventListener("drop", event => {
				this.root.classList.remove("s3-dragover");
				const file = event.dataTransfer?.files?.[0];
				if (this.readOnly || !file) return;
				event.preventDefault();
				this.selectFile(file);
			});
		}

		choose() {
			if (!this.readOnly && !this.uploading) this.fileInput?.click();
		}

		//-------------------------
		// Uploading
		//-------------------------

		selectFile(file) {
			if (this.readOnly || this.uploading) return;

			const errors = checkRules(this.settings.rules || {}, file);
			if (errors.length > 0) {
				this.showError(errors[0]);
				return;
			}

			this.queued = file;
			this.showError("");
			this.render();

			// A saved object uploads at once. So does a file picked while the
			// form is waiting on this field, after an upload that failed.
			if (this.parentIsSaved() || this.callbacks.length > 0) {
				this.start();
				return;
			}
			// A new object: the form saves first, then flushes this queue.
			this.markUnsaved();
		}

		/** Upload the queued file. Resolves when the attempt is over, however it ended. */
		async start() {
			const file = this.queued;
			const id   = this.form?.getId?.();
			if (!file || this.uploading) return;
			if (!id) {
				this.showError("Save the object before uploading.");
				return;
			}

			this.uploading = true;
			this.handle    = {};
			this.showError("");
			this.setProgress(0);
			this.render();

			try {
				const result = await uploadOne(
					{ collection: this.form.collection, id, property: this.property },
					file,
					{ onProgress: fraction => this.setProgress(fraction), handle: this.handle },
				);

				this.uploading = false;
				this.queued    = null;
				this.showError("");
				// Already on disk: show it without marking the field dirty.
				this.setSavedValue(result.value ?? null);
				this.loadDownloads();
			} catch (failure) {
				this.uploading = false;
				if (failure?.aborted) {
					this.queued = null;
				} else {
					// The file stays queued, so Try again needs no second pick.
					this.showError(failure?.message || BLOCKED);
				}
				this.render();
			}

			this.settle();
		}

		saved() {
			super.saved();
			this.baseline = JSON.stringify(this.getValue());
		}

		/**
		 * A save while a file is uploading would send the pointer the field
		 * held before the upload, and could land after the new one is stored.
		 */
		validate() {
			if (this.uploading) {
				this.showError("Wait for the upload to finish, then save.");
				return false;
			}
			return super.validate();
		}

		/** Stop the running upload, or drop the file that is waiting. */
		cancel() {
			if (this.uploading) {
				// start() cleans up when the running step notices. Before the PUT
				// that is the next step; during it, the abort.
				this.handle.cancelled = true;
				this.handle.xhr?.abort();
				return;
			}
			this.queued = null;
			this.showError("");
			this.render();
			this.settle();
		}

		remove() {
			if (this.readOnly) return;
			if (this.queued) {
				this.cancel();
				return;
			}
			// Marks the field unsaved; the next save clears the pointer, and the
			// server then deletes the file from the bucket.
			this.setValue(null);
		}

		/** With nothing left to upload, tell the form, and drop a dirty flag only the queue had raised. */
		settle() {
			if (this.queued !== null) return;

			// Only when the value is what was last saved: a removal or a
			// reorder made here is still an unsaved change.
			if (JSON.stringify(this.getValue()) === this.baseline) {
				this.container.classList.remove("unsaved");
			}
			const callbacks = this.callbacks;
			this.callbacks  = [];
			callbacks.forEach(callback => callback());
		}

		//-------------------------
		// Display
		//-------------------------

		render() {
			const pointer = this.pointer();
			const file    = this.queued;
			const failed  = file !== null && !this.uploading && !this.message.hidden;
			const waiting = file !== null && !this.uploading && !failed;
			const show    = (selector, visible) => {
				const element = this.root.querySelector(selector);
				if (element) element.hidden = !visible;
			};
			const text = (selector, value) => {
				const element = this.root.querySelector(selector);
				if (element) element.textContent = value;
			};

			this.root.dataset.state = this.uploading ? "uploading" : failed ? "failed" : waiting ? "queued" : pointer ? "filled" : "empty";

			this.drop.hidden     = this.readOnly || file !== null || pointer !== null;
			this.card.hidden     = file === null && pointer === null;
			this.progress.hidden = !this.uploading;

			text(".s3-name", file ? file.name : pointer?.name || "");
			text(".s3-size", formatSize(file ? file.size : pointer?.size || 0));
			text(".s3-date", file ? (this.uploading ? "Uploading…" : failed ? "Not uploaded" : "Uploads when you save") : (pointer?.uploadedAt || "").slice(0, 10));
			if (file) text(".s3-downloads", "");

			show(".s3-open", file === null && pointer !== null && Boolean(this.form?.getId?.()));
			show(".s3-replace", file === null && pointer !== null && !this.readOnly);
			show(".s3-remove", !this.uploading && !failed && !this.readOnly);
			show(".s3-cancel", this.uploading || failed);
			show(".s3-retry", failed);

			const open = this.root.querySelector(".s3-open");
			if (open && pointer && this.form) {
				const url = endpoint("open");
				url.search = new URLSearchParams({ collection: this.form.collection || "", id: this.form.getId?.() || "", property: this.property }).toString();
				open.href = url.toString();
			}
		}

		setProgress(fraction) {
			if (this.progress) this.progress.value = Math.round(Math.min(1, Math.max(0, fraction)) * 100);
		}

		showError(message) {
			if (!this.message) return;
			this.message.textContent = message;
			this.message.hidden      = message === "";
		}

		/** How often the stored file was downloaded, beside its size. Decoration: a failure shows nothing. */
		async loadDownloads() {
			const id = this.form?.getId?.();
			if (!this.pointer() || !id || !this.form?.isEditMode?.()) return;

			try {
				const url = endpoint("stats");
				url.search = new URLSearchParams({ collection: this.form.collection || "", id }).toString();
				const response = await fetch(url, { credentials: "same-origin", headers: { Accept: "application/json" } });
				const stats    = response.ok ? await response.json() : null;
				const element  = this.root.querySelector(".s3-downloads");
				if (element) {
					element.textContent = downloadsLabel(stats?.counts?.[this.property], stats?.totals?.[this.property]);
				}
			} catch {
				// no count to show
			}
		}
	};
}

//-----------------------------------------------
// The s3depot field: many files on one property
//
// The value is a list of pointers in display order. Files upload three at a
// time. Each commit answers with the whole list as the server now holds it,
// so files that finish together never overwrite one another.
//-----------------------------------------------

const CONCURRENT = 3;

export function defineS3DepotField(TotalField) {
	return class S3DepotField extends TotalField {
		constructor(container, settings) {
			super(container, settings);

			this.root     = container.querySelector(".s3-field");
			this.readOnly = this.root?.dataset.uploads === "off";

			this.jobs      = [];        // files waiting, uploading or failed: { file, state, handle, element }
			this.removed   = new Set(); // ids taken out here but not saved yet
			this.callbacks = [];        // the form's "queue is empty" listeners
			this.downloads = {};
			this.baseline  = JSON.stringify(this.getValue()); // the value as last saved

			this.droplet = {
				isComplete       : () => this.jobs.length === 0,
				onQueueComplete  : callback => { if (typeof callback === "function") this.callbacks.push(callback); },
				autoProcessQueue : () => {},
				processQueue     : () => this.pump(),
				updateUrl        : () => {},
			};

			if (this.root) {
				this.bind();
				this.render();
				this.loadDownloads();
			}
		}

		updateAPIUrl() {}

		//-------------------------
		// Value: the list of pointers, kept as JSON on the hidden input
		//-------------------------

		getValue() {
			const raw = (this.input.value || "").trim();
			if (raw === "") return [];
			try {
				return JSON.parse(raw);
			} catch {
				return raw;
			}
		}

		setValue(value) {
			const empty = value === null || value === undefined || value === "" || (Array.isArray(value) && value.length === 0);
			this.input.value = empty ? "" : (typeof value === "string" ? value : JSON.stringify(value));
			if (this.root) this.render();
			this.changed();
		}

		clearValue() {
			this.setValue([]);
		}

		/** The stored items that are pointers. */
		pointers() {
			const value = this.getValue();
			return Array.isArray(value) ? value.filter(file => file && typeof file === "object" && typeof file.key === "string" && typeof file.id === "string") : [];
		}

		/** The form saved: what was removed here is now removed on disk. */
		saved() {
			super.saved();
			this.baseline = JSON.stringify(this.getValue());
			this.removed.clear();
		}

		validate() {
			if (this.jobs.some(job => job.state === "uploading")) {
				this.showError("Wait for the uploads to finish, then save.");
				return false;
			}
			return super.validate();
		}

		//-------------------------
		// Events
		//-------------------------

		bind() {
			this.drop      = this.root.querySelector(".s3-drop");
			this.fileInput = this.root.querySelector(".s3-input");
			this.list      = this.root.querySelector(".s3-list");
			this.template  = this.root.querySelector(".s3-item-template");
			this.message   = this.root.querySelector(".s3-error");

			this.root.querySelector(".s3-choose")?.addEventListener("click", event => {
				event.preventDefault();
				if (!this.readOnly) this.fileInput?.click();
			});
			this.fileInput?.addEventListener("change", event => {
				event.stopPropagation();
				const files = [...(this.fileInput.files || [])];
				this.fileInput.value = "";
				this.selectFiles(files);
			});

			this.root.addEventListener("dragover", event => {
				if (this.readOnly) return;
				// A file from the desktop, or one of this field's own cards being moved.
				if (event.dataTransfer?.types?.includes("Files") || this.dragging) {
					event.preventDefault();
					if (!this.dragging) this.root.classList.add("s3-dragover");
				}
			});
			this.root.addEventListener("dragleave", () => this.root.classList.remove("s3-dragover"));
			this.root.addEventListener("drop", event => {
				this.root.classList.remove("s3-dragover");
				if (this.readOnly) return;

				if (this.dragging) {
					event.preventDefault();
					const target = event.target.closest?.("li[data-file-id]");
					this.move(this.dragging, target ? target.dataset.fileId : null);
					this.dragging = null;
					return;
				}

				const files = [...(event.dataTransfer?.files || [])];
				if (files.length > 0) {
					event.preventDefault();
					this.selectFiles(files);
				}
			});
			this.list.addEventListener("dragstart", event => {
				const card = event.target.closest?.("li[data-file-id]");
				this.dragging = !this.readOnly && card?.dataset.fileId ? card.dataset.fileId : null;
			});
			this.list.addEventListener("dragend", () => { this.dragging = null; });
		}

		//-------------------------
		// Uploading
		//-------------------------

		selectFiles(files) {
			if (this.readOnly) return;
			this.showError("");

			const max = Number(this.settings.rules?.count?.max);
			for (const file of files) {
				const count = this.pointers().length + this.jobs.length;
				if (Number.isFinite(max) && max > 0 && count >= max) {
					this.showError(`This field holds at most ${max} files.`);
					break;
				}

				const errors = checkRules({ ...this.settings.rules, count: undefined }, file);
				if (errors.length > 0) {
					this.showError(errors[0]);
					continue;
				}
				this.jobs.push({ file, state: "queued", handle: {} });
			}
			this.render();

			if (this.jobs.length === 0) return;
			if (this.parentIsSaved() || this.callbacks.length > 0) {
				this.pump();
				return;
			}
			// A new object: the form saves first, then flushes this queue.
			this.markUnsaved();
		}

		/** Start queued files until three are uploading. */
		pump() {
			if (!this.form?.getId?.()) return;

			let running = this.jobs.filter(job => job.state === "uploading").length;
			for (const job of this.jobs) {
				if (running >= CONCURRENT) break;
				if (job.state !== "queued") continue;
				running++;
				this.run(job);
			}
			this.settle();
		}

		async run(job) {
			job.state  = "uploading";
			job.handle = {};
			this.render();

			try {
				const result = await uploadOne(
					{ collection: this.form.collection, id: this.form.getId(), property: this.property },
					job.file,
					{ onProgress: fraction => this.setProgress(job, fraction), handle: job.handle },
				);
				this.jobs = this.jobs.filter(other => other !== job);
				this.adopt(Array.isArray(result.value) ? result.value : []);
			} catch (failure) {
				if (failure?.aborted) {
					this.jobs = this.jobs.filter(other => other !== job);
				} else {
					job.state = "failed";
					this.showError(failure?.message || BLOCKED);
				}
				this.render();
			}

			this.pump();
		}

		/**
		 * Take the list as the server now holds it. That list is what is on
		 * disk, so it becomes the saved baseline; what was removed or moved
		 * here and not yet saved is then put back on top of it.
		 */
		adopt(serverList) {
			const local   = this.pointers().map(file => file.id);
			const known   = new Map(serverList.map(file => [file.id, file]));
			const ordered = local.filter(id => known.has(id)).map(id => known.get(id));
			const added   = serverList.filter(file => !local.includes(file.id) && !this.removed.has(file.id));
			const merged  = [...ordered, ...added];

			this.input.value = serverList.length > 0 ? JSON.stringify(serverList) : "";
			TotalField.prototype.saved.call(this); // the baseline moves; pending removals stay pending
			this.baseline = JSON.stringify(this.getValue());
			if (JSON.stringify(merged) === JSON.stringify(serverList)) {
				this.render();
				return;
			}
			this.setValue(merged);
		}

		cancel(job) {
			if (job.state === "uploading") {
				job.handle.cancelled = true;
				job.handle.xhr?.abort(); // run() cleans up when the step notices
				return;
			}
			this.jobs = this.jobs.filter(other => other !== job);
			this.showError("");
			this.render();
			this.settle();
		}

		retry(job) {
			job.state = "queued";
			this.showError("");
			this.render();
			this.pump();
		}

		removeFile(id) {
			if (this.readOnly) return;
			this.removed.add(id);
			this.setValue(this.pointers().filter(file => file.id !== id));
		}

		/** Put file `id` before file `beforeId`, or at the end when `beforeId` is null. */
		move(id, beforeId) {
			if (this.readOnly || id === beforeId) return;

			const files  = this.pointers();
			const moving = files.find(file => file.id === id);
			if (!moving) return;

			const rest  = files.filter(file => file.id !== id);
			const index = beforeId === null ? -1 : rest.findIndex(file => file.id === beforeId);
			if (index === -1) rest.push(moving);
			else rest.splice(index, 0, moving);

			this.setValue(rest);
		}

		/** With nothing left to upload, tell the form, and drop a dirty flag only the queue had raised. */
		settle() {
			if (this.jobs.length > 0) return;

			// Only when the value is what was last saved: a removal or a
			// reorder made here is still an unsaved change.
			if (JSON.stringify(this.getValue()) === this.baseline) {
				this.container.classList.remove("unsaved");
			}
			const callbacks = this.callbacks;
			this.callbacks  = [];
			callbacks.forEach(callback => callback());
		}

		//-------------------------
		// Display
		//-------------------------

		render() {
			const files = this.pointers();
			this.list.replaceChildren(
				...files.map(file => this.fileCard(file)),
				...this.jobs.map(job => this.jobCard(job)),
			);
			this.root.dataset.state = this.jobs.some(job => job.state === "uploading") ? "uploading" : files.length + this.jobs.length > 0 ? "filled" : "empty";
			this.drop.hidden = this.readOnly;
		}

		blankCard() {
			return this.template.content.firstElementChild.cloneNode(true);
		}

		fileCard(file) {
			const card = this.blankCard();
			const set  = (selector, value) => { card.querySelector(selector).textContent = value; };
			const show = (selector, visible) => { const el = card.querySelector(selector); if (el) el.hidden = !visible; };
			const id   = this.form?.getId?.() || "";

			card.dataset.fileId = file.id;
			card.dataset.state  = "filled";
			card.draggable      = !this.readOnly;
			set(".s3-name", file.name || "");
			set(".s3-size", formatSize(file.size || 0));
			set(".s3-date", (file.uploadedAt || "").slice(0, 10));
			const count = this.downloads[`${this.property}/${file.id}`];
			set(".s3-downloads", downloadsLabel(count));

			const open = card.querySelector(".s3-open");
			show(".s3-open", id !== "");
			if (open && id !== "") {
				const url = endpoint("open");
				url.search = new URLSearchParams({ collection: this.form.collection || "", id, property: this.property, file: file.id }).toString();
				open.href = url.toString();
			}

			show(".s3-remove", !this.readOnly);
			card.querySelector(".s3-remove")?.addEventListener("click", event => {
				event.preventDefault();
				this.removeFile(file.id);
			});
			return card;
		}

		jobCard(job) {
			const card = this.blankCard();
			const show = (selector, visible) => { const el = card.querySelector(selector); if (el) el.hidden = !visible; };
			const on   = (selector, handler) => card.querySelector(selector)?.addEventListener("click", event => {
				event.preventDefault();
				handler();
			});

			job.element         = card;
			card.dataset.fileId = "";
			card.dataset.state  = job.state;
			card.draggable      = false;
			card.querySelector(".s3-name").textContent = job.file.name;
			card.querySelector(".s3-size").textContent = formatSize(job.file.size);
			card.querySelector(".s3-date").textContent = { queued: "Uploads when you save", uploading: "Uploading…", failed: "Not uploaded" }[job.state];
			if (job.state === "queued" && (this.parentIsSaved() || this.callbacks.length > 0)) {
				card.querySelector(".s3-date").textContent = "Waiting…";
			}

			show(".s3-progress", job.state === "uploading");
			show(".s3-open", false);
			show(".s3-remove", false);
			show(".s3-cancel", true);
			show(".s3-retry", job.state === "failed");
			on(".s3-cancel", () => this.cancel(job));
			on(".s3-retry", () => this.retry(job));
			return card;
		}

		setProgress(job, fraction) {
			const bar = job.element?.querySelector(".s3-progress");
			if (bar) bar.value = Math.round(Math.min(1, Math.max(0, fraction)) * 100);
		}

		showError(message) {
			if (!this.message) return;
			this.message.textContent = message;
			this.message.hidden      = message === "";
		}

		async loadDownloads() {
			const id = this.form?.getId?.();
			if (this.pointers().length === 0 || !id || !this.form?.isEditMode?.()) return;

			try {
				const url = endpoint("stats");
				url.search = new URLSearchParams({ collection: this.form.collection || "", id }).toString();
				const response = await fetch(url, { credentials: "same-origin", headers: { Accept: "application/json" } });
				this.downloads = (response.ok ? (await response.json())?.counts : null) || {};
				this.render();
			} catch {
				// no counts to show
			}
		}
	};
}

//-----------------------------------------------
// Registration
//
// Core admin scripts load ahead of extension scripts, and modules run in
// document order, so the registry is there by the time this runs.
//-----------------------------------------------

const { TotalField, registerFieldType } = globalThis.TotalCMS ?? {};
if (TotalField && registerFieldType) {
	registerFieldType("s3file", defineS3FileField(TotalField));
	registerFieldType("s3depot", defineS3DepotField(TotalField));
}
