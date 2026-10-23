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
		this.pending = this.pending || {};
		this.pending[event] = detail;
		this.debounceTimer[event] = setTimeout(() => this.fire(event), this.options.delay);
	}

	// Fire every event still waiting out its debounce, now. The form calls
	// this before it validates or saves, so a change made in the last 300ms
	// (a correction typed just before clicking Save) has reached the form's
	// state first. See TotalForm.validate().
	flush() {
		Object.keys(this.pending || {}).forEach(event => {
			clearTimeout(this.debounceTimer[event]);
			this.fire(event);
		});
	}

	fire(event) {
		if (!this.pending || !(event in this.pending)) return;
		const detail = this.pending[event];
		delete this.pending[event];
		delete this.debounceTimer[event];
		this.container.dispatchEvent(new CustomEvent(event, { detail: detail, bubbles: true }));
	}
}
