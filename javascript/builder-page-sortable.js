import TotalSortable from './totalform/total-sortable';

/**
 * Drag-drop reordering for the Site Builder pages tree.
 *
 * Attaches a TotalSortable to every `<ul class="tree-view" data-parent="...">`
 * inside the Site Pages section. All sortables share the same group so pages
 * can move freely between top-level and any folder.
 *
 * Expected DOM (rendered by builder.twig):
 *
 *   <details id="category-builder-pages">
 *     <summary>Site Pages</summary>
 *     <ul class="tree-view" data-parent="">                  // root
 *       <li class="builder-page-item" data-page-id="home">...</li>
 *       <li class="builder-page-item has-children" data-page-id="blog">
 *         <details>
 *           <summary><a>Blog</a></summary>
 *           <ul class="tree-view" data-parent="blog">         // child of blog
 *             <li class="builder-page-item" data-page-id="blog-post">...</li>
 *           </ul>
 *         </details>
 *       </li>
 *     </ul>
 *   </details>
 *
 * On drop the new parent comes from the destination ul's `data-parent`, the
 * new sort comes from the li's index in the destination ul, and the page id
 * comes from the dragged li's `data-page-id`.
 */
export default function initBuilderPageSortable(sidebar) {
	if (!sidebar) return;

	const pagesSection = sidebar.querySelector('#category-builder-pages');
	if (!pagesSection) return;

	const lists = Array.from(pagesSection.querySelectorAll('ul.tree-view[data-parent]'));
	if (lists.length === 0) return;

	const reorderUrl = resolveReorderUrl(sidebar);

	wireOrderModeToggle(sidebar, pagesSection);

	// Track active drags so leaf drop zones (empty inner uls) can be styled
	// visible during a sort. Each TotalSortable adds .sorting to its own
	// container; we use `.is-sorting` on the sidebar for cross-list signaling.
	const onAnyStart = () => sidebar.classList.add('is-sorting');
	const onAnyEnd   = () => {
		sidebar.classList.remove('is-sorting');
		clearDropTargets(sidebar);
	};

	// onMove fires continuously during a drag — use it to highlight only the
	// destination ul under the cursor (instead of every leaf's drop zone at
	// once, which was visually noisy).
	const onMove = (event) => {
		clearDropTargets(sidebar);
		if (event.to) {
			event.to.classList.add('drop-target-active');
		}
		return true;
	};

	// Start disabled — drag-drop only works inside order mode. Toggling the
	// mode flips `disabled` on every instance.
	const sortables = lists.map((list) => new TotalSortable(list, {
		group               : 'builder-pages',
		handle              : '.builder-page-item',
		draggable           : '.builder-page-item',
		animation           : 150,
		fallbackOnBody      : true,
		disabled            : true,
		// Tighter thresholds so dragging near a row reorders by default;
		// nesting requires aiming squarely at a folder's children area.
		swapThreshold       : 0.4,
		invertSwap          : false,
		emptyInsertThreshold: 8,
		ghostClass          : 'page-drag-ghost',
		chosenClass         : 'page-drag-chosen',
		dragClass           : 'page-drag-active',
		onStart             : onAnyStart,
		onMove              : onMove,
		onEnd               : (event) => {
			onAnyEnd();
			handleDrop(event, reorderUrl);
		},
	}));

	sidebar._builderPageSortables = sortables;
}

function clearDropTargets(sidebar) {
	sidebar.querySelectorAll('.drop-target-active').forEach((el) => {
		el.classList.remove('drop-target-active');
	});
}

/**
 * Wire the reorder-mode toggle button. In reorder mode, the sidebar gets
 * `.order-mode`, drop zones become permanently visible, and clicks on page
 * links are intercepted so dragging doesn't accidentally navigate away
 * mid-drag.
 */
function wireOrderModeToggle(sidebar, pagesSection) {
	const toggle = sidebar.querySelector('[data-page-order-toggle]');
	if (!toggle) return;

	toggle.addEventListener('click', (event) => {
		// Stop the click from bubbling to <summary> (which would toggle the
		// Site Pages disclosure)
		event.preventDefault();
		event.stopPropagation();

		const enabled = sidebar.classList.toggle('order-mode');
		toggle.setAttribute('aria-pressed', enabled ? 'true' : 'false');

		// Drag-drop is only active in order mode — flip every Sortable
		// instance's `disabled` flag.
		const sortables = sidebar._builderPageSortables || [];
		sortables.forEach((s) => s.option('disabled', !enabled));
	});

	// In capture phase so we run before the link's default navigation
	pagesSection.addEventListener('click', (event) => {
		if (!sidebar.classList.contains('order-mode')) return;
		const link = event.target.closest('li.builder-page-item a');
		if (link) {
			event.preventDefault();
			event.stopPropagation();
		}
	}, true);
}

/**
 * Resolve `<base href>` (set in the admin <head>) plus the relative reorder
 * path. Falls back to the relative URL — the browser resolves it against the
 * current location which is also under /admin/.
 */
function resolveReorderUrl(sidebar) {
	const base = document.querySelector('base[href]');
	if (base && base.href) {
		return new URL('builder/reorder', base.href).toString();
	}
	return 'builder/reorder';
}

/**
 * After a drop, send the FULL Site Pages hierarchy as a tree to the server.
 * The server hands the tree to BuilderOrderService which writes the order
 * file — one small file write, no event cascade, no per-page record touched.
 *
 * Parent/sort are no longer stored on page records, so saving a page form
 * after a reorder cannot undo the move (the form doesn't carry that data).
 * That's why there's no reload-on-exit-order-mode anymore.
 */
async function handleDrop(event, reorderUrl) {
	const item = event.item;
	if (!item.dataset.pageId) return;

	const sidebar = item.closest('.dash-content-sidebar');
	if (!sidebar) return;

	const rootUl = sidebar.querySelector('#category-builder-pages ul.tree-view[data-parent=""]');
	if (!rootUl) return;

	const tree = collectTree(rootUl);

	const formData = new FormData();
	formData.append('tree', JSON.stringify(tree));

	try {
		const res = await fetch(reorderUrl, {
			method: 'POST',
			credentials: 'same-origin',
			body: formData,
		});

		if (!res.ok) {
			const data = await res.json().catch(() => ({}));
			revertDrop(event);
			console.error('Page reorder failed:', data.error || res.statusText);
			alert('Move failed: ' + (data.error || res.statusText));
			return;
		}

		// Keep the folder icon / has-children class in sync without a reload
		updateHasChildren(event.from);
		updateHasChildren(event.to);
	} catch (err) {
		revertDrop(event);
		console.error('Page reorder request failed:', err);
		alert('Move failed: ' + err.message);
	}
}

/**
 * Walk a <ul> of builder-page-item LIs and produce the matching JSON tree:
 *   [{ id, children: [...] }, ...]
 *
 * Every page <li> wraps its inner <ul> inside <details>, so we recurse via
 * `:scope > details > ul.tree-view`. Pages without children still have an
 * empty inner <ul> in the DOM (so SortableJS can drop into them) — those
 * become `children: []` in the tree.
 */
function collectTree(ul) {
	return Array.from(ul.children)
		.filter((el) => el.classList && el.classList.contains('builder-page-item'))
		.map((li) => {
			const childUl = li.querySelector(':scope > details > ul.tree-view');
			return {
				id      : li.dataset.pageId,
				children: childUl ? collectTree(childUl) : [],
			};
		})
		.filter((node) => node.id);
}

/**
 * Revert a drop by moving the item back to its original position.
 */
function revertDrop(event) {
	const { from, item, oldIndex } = event;
	if (oldIndex >= from.children.length) {
		from.appendChild(item);
	} else {
		from.insertBefore(item, from.children[oldIndex]);
	}
}

/**
 * After a drop, refresh the .has-children class on the parent <li> of the
 * given <ul.tree-view>. This keeps the folder icon / chevron in sync without
 * a full page reload.
 */
function updateHasChildren(ul) {
	if (!ul) return;
	const parentLi = ul.closest('li.builder-page-item');
	if (!parentLi) return;

	const peers = Array.from(ul.children).filter((el) => el.classList && el.classList.contains('builder-page-item'));
	parentLi.classList.toggle('has-children', peers.length > 0);
}
