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
			open       : null,
			close      : ".close",
			onOpen     : null,
			onClose    : null,
			openOnLoad : false,
        };

		// One Dialog instance per node, so listeners are never double-bound.
		// But callers legitimately re-construct against a node they share
		// between items (the depot folder dialog, reused for whichever folder
		// is selected) and pass fresh callbacks each time. Returning the cached
		// instance while keeping the FIRST call's options made those callbacks
		// act on a stale item. Adopt the new options; the already-bound
		// listeners read them at call time.
		if (dialog.dialog) {
			dialog.dialog.options = Object.assign({}, defaults, options);

			return dialog.dialog;
		}

        this.options = Object.assign({}, defaults, options);

		this.dialog = dialog;

		this.openListener();
		this.closeListener();

		// Escape closes a native <dialog> through the browser's cancel behavior,
		// bypassing close() — run the same cleanup so onClose still fires and
		// body scrolling is restored.
		this.dialog.addEventListener('cancel', () => {
			this.allowBodyScrolling();
			if (this.options.onClose && typeof this.options.onClose === "function") {
				this.options.onClose();
			}
		});

		if (this.options.openOnLoad) {
			this.open();
		}

		dialog.dialog = this;
    }

    isDomNode(node){
        return node && typeof node === "object" && "nodeType" in node && node.nodeType === 1;
    }

	close() {
		this.allowBodyScrolling();
		this.dialog.close();

		if (this.options.onClose && typeof this.options.onClose === "function") {
			this.options.onClose();
		}
	}

	stopBodyScrolling() {
		// When the modal is shown, we want to remain at the top of the scroll position
		document.body.style.width    = '100%';
		document.body.style.top      = `-${window.scrollY}px`;
		document.body.style.position = 'fixed';
	}

	allowBodyScrolling() {
		// When the modal is hidden, we want to remain at the top of the scroll position
		const scrollY = parseInt(document.body.style.top || 0);
		document.body.style.width = '';
		document.body.style.position = '';
		document.body.style.top = '';
		window.scrollTo(0, scrollY * -1);
	}

	open() {
		this.stopBodyScrolling();
		this.dialog.showModal();

		if (!this.closeClickListener) {
			this.dialog.addEventListener('pointerdown', event => {
				// only checking for clicks on the dialog backgdrop itself
				if (event.target.tagName !== "DIALOG") return;
				const rect = this.dialog.getBoundingClientRect();
				const isInDialog = (rect.top <= event.clientY && event.clientY <= rect.top + rect.height &&
					rect.left <= event.clientX && event.clientX <= rect.left + rect.width);
				if (!isInDialog) {
					this.close();
				}
			});
		}
		this.closeClickListener = true;

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
		buttons.forEach(button => button.addEventListener('click', event => {
			event.preventDefault();
			callback()
		}));
	}

	closeListener() {
		this.buttonListener(this.options.close, () => this.close());
	}

	openListener() {
		this.buttonListener(this.options.open, () => this.open());
	}
}
window.Dialog = Dialog;