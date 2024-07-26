import Scrollable from './scrollable';

//-----------------------------------------------
// Total CMS Filter List Component
//-----------------------------------------------
export default class FilterList {

    constructor(input, list, options = {}) {
		if (list.filterlist) {
			return list.filterlist;
		}
		this.input = input;

		this.list = list;
		this.list.filterlist = this;
		this.list.classList.add('filter-list');

		this.options = Object.assign({
			scrollable     : true,
			maintainHeight : true,
		}, options);

		if (this.options.scrollable) {
			this.scrollable = new Scrollable(this.list);
		}

		if (this.input) {
			this.initFilter();
		}
    }

	initFilter() {
		if (this.options.maintainHeight) {
			// Set the height of the input to prevent jumping
			this.list.style.height = this.list.offsetHeight + 'px';
		}

		this.input.addEventListener('input', event => {
			const query = this.input.value.toLowerCase();
			Array.from(this.list.children).forEach(item => {
				// .textContent() includes hidden text as well, .innterText() does not
				const text = item.textContent.toLowerCase();
				if (text.includes(query)) {
					item.classList.remove('filtered');
				} else {
					item.classList.add('filtered');
				}
			});
			this.scrollable?.refresh();
		});
	}
}
