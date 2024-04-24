//-----------------------------------------------
// Total CMS Event Dispatcher
//-----------------------------------------------
export default class TotalDispatcher {

    constructor(container, options) {
		this.container = container;

        // Define option defaults
        const defaults = {
            delay : 300
        };
        this.options = Object.assign({}, defaults, options);

		// throttle the event dispatching
		this.debounceTimer = {};
    }

	dispatchEvent(event, detail) {
		clearTimeout(this.debounceTimer[event]);
		this.debounceTimer[event] = setTimeout(() => {
			this.container.dispatchEvent(new CustomEvent(event, { detail: detail }));
		}, this.options.delay);
	}
}
