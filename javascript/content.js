document.addEventListener("DOMContentLoaded", e => {

	const paginationEditables = Array.from(document.getElementsByClassName('pagination-current'));
	const redirectToPage = (page) => {
		const url = new URL(window.location.href);
		url.searchParams.set('p', page);
		window.location = url.href;
	};
	paginationEditables.forEach(editable => {
		if (!editable.isContentEditable) return;
		editable.addEventListener('keydown', event => {
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
				editable.blur();
				redirectToPage(editable.innerText);
			}
		});
	});

});
