//-----------------------------------------------
// Total CMS Scrollable Container
//-----------------------------------------------
export default class Scrollable {

    constructor(container, options = {}) {
		if (container.scrollable) {
			return container.scrollable;
		}
		this.container = container;
		this.container.scrollable = this;

		this.options = Object.assign({
			height   : 600,
			behavior : 'smooth', // auto, smooth, instant
		}, options, this.container.dataset);

		this.maxHeight = this.calculateMaxHeight() || this.options.height;

		this.isScrollable();
    }

	calculateMaxHeight() {
		const style = getComputedStyle(this.container);
		return parseInt(style.getPropertyValue('--scrollable-height'));
	}

	isScrollable() {
		if (this.container.scrollHeight > this.maxHeight) {
			this.container.classList.add('scrollable');
			return true;
		}
		this.container.classList.remove('scrollable');
		return false;
	}

	scrollTo(position) {
		if (!this.isScrollable()) return;
		this.container.scrollTo({
			top      : position,
			behavior : this.options.behavior,
		});
	}

	scrollToTop() {
		this.scrollTo(0);
	}

	scrollToBottom() {
		this.scrollTo(this.container.scrollHeight);
	}
}
