//-----------------------------------------------
// Stock Photos panel
//
// Each image and gallery field gets a small button beside its label, and an
// object page's actions menu (the ⋯ popover) gets a Stock Photos item. Both
// open a side panel that searches the provider through this extension's admin
// routes (the API key stays on the server) and hands photos to that field
// through core's photo drop contract:
//
//   drag   dataTransfer "application/x-totalcms-photo" = {"photos": [...]}
//   click  field.insertPhotos({"photos": [...]})
//
// The field does the download (via the same-origin proxy URL in each photo)
// and the upload, with the photo's alt text and credit. A photo can still be
// dragged onto any field, not just the one the panel was opened from.
//-----------------------------------------------

const PHOTO_TYPE = "application/x-totalcms-photo";
const FIELDS     = ".form-field.image-field, .form-field.gallery-field";
const SEARCH_URL = new URL("ext/totalcms/stock-photos/search", document.baseURI);

let panel  = null;
let target = null;  // the field container Add puts photos in
let opener = null;  // what opened the panel, which gets focus back on close
let fields = [];    // the containers listed in the panel's field picker
let state  = { query: "", page: 1, orientation: "", total: 0, shown: 0 };

//-----------------------------------------------
// A button on each field that can take photos
//-----------------------------------------------

function addButtons() {
	document.querySelectorAll(FIELDS).forEach(container => {
		if (container.dataset.stockPhotos || !container.totalfield?.insertPhotos) return;
		container.dataset.stockPhotos = "1";

		const button = el("button", {
			type            : "button",
			class           : "stock-photos-field-button",
			title           : "Search stock photos",
			"aria-label"    : `Search stock photos for ${fieldName(container)}`,
			"aria-expanded" : "false",
			"aria-controls" : "stock-photos-panel",
		});
		button.addEventListener("click", () => openFor(container, button));

		// Inside the label keeps it on the label's line; a button in a label
		// does not trigger the label's own click.
		const label = container.querySelector(":scope > label");
		if (label) label.append(button);
		else container.prepend(button);
	});
	addMenuItem();
}

function fieldsOnPage() {
	return Array.from(document.querySelectorAll(FIELDS)).filter(container => container.totalfield?.insertPhotos);
}

// The object page's ⋯ actions menu. It has no extension hook, so the item is
// added to its list; if the menu ever changes shape, the field buttons remain.
function addMenuItem() {
	const list = document.querySelector("#collection-actions > ul");
	if (!list || list.querySelector(".stock-photos") || fieldsOnPage().length === 0) return;

	const link = el("a", { href: "#", "aria-controls": "stock-photos-panel" }, "Stock Photos");
	link.addEventListener("click", event => {
		event.preventDefault();
		document.getElementById("collection-actions")?.hidePopover?.();
		const first = fieldsOnPage()[0];
		if (first) openFor(first, document.querySelector('[popovertarget="collection-actions"]'), true);
	});

	const item = el("li", { class: "stock-photos" }, link);
	const after = list.querySelector(".relationships");
	if (after) after.after(item);
	else list.prepend(item);
}

function fieldName(container) {
	return container.totalfield?.label || container.totalfield?.property || "this field";
}

// A name for the picker: a field inside a card or deck is shown under its
// parent ("Menu › Photo"), and names that still repeat (one per deck item)
// are numbered.
function pickerNames(containers) {
	const names = containers.map(container => {
		const parent = container.parentElement?.closest(".form-field");
		return parent?.totalfield ? `${fieldName(parent)} › ${fieldName(container)}` : fieldName(container);
	});
	const seen = {};
	return names.map(name => {
		const total = names.filter(other => other === name).length;
		if (total === 1) return name;
		seen[name] = (seen[name] || 0) + 1;
		return `${name} (${seen[name]})`;
	});
}

function fillPicker() {
	const select = panel.querySelector(".stock-photos-target select");
	fields = fieldsOnPage();
	const names = pickerNames(fields);
	select.replaceChildren(...fields.map((container, i) => el("option", { value: String(i) }, names[i])));
	select.value = String(Math.max(0, fields.indexOf(target)));
	select.disabled = fields.length < 2;
}

// Fields can arrive after load (lazy field types, new deck items). Check at
// most once a frame, however many changes a frame brings.
let queued = false;
function queueButtons() {
	if (queued) return;
	queued = true;
	requestAnimationFrame(() => {
		queued = false;
		addButtons();
	});
}

//-----------------------------------------------
// Open and close
//-----------------------------------------------

function openFor(container, button, fromMenu = false) {
	if (!panel) panel = buildPanel();

	// A field's button again closes it.
	if (!panel.hidden && !fromMenu && target === container && opener === button) {
		closePanel();
		return;
	}

	opener?.setAttribute("aria-expanded", "false");
	target = container;
	opener = button;
	if (!fromMenu) button.setAttribute("aria-expanded", "true");
	fillPicker();
	panel.hidden = false;
	panel.querySelector("input[type=search]").focus();
}

function closePanel() {
	if (!panel || panel.hidden) return;
	panel.hidden = true;
	opener?.setAttribute("aria-expanded", "false");
	if (opener?.isConnected) opener.focus();
}

// Escape closes the panel, unless it belongs to an open dialog (the image
// edit dialog) the user is working in.
document.addEventListener("keydown", event => {
	if (event.key !== "Escape" || !panel || panel.hidden) return;
	if (!panel.contains(document.activeElement) && document.querySelector("dialog[open]")) return;
	event.preventDefault();
	closePanel();
});

//-----------------------------------------------
// Panel
//-----------------------------------------------

function buildPanel() {
	const input  = el("input", { type: "search", name: "q", placeholder: "Search photos", "aria-label": "Search photos", required: "" });
	const select = el("select", { name: "orientation", "aria-label": "Orientation" },
		el("option", { value: "" }, "Default"),
		el("option", { value: "all" }, "Any shape"),
		el("option", { value: "horizontal" }, "Landscape"),
		el("option", { value: "vertical" }, "Portrait"),
	);
	const form = el("form", { class: "stock-photos-search", role: "search" }, input, select, el("button", { type: "submit", class: "dash-button small" }, "Search"));

	const node = el("aside", { id: "stock-photos-panel", class: "stock-photos-panel", hidden: "", "aria-label": "Stock photos" },
		el("header", {},
			el("h2", {}, "Stock Photos"),
			el("button", { type: "button", class: "stock-photos-close", "aria-label": "Close" }, "×"),
		),
		form,
		el("label", { class: "stock-photos-target" }, "Add to ", el("select", { name: "field" })),
		el("p", { class: "stock-photos-status", role: "status" }),
		el("ul", { class: "stock-photos-grid" }),
		el("button", { type: "button", class: "stock-photos-more dash-button transparent small full", hidden: "" }, "Load more"),
		el("footer", {},
			el("a", { class: "stock-photos-source", target: "_blank", rel: "noopener" }),
			el("a", { class: "stock-photos-license", target: "_blank", rel: "noopener" }),
		),
	);

	form.addEventListener("submit", event => {
		event.preventDefault();
		state = { query: input.value.trim(), page: 1, orientation: select.value, total: 0, shown: 0 };
		node.querySelector(".stock-photos-grid").replaceChildren();
		search(node);
	});
	node.querySelector(".stock-photos-more").addEventListener("click", () => {
		state.page++;
		search(node);
	});
	node.querySelector(".stock-photos-close").addEventListener("click", closePanel);
	node.querySelector(".stock-photos-target select").addEventListener("change", event => {
		target = fields[Number(event.target.value)] ?? target;
	});

	document.body.append(node);
	return node;
}

async function search(node) {
	if (state.query === "") return;

	const status = node.querySelector(".stock-photos-status");
	const more   = node.querySelector(".stock-photos-more");
	status.textContent = "Searching…";
	more.hidden = true;

	const url = new URL(SEARCH_URL);
	url.searchParams.set("q", state.query);
	url.searchParams.set("page", String(state.page));
	if (state.orientation) url.searchParams.set("orientation", state.orientation);

	let data;
	try {
		const response = await fetch(url, { credentials: "same-origin", headers: { Accept: "application/json" } });
		data = await response.json();
		if (!response.ok) throw new Error(data?.error || `HTTP ${response.status}`);
	} catch (error) {
		status.textContent = `Search failed: ${error.message}`;
		return;
	}

	const source = node.querySelector(".stock-photos-source");
	source.href        = data.provider.url;
	source.textContent = `Images from ${data.provider.label}`;

	// The terms editors agree to by using a photo, one click away.
	const license = node.querySelector(".stock-photos-license");
	license.href        = data.provider.license;
	license.textContent = "License";

	const grid = node.querySelector(".stock-photos-grid");
	data.photos.forEach(photo => grid.append(tile(photo)));

	state.total  = data.total;
	state.shown += data.photos.length;
	status.textContent = state.total === 0 ? "No photos found." : `${state.shown} of ${state.total} photos`;
	more.hidden = state.shown >= state.total || data.photos.length === 0;
}

function tile(photo) {
	const payload = { photos: [{ url: photo.download, filename: photo.filename, alt: photo.alt, credit: photo.credit }] };
	const author  = photo.credit?.author || "";

	const img = el("img", { src: photo.preview || photo.thumb, alt: photo.alt, loading: "lazy", draggable: "true", width: String(photo.width), height: String(photo.height) });
	img.addEventListener("dragstart", event => {
		event.dataTransfer.setData(PHOTO_TYPE, JSON.stringify(payload));
		event.dataTransfer.effectAllowed = "copy";
	});

	const add = el("button", {
		type         : "button",
		class        : "stock-photos-add",
		title        : "Add to the field",
		"aria-label" : author ? `Add photo by ${author}` : "Add photo",
	});
	add.addEventListener("click", () => insert(payload, add));

	return el("li", {},
		img,
		el("div", { class: "stock-photos-meta" },
			el("span", { class: "stock-photos-author", title: author }, author ? `by ${author}` : ""),
			add,
		),
	);
}

async function insert(payload, button) {
	if (!target?.isConnected) {
		// The field went away (a deck item was removed): pick again.
		target = fieldsOnPage()[0] ?? null;
		fillPicker();
		if (!target) return;
	}
	// The icon shows the state: busy, then a check or an error mark.
	button.disabled = true;
	button.dataset.state = "busy";
	try {
		const added = await target.totalfield.insertPhotos(payload);
		button.dataset.state = added > 0 ? "added" : "failed";
	} catch {
		button.dataset.state = "failed";
	} finally {
		setTimeout(() => {
			button.disabled = false;
			delete button.dataset.state;
		}, 1500);
	}
}

//-----------------------------------------------

function el(tag, attrs = {}, ...children) {
	const node = document.createElement(tag);
	Object.entries(attrs).forEach(([key, value]) => node.setAttribute(key, value));
	node.append(...children);
	return node;
}

addButtons();
new MutationObserver(queueButtons).observe(document.body, { childList: true, subtree: true });
