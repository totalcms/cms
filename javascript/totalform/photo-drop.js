//-----------------------------------------------
// Photo drop: put a photo from another part of the page (a stock-photo panel,
// a page in another tab) on an image or gallery field.
//
// The contract, public for any photo source:
//
//   dataTransfer type  application/x-totalcms-photo
//   payload            {"photos": [{url, filename, alt, credit, tags, link, focalpoint}, …]}  — always a list
//
// `url` must be fetchable by the admin page: same-origin, or a host that sends
// CORS headers. The field fetches each photo and hands it to its Dropzone as an
// ordinary File, so rules, previews, progress and chunking work as for any
// upload. `alt`, `credit`, `tags`, `link` and `focalpoint` ride with the file
// as its `meta` form field, and the server applies them to the stored image.
//
// Without dragging: field.insertPhotos(payload) does the same.
//-----------------------------------------------

export const PHOTO_TYPE = "application/x-totalcms-photo";

/** Whether a drag carries photos. The data itself is only readable on drop. */
export function hasPhotos(dataTransfer) {
	return Array.from(dataTransfer?.types ?? []).includes(PHOTO_TYPE);
}

/** The photos a drop carries, or [] when it carries none or they don't parse. */
export function readPhotos(dataTransfer) {
	if (!hasPhotos(dataTransfer)) return [];
	try {
		return normalizePhotos(JSON.parse(dataTransfer.getData(PHOTO_TYPE)));
	} catch {
		return [];
	}
}

/** The payload's photos that have a URL; anything else in it is ignored. */
export function normalizePhotos(payload) {
	const photos = Array.isArray(payload?.photos) ? payload.photos : [];
	return photos.filter(photo => typeof photo?.url === "string" && photo.url !== "");
}

/**
 * Fetch each photo and wrap it as a File carrying its meta. A photo that fails
 * to download is skipped and reported, so one bad URL doesn't block the rest.
 */
export async function photosToFiles(photos, fetcher = globalThis.fetch.bind(globalThis)) {
	const files = [];
	for (const photo of photos) {
		try {
			const response = await fetcher(photo.url, { credentials: "same-origin" });
			if (!response.ok) throw new Error(`HTTP ${response.status}`);
			const blob = await response.blob();
			const file = new File([blob], fileName(photo, blob.type), { type: blob.type });
			file.tcmsMeta = photoMeta(photo);
			files.push(file);
		} catch (error) {
			console.error("Photo could not be downloaded", photo.url, error);
		}
	}
	return files;
}

/**
 * The descriptive details that ride with the upload: alt text, credit, tags,
 * link and focal point. The server keeps only the keys an image may have.
 */
export function photoMeta(photo) {
	const meta = {};
	if (typeof photo.alt === "string" && photo.alt.trim() !== "") meta.alt = photo.alt.trim();
	if (typeof photo.link === "string" && photo.link.trim() !== "") meta.link = photo.link.trim();
	if (Array.isArray(photo.tags) && photo.tags.length > 0) meta.tags = photo.tags;
	for (const key of ["credit", "focalpoint"]) {
		if (photo[key] && typeof photo[key] === "object" && !Array.isArray(photo[key])) meta[key] = photo[key];
	}
	return meta;
}

/** The photo's own filename, else one from its URL, with an extension that matches its type. */
export function fileName(photo, mime = "") {
	let name = typeof photo.filename === "string" ? photo.filename.trim() : "";
	if (name === "") {
		try {
			name = decodeURIComponent(new URL(photo.url, "http://x").pathname.split("/").pop() || "");
		} catch {
			name = "";
		}
	}
	name = name.replace(/[^\w.-]+/g, "-").replace(/^-+|-+$/g, "") || "photo";

	const ext = { "image/jpeg": "jpg", "image/png": "png", "image/webp": "webp", "image/gif": "gif", "image/avif": "avif" }[mime];
	return ext && !/\.\w{2,5}$/.test(name) ? `${name}.${ext}` : name;
}

/**
 * Add the photos to a field's droplet. An image field (single mode) takes the
 * first; a gallery takes them all, in order.
 */
export async function insertPhotos(droplet, payload) {
	let photos = normalizePhotos(payload);
	if (droplet.settings.singleMode) photos = photos.slice(0, 1);

	const files = await photosToFiles(photos);
	droplet.addFiles(files);
	return files.length;
}

/**
 * Let a field take photo drops. Listens in the capture phase so Dropzone,
 * which only knows about files, never sees a photo drop.
 */
export function attachPhotoDrop(field) {
	const container = field.container;

	container.addEventListener("dragover", event => {
		if (!hasPhotos(event.dataTransfer)) return;
		event.preventDefault();
		event.dataTransfer.dropEffect = "copy";
	}, true);

	container.addEventListener("drop", event => {
		if (!hasPhotos(event.dataTransfer)) return;
		event.preventDefault();
		event.stopImmediatePropagation();
		field.droplet.event_drop(event);
		field.insertPhotos({ photos: readPhotos(event.dataTransfer) });
	}, true);
}
