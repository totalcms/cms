/**
 * Lightweight column sorting for static admin tables.
 * Attaches click handlers to `th.sortable` elements and sorts tbody rows.
 */
export default class SortableTable {
	constructor(table) {
		this.table = table;
		this.tbody = table.querySelector('tbody');
		this.headers = Array.from(table.querySelectorAll('thead th.sortable'));

		this.headers.forEach((th, index) => {
			th.addEventListener('click', () => this.sort(th, index));
		});
	}

	sort(th, colIndex) {
		const currentDir = th.getAttribute('data-sort-dir');
		const newDir = currentDir === 'asc' ? 'desc' : 'asc';

		// Clear sort state from all headers
		this.headers.forEach(h => h.removeAttribute('data-sort-dir'));
		th.setAttribute('data-sort-dir', newDir);

		const rows = Array.from(this.tbody.querySelectorAll('tr'));
		const isNumeric = th.dataset.sortType === 'number';

		rows.sort((a, b) => {
			const cellA = a.children[colIndex];
			const cellB = b.children[colIndex];
			let valA = cellA?.dataset.sort ?? cellA?.textContent?.trim() ?? '';
			let valB = cellB?.dataset.sort ?? cellB?.textContent?.trim() ?? '';

			if (isNumeric) {
				valA = parseFloat(valA) || 0;
				valB = parseFloat(valB) || 0;
				return newDir === 'asc' ? valA - valB : valB - valA;
			}

			return newDir === 'asc'
				? valA.localeCompare(valB, undefined, { numeric: true })
				: valB.localeCompare(valA, undefined, { numeric: true });
		});

		rows.forEach(row => this.tbody.appendChild(row));
	}
}
