/**
 * TreeView — progressive enhancement for server-rendered tree sidebars.
 *
 * Operates on hierarchical <details>/<ul>/<li> structures (no virtual DOM).
 * Provides:
 *   - Alternating row stripes that respect filter visibility and folder open state
 *   - Filter input that recursively shows/hides items and auto-opens folders
 *     containing matches
 *   - Re-stripe on folder toggle
 *
 * Used by both the Builder admin sidebar and (eventually) the Depot file
 * browser. The Depot browser currently has its own copy of this logic
 * (javascript/depot-browser.js) which can migrate to this class when the
 * markup has been aligned.
 *
 * Expected DOM:
 *   <input type="search" />
 *   <ul class="${treeSelector}">
 *     <li>
 *       <details>
 *         <summary>Folder name</summary>
 *         <ul class="${treeSelector}">...</ul>
 *       </details>
 *     </li>
 *     <li><a>Leaf</a></li>
 *   </ul>
 *
 * Usage:
 *   new TreeView(rootElement, { treeSelector: ".my-tree", filterInput: input });
 */
export default class TreeView {
	/**
	 * @param {Element} container Root element containing the tree(s)
	 * @param {object}  [options]
	 * @param {string}  [options.treeSelector=".tree-view"] CSS class on each <ul>
	 * @param {string[]} [options.searchableExtras=[]] Additional selectors within an <li> whose text content should be matched by the filter
	 * @param {Element|null} [options.filterInput=null] Input element to bind filtering to
	 * @param {boolean} [options.stripes=true] Whether to apply alternating-row stripes
	 */
	constructor(container, options = {}) {
		if (!container) return;

		this.container        = container;
		this.treeSelector     = options.treeSelector || ".tree-view";
		this.searchableExtras = options.searchableExtras || [];
		this.filterInput      = options.filterInput || null;
		this.stripes          = options.stripes !== false;

		if (this.stripes) this.applyStripes();
		this.bindToggles();
		if (this.filterInput) this.bindFilter(this.filterInput);
	}

	/**
	 * Locate the top-level tree <ul>(s) within the container — every
	 * matching element that isn't itself nested inside another tree.
	 * Sidebars with multiple sibling categories (one tree-view per
	 * category) all need handling.
	 * @returns {Element[]}
	 */
	rootTrees() {
		const all = Array.from(this.container.querySelectorAll(this.treeSelector));
		return all.filter((el) => !el.parentElement?.closest(this.treeSelector));
	}

	/**
	 * Apply alternating .stripe classes to visible items across all root
	 * trees, respecting open folders and filter state. Resets on each call.
	 */
	applyStripes() {
		const trees = this.rootTrees();
		if (trees.length === 0) return;

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

				const details = li.querySelector(":scope > details");
				if (details && details.open) {
					const nested = details.querySelector(`:scope > ${this.treeSelector}`);
					if (nested) walk(nested);
				}
			}
		};
		trees.forEach(walk);
	}

	/**
	 * Re-stripe whenever any folder toggles open/closed.
	 */
	bindToggles() {
		this.container.querySelectorAll("details").forEach((details) => {
			details.addEventListener("toggle", () => {
				if (this.stripes) this.applyStripes();
			});
		});
	}

	/**
	 * Wire a search input to filter the tree. Listens to both `input` (typing)
	 * and `search` (native clear button).
	 *
	 * @param {Element} input
	 */
	bindFilter(input) {
		const onFilter = () => this.filter(input.value);
		input.addEventListener("input", onFilter);
		input.addEventListener("search", onFilter);
	}

	/**
	 * Filter the tree by query string. Matches against each leaf's text
	 * content plus any `searchableExtras` selectors. Folders are hidden if
	 * none of their descendants match; matched folders are auto-opened.
	 *
	 * @param {string} rawQuery
	 */
	filter(rawQuery) {
		const trees = this.rootTrees();
		if (trees.length === 0) return;
		const query = (rawQuery || "").toLowerCase().trim();
		const allLi = this.container.querySelectorAll(`${this.treeSelector} li`);

		if (query.length === 0) {
			allLi.forEach((li) => li.classList.remove("filtered-out"));
			if (this.stripes) this.applyStripes();
			return;
		}

		// First pass: match every <li> against its OWN text (the link inside
		// summary, a direct link, or the summary text for non-link folders).
		// Don't match descendant ul text — that's handled by the propagation
		// pass below, which keeps a parent visible when any descendant matches.
		allLi.forEach((li) => {
			const text = this.ownText(li);

			let extras = "";
			for (const sel of this.searchableExtras) {
				const extra = li.querySelector(sel);
				if (extra) extras += " " + (extra.textContent || "");
			}

			const match = (text + extras).toLowerCase().includes(query);
			li.classList.toggle("filtered-out", !match);
		});

		// Second pass: walk depth-first. If a <li> has a visible descendant,
		// keep the <li> visible too (and auto-open its details) so the user
		// can see the matching child in context. Items with no descendants
		// keep whatever match state pass 1 assigned.
		const propagate = (ul) => {
			for (const li of ul.children) {
				if (li.tagName !== "LI") continue;
				const nested = li.querySelector(`:scope > details > ${this.treeSelector}`);
				if (!nested) continue;

				propagate(nested);

				const hasVisibleChild = Array.from(nested.children).some(
					(child) => child.tagName === "LI" && !child.classList.contains("filtered-out")
				);
				if (hasVisibleChild) {
					li.classList.remove("filtered-out");
					li.querySelector(":scope > details")?.setAttribute("open", "");
				}
			}
		};
		trees.forEach(propagate);

		if (this.stripes) this.applyStripes();
	}

	/**
	 * Get the immediate display text for a <li> — the link inside its summary
	 * (page items wrapped in <details>), the direct link (legacy leaf items),
	 * or the summary text itself (template folders without a link).
	 *
	 * @param {Element} li
	 * @returns {string}
	 */
	ownText(li) {
		const summaryLink = li.querySelector(":scope > details > summary > a");
		if (summaryLink) return summaryLink.textContent || "";

		const summary = li.querySelector(":scope > details > summary");
		if (summary) {
			// Strip any inner ul text that may be picked up via textContent —
			// only the summary's own text content matters here.
			const clone = summary.cloneNode(true);
			clone.querySelectorAll("ul").forEach((ul) => ul.remove());
			return clone.textContent || "";
		}

		const directLink = li.querySelector(":scope > a");
		if (directLink) return directLink.textContent || "";

		return li.textContent || "";
	}
}
