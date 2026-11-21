//-----------------------------------------------
// Extensions page: find one extension among many
//-----------------------------------------------
// The page lists every extension as a row. This drives the search box and
// the status filter above the list. Without it nothing is hidden: the toolbar
// is rendered `hidden` and only shown once this script can work it.
//
// What the markup provides:
//   .ext-toolbar[hidden]           holds the two controls below
//   input.ext-search               free text
//   .ext-filter [data-show]        all | enabled | disabled | attention, with aria-pressed
//   .ext-group                     a heading and its rows; hidden when none of them show.
//                                  Its heading's .ext-count says how many rows are left
//   .ext-row[data-status][data-attention][data-search]
//   .ext-no-match[hidden]          with .ext-no-match-term and a .ext-clear button

const REMEMBER = "tcms.extensions.show";
const SHOWS    = ["all", "enabled", "disabled", "attention"];

/** Lowercase, trimmed, accents dropped: "Café" is found by "cafe". */
export function normalize(text) {
	return String(text ?? "").normalize("NFD").replace(/[̀-ͯ]/g, "").toLowerCase().trim();
}

/**
 * Whether an extension passes the search and the status filter. Every word of
 * the search must appear somewhere in the extension's text, in any order.
 *
 * @param {{search: string, status: string, attention: boolean}} extension search is already normalized
 * @param {{query: string, show: string}} filter
 */
export function matches(extension, { query, show }) {
	if (show === "attention" ? !extension.attention : show !== "all" && extension.status !== show) return false;

	return normalize(query).split(/\s+/).filter(Boolean).every(word => extension.search.includes(word));
}

function remembered() {
	try {
		const show = localStorage.getItem(REMEMBER);
		return SHOWS.includes(show) ? show : "all";
	} catch {
		return "all";
	}
}

function remember(show) {
	try {
		localStorage.setItem(REMEMBER, show);
	} catch {
		// Private windows and blocked storage: the filter just is not kept.
	}
}

export function initExtensionsList(root = document) {
	const toolbar = root.querySelector(".ext-toolbar");
	const search  = toolbar?.querySelector(".ext-search");
	if (!toolbar || !search) return;

	const buttons = [...toolbar.querySelectorAll(".ext-filter [data-show]")];
	const groups  = [...root.querySelectorAll(".ext-group")];
	const empty   = root.querySelector(".ext-no-match");
	const rows    = [...root.querySelectorAll(".ext-row")].map(element => ({
		element,
		search    : normalize(element.dataset.search),
		status    : element.dataset.status || "",
		attention : element.dataset.attention === "true",
	}));

	// A filter left on from last time must not hide a just-installed extension,
	// and one that no longer has a button (nothing needs attention now) is dropped.
	let show = root.querySelector(".ext-installed") ? "all" : remembered();
	if (!buttons.some(button => button.dataset.show === show)) show = "all";

	const apply = () => {
		const filter = { query: search.value, show };
		let visible  = 0;

		rows.forEach(row => {
			const keep = matches(row, filter);
			row.element.hidden = !keep;
			if (keep) visible++;
		});
		groups.forEach(group => {
			const total = group.querySelectorAll(".ext-row").length;
			const left  = group.querySelectorAll(".ext-row:not([hidden])").length;
			const count = group.querySelector(".ext-group-heading .ext-count");

			group.hidden = left === 0;
			if (count) count.textContent = left === total ? String(total) : `${left} of ${total}`;
		});
		buttons.forEach(button => {
			button.setAttribute("aria-pressed", button.dataset.show === show ? "true" : "false");
		});

		if (empty) {
			empty.hidden = visible > 0;
			const term = empty.querySelector(".ext-no-match-term");
			if (term) term.textContent = search.value.trim();
		}
	};

	const reset = () => {
		search.value = "";
		show         = "all";
		remember(show);
		apply();
	};

	search.addEventListener("input", apply);
	search.addEventListener("keydown", event => {
		if (event.key !== "Escape" || search.value === "") return;
		event.preventDefault();
		search.value = "";
		apply();
	});
	buttons.forEach(button => button.addEventListener("click", () => {
		show = button.dataset.show;
		remember(show);
		apply();
	}));
	empty?.querySelector(".ext-clear")?.addEventListener("click", () => {
		reset();
		search.focus();
	});

	toolbar.hidden = false;
	apply();
}
