//-----------------------------------------------
// Total CMS Details Simple Accordion
//-----------------------------------------------
export default class Details  {

    constructor(details, options = {}) {
		if (details.details) return details.details;

        // Define option defaults
        const defaults = {
			soloMode     : true,
			openFirst    : true,
			scrollOffset : 16
        };
        this.options = Object.assign({}, defaults, options);

		this.details = this.findDetails(details);

		if (this.options.soloMode) {
			this.soloMode();
		}
		if (this.options.openFirst) {
			this.details[0].setAttribute('open', '');
		}
		this.details.details = this;
    }

	isDomNode(node){
        return node && typeof node === "object" && "nodeType" in node && node.nodeType === 1;
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

	ensureVisible(detail) {
		// give the browser a moment to render the 'open' change
		setTimeout(() => {
			const rect   = detail.getBoundingClientRect();
			const offset = this.options.scrollOffset;
			if (rect.top < offset || rect.top > window.innerHeight - offset) {
				detail.scrollIntoView({
					behavior : 'smooth',
					block    : 'start'
				});
			}
		}, 100);
	}

	soloMode() {
		// Close other details when one is opened
		this.details.forEach(detail => {
			detail.addEventListener('toggle', () => {
				if (detail.open) {
					const siblings = Array.from(detail.parentNode.children).filter((d) => d !== detail);
					siblings.forEach((sibling) => sibling.removeAttribute('open'));
					this.ensureVisible(detail);
				}
			});
		});
	}
}

window.Details = Details;