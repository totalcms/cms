//-----------------------------------------------
// Total CMS Details Simple Accordion
//-----------------------------------------------
export default class Details  {

    constructor(details, options = {}) {
        // Define option defaults
        const defaults = {
			soloMode  : true,
			openFirst : true,
        };
        this.options = Object.assign({}, defaults, options);

		this.details = this.findDetails(details);

		if (this.options.soloMode) {
			this.soloMode();
		}
		if (this.options.openFirst) {
			this.details[0].setAttribute('open', '');
		}
    }

	findDetails(selector) {
		let details = [];
		if (typeof selector === "string") {
			details = Array.from(document.querySelectorAll(selector));
		} else if (Array.isArray(selector)) {
			details = selector;
		} else if (this.isDomNode(selector)) {
			details.push(selector);
		} else {
			console.warn("Invalid Details");
		}
		return details;
	}

	soloMode() {
		// Close other details when one is opened
		this.details.forEach(detail => {
			detail.addEventListener('toggle', () => {
				if (detail.open) {
					const siblings = Array.from(detail.parentNode.children).filter((d) => d !== detail);
					siblings.forEach((sibling) => sibling.removeAttribute('open'));
				}
			});
		});
	}
}
