//-----------------------------------------------
// Total CMS Dialog
//-----------------------------------------------
export default class Dialog  {

    constructor(dialog, options = {}) {
		if (!this.isDomNode(dialog)) {
			console.warn("Missing dialog element");
			return;
		}
        // Define option defaults
        const defaults = {
			open       : "open",
			close      : "close",
			onOpen     : null,
			onClose    : null,
			openOnLoad : false,
        };
        this.options = Object.assign({}, defaults, options);

		this.dialog = dialog;

		this.openListener();
		this.closeListener();

		if (this.options.openOnLoad) {
			this.open();
		}
    }

    isDomNode(node){
        return node && typeof node === "object" && "nodeType" in node && node.nodeType === 1;
    }

	close(event) {
		if (event) event.preventDefault();
		this.dialog.close();

		if (this.closeListener) {
			this.dialog.removeEventListener('click', this.closeListener);
		}
		if (this.options.onClose && typeof this.options.onClose === "function") {
			this.options.onClose();
		}
	}

	open(event) {
		if (event) event.preventDefault();
		this.dialog.showModal();

		this.closeListener = this.dialog.addEventListener('click', event => {
			const rect = this.dialog.getBoundingClientRect();
			const isInDialog = (rect.top <= event.clientY && event.clientY <= rect.top + rect.height &&
				rect.left <= event.clientX && event.clientX <= rect.left + rect.width);
			if (!isInDialog) {
				this.close();
			}
		});

		if (this.options.onOpen && typeof this.options.onOpen === "function") {
			this.options.onOpen();
		}
	}

	buttonListener(selector, callback) {
		if (typeof callback !== "function") return;

		let buttons = [];
		if (typeof selector === "string") {
			buttons = Array.from(this.dialog.querySelectorAll(selector));
		} else if (Array.isArray(selector)) {
			buttons = selector;
		} else if (this.isDomNode(selector)) {
			buttons.push(selector);
		} else {
			// console.warn("Invalid Listener Option");
			return;
		}
		buttons.forEach(button => button.addEventListener('click', event => callback(event)));
	}

	closeListener() {
		this.buttonListener(this.options.close, event => this.close(event));
	}

	openListener() {
		this.buttonListener(this.options.open, event => this.open(event));
	}
}
