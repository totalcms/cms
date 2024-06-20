import Scrollable from './scrollable';

//-----------------------------------------------
// Total CMS Admin List Component
//-----------------------------------------------
export default class AdminList {

    constructor(container, options = {}) {
		if (container.adminlist) {
			return container.adminlist;
		}
		this.container = container;
		this.container.adminlist = this;

		this.content = container.querySelector('.list-content');
		this.filter  = container.querySelector('input[type="search"]');

		this.scrollable = new Scrollable(this.content);

		if (this.filter) {
			this.initFilter();
		}
    }

	initFilter() {
		// Set the height of the container to prevent jumping
		this.content.style.height = this.content.offsetHeight + 'px';

		this.filter.addEventListener('input', event => {
			const query = this.filter.value.toLowerCase();
			Array.from(this.content.children).forEach(item => {
				// .textContent() includes hidden text as well, .innterText() does not
				const text = item.textContent.toLowerCase();
				if (text.includes(query)) {
					item.classList.remove('filtered');
				} else {
					item.classList.add('filtered');
				}
			});
			this.scrollable.refresh();
		});
	}
}
