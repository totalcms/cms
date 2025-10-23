import Sortable from 'sortablejs';

//-----------------------------------------------
// Total CMS Sortable Wrapper
// Wraps SortableJS with Firefox click prevention
//-----------------------------------------------
export default class TotalSortable {

	constructor(container, options = {}) {
		this.container = container;
		this.isDragging = false;
		this.sortable = null;

		options = Object.assign({
			animation     : 150,
			ghostClass    : 'drag-ghost',
			forceFallback : false,
		}, options);

		// Wrap onStart callback
		const originalOnStart = options.onStart || (() => {});
		options.onStart = (event) => {
			this.container.classList.add('sorting');
			this.isDragging = true;
			originalOnStart(event);
		};

		// Wrap onEnd callback
		const originalOnEnd = options.onEnd || (() => {});
		options.onEnd = (event) => {
			originalOnEnd(event);
			this.container.classList.remove('sorting');

			// Firefox fires click after drag, so delay resetting flag
			setTimeout(() => {
				this.isDragging = false;
			}, 50);
		};

		// Create the sortable instance
		this.sortable = Sortable.create(container, options);

		// Prevent clicks during/after drag for Firefox
		this.container.addEventListener('click', (e) => {
			if (this.isDragging) {
				e.stopPropagation();
				e.preventDefault();
			}
		}, true);
	}

	// Proxy common Sortable methods
	destroy() {
		if (this.sortable) {
			this.sortable.destroy();
		}
	}

	// Add any other Sortable methods you need to proxy
	option(name, value) {
		return this.sortable.option(name, value);
	}

	toArray() {
		return this.sortable.toArray();
	}
}
