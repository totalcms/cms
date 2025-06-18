/**
 * Total CMS Job Queue Stats
**/
export default class JobQueueStatsTable {

    constructor(table) {
        this.table = table;
        this.api   = new TotalCMS({
			url: this.table.dataset.api,
		});
        this.route = "/jobqueue/stats";

        const collection = this.table.dataset.collection || "";
        if (collection.length > 0) {
            this.route = `/jobqueue/stats/${collection}`;
        }
        this.start();
    }


    start() {
        const poll = () => {
            this.updateQueueStats();
            const interval = this.pendingCount() === 0 ? 30 : 5;
            this.timer = setTimeout(poll, interval * 1000);
        };
        poll();
    }

    updateQueueStats() {
        this.api.fetchAPI(this.route).then(data => {
            for (const type in data) {
                for (const status in data[type]) {
                    this.updateCount(status.toLowerCase(), data[type][status]);
                }
            }
        });
    }

    pendingCount() {
        return parseInt(document.querySelector(".jobqueue-stats .pending td:last-child")?.textContent) || 0;
    }

    updateCount(field, value) {
        const cell = this.table.querySelector(`.${field} td:last-child`);
        if (cell) cell.textContent = value;
    }
}
