/**
 * Total CMS Job Queue Stats
**/
export default class JobQueueStatsTable {

    constructor(table) {
        this.table = table;
        this.api   = new TotalCMS({
			url: this.table.dataset.api || "",
		});
        this.route = "/jobqueue/stats";

        // Determine which data key this table uses based on its class
        this.dataKey = this.table.classList.contains("jobqueue-by-status") ? "status" : "type";

        const collection = this.table.dataset.collection || "";
        if (collection.length > 0) {
            this.route = `/jobqueue/stats/${collection}`;
        }
        this.start();
    }


    start() {
        const poll = () => {
            this.updateQueueStats();
            const interval = this.pendingCount() === 0 ? 10 : 2;
            this.timer = setTimeout(poll, interval * 1000);
        };
        poll();
    }

    updateQueueStats() {
        this.api.fetchAPI(this.route).then(data => {
            if (!data || !data[this.dataKey]) {
                console.warn("Job queue stats: No data received for", this.dataKey);
                return;
            }
            // Only update fields for this table's data type
            const stats = data[this.dataKey];
            for (const field in stats) {
                this.updateCount(field.toLowerCase().replace(/ /g, '-'), stats[field]);
            }
        }).catch(error => {
            // Silently ignore network errors for background polling
            console.error("Job queue stats fetch failed", error);
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
