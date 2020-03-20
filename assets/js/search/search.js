
class TotalSearch {

    constructor(node) {

        // Define option defaults
        const defaults = {
            key      : "myLayoutKey",
            wait     : 500,
            maxTop   : 50,
            sticky   : true,
            minChars : 1,
            maxItems : 10,
            modal    : false
        };
        const options = node.dataset.options ? JSON.parse(node.dataset.options) : {};
        this.options  = Object.assign({}, defaults, options);

        this.timeout = null;
        this.wrapper = node;
        this.wait    = this.options.wait;
        this.input   = this.wrapper.querySelector("input");
        this.key     = this.options.key;
        this.maxTop  = this.options.maxTop;
        this.sticky  = this.options.sticky;

        this.layouts = Array.from(document.querySelectorAll(`.totalcms-grid[data-key=${this.key}]`));

        if (this.layouts.length === 0) {
            console.warn("Unable to find any layouts for search.");
        }

        if (this.options.modal) {
            this.node.classList.add("modal");
            this.initAwesomplete();
        }
    }

    listen() {
        this.input.onkeydown = function(e) {
            this.onkeydown = null;
            const placeholder = this.parentNode.querySelector(".placeholder");
            if (placeholder) {
                placeholder.style.opacity = 0;
            }
        };
        // Listen for keystroke events
        this.input.onkeyup = (e) => {
            const enterKey = 13;
            if (e.which == enterKey) {
                this.searchLayouts();
                this.closeSearchModal();
                return;
            }

            clearTimeout(this.timeout);
            this.timeout = setTimeout(() => this.searchLayouts(), this.wait);
        };
        this.wrapper.onclick = (e) => this.toggleSearchFocus(e);
    }

    toggleSearchFocus(e) {
        if (this.wrapper.classList.contains("focus")) {
            if (e.target === this.input) return;
            this.wrapper.classList.remove("focus");
            this.closeSearchModal();
            this.input.blur();
        } else {
            this.openSearchModal();
            this.wrapper.classList.add("focus");
        }
    }

    closeSearchModal() {
        if (!this.options.modal) return;

        window.removeEventListener("scroll", this.boundScrollSearch);
        this.scrollContainer.style.removeProperty("top");
        this.scrollContainer.style.removeProperty("width");
        this.awesomplete.evaluate();
    }

    openSearchModal() {
        if (!this.options.modal) return;
        const viewportOffset   = this.wrapper.getBoundingClientRect();

        this.scrollContainer             = this.input.parentNode;
        this.scrollContainer.style.top   = `${viewportOffset.top}px`;
        this.scrollContainer.style.width = `${this.input.offsetWidth}px`;
        this.windowPosition              = window.scrollY;
        this.ticking                     = false;

        // requestAnimationFrame magic scroll
        this.boundScrollSearch = () => this.onScroll();
        window.addEventListener("scroll", this.boundScrollSearch);
    }

    onScroll() {
        this.scrollChange   = window.scrollY - this.windowPosition;
        this.windowPosition = window.scrollY;
        this.requestTick();
    }

    requestTick() {
        if (this.ticking !== true) {
            window.requestAnimationFrame(() => this.scrollSearch());
            this.ticking = true;
        }
    }

    scrollSearch() {
        const currentTop = parseInt(this.scrollContainer.style.top);
        if (this.sticky && currentTop <= this.maxTop) return;

        // update position
        const newTop = currentTop - this.scrollChange;
        this.scrollContainer.style.top = `${newTop}px`;

        this.ticking = false;
    }

    initAwesomplete() {
        this.awesomplete = new Awesomplete(this.input,{
            filter   : Awesomplete.FILTER_CONTAINS,
            replace  : Awesomplete.REPLACE,
        });
        this.input.addEventListener("awesomplete-select", (event) => {
            this.closeSearchModal();
        });
    }

    searchLayouts(query) {
        if (!query) query = this.input.value;
        this.layouts.forEach(layout => {
            const layoutController = layout.cmslayout;
            // console.log("Searching for "+this.input.value);
            layoutController.search(query);
        });
    }
}

