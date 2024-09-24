//-----------------------------------------------
// Total CMS Pagination Component
//-----------------------------------------------
export default class Pagination {

    constructor(container, options = {}) {
		this.container = container;
		container.pagination = this;

		this.pageKey = container.dataset.pageKey || 'p';

		if (container.classList.contains('full')) {
			this.initFullPagination();
		} else {
			this.initSimplePagination();
		}
	}

	redirectToPage(page)
	{
		const url = new URL(window.location.href);
		url.searchParams.set(this.pageKey, page);
		window.location = url.href;
	}

	initSimplePagination()
	{
		this.pageEdit = this.container.querySelector('.pagination-current');
		if (!this.pageEdit.isContentEditable) return;
		this.pageEdit.addEventListener('keydown', event => this.pageEditKeyPress(event));
	}

	pageEditKeyPress(event)
	{
		if (!/^\d$/.test(event.key)
			&& event.key !== 'Backspace'
			&& event.key !== 'ArrowLeft'
			&& event.key !== 'ArrowRight'
			&& event.key !== 'Enter'
		) {
			event.preventDefault();
		}
		if (event.key === 'Enter') {
			event.preventDefault();
			this.pageEdit.blur();
			this.redirectToPage(this.pageEdit.innerText);
		}
	}

	initFullPagination() {
		const prev  = this.container.querySelector(".pagination-prev");
		const next  = this.container.querySelector(".pagination-next");
		const pages = this.container.querySelector(".pagination-pages");

		if (pages.scrollWidth < pages.clientWidth) {
			// no need for the scroll on hover effect
			return;
		}

		// pages.classList.add("scrollable");
		this.scrollToActivePage(pages);

		prev.addEventListener("pointerover", e => pages.scrollTo({
			top      : 0,
			left     : 0,
			behavior : 'smooth'
		}));
		next.addEventListener("pointerover", e => pages.scrollTo({
			top      : 0,
			left     : pages.scrollWidth,
			behavior : 'smooth'
		}));
	}

	scrollToActivePage(pages)
	{
		const activePage = pages.querySelector('li.active');
		if (activePage) {
			const activePageRect = activePage.getBoundingClientRect();
			const pagesRect = pages.getBoundingClientRect();

			if (activePageRect.left < pagesRect.left || activePageRect.right > pagesRect.right) {
				pages.scrollTo({
					top: 0,
					left: activePage.offsetLeft - pagesRect.width / 2 + activePageRect.width / 2,
					behavior: 'smooth'
				});
			}
		}
	}
}
