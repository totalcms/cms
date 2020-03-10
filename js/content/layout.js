//-----------------------------------------------
// Total CMS Layout Builder
//-----------------------------------------------
class TotalLayout extends TotalCMS {

    constructor(layout) {
        super();

        this.layout      = layout;
        layout.cmslayout = this;
        this.id          = this.layout.id||this.layout.dataset.id;

        this.templateContollers = [];
        this.filters            = [];

        this.registerButtons();
    }

    generateFilters(templates) {
        // Find only filter templates
        const filters = templates.filter(template => "filter" in template.dataset);
        // Loop through and add queries to filters property
        filters.forEach(filter => {
            const jsql  = JSON.parse(filter.innerHTML);
            // A filter is just a query with a filterClass property added to it
            const query = new TotalQuery({jsql:jsql});
            query.filterClass = filter.dataset.filterClass;
            this.filters.push(query);
        });
    }

    applyFilters(data) {
        // Loop through all filters and apply classes
        this.filters.forEach(filter => {
            filter.filterData(data).forEach(object => {
                // add the class to the node
                const node = this.objectNode(object);
                node.classList.add(filter.filterClass);
            });
        });
    }

    populateLayout(options={}) {
        this.templateContollers.forEach(templateContoller => {
            templateContoller.populateTemplate(this.nextPage, options).then(data => {
                this.log.debug("populateLayout Complete");
                // loadmore for non-static controllers
                if (!templateContoller.static) this.loadmore();
                // Apply filters
                this.applyFilters(data);
            });
        });
    }

    shuffleLayout() {
        this.sortLayout("shuffle");
    }

    sortLayout(sort="",sortBy=[]) {
        this.nextPage = 1;
        this.layout.innerHTML = "";
        this.populateLayout({sort:sort,sortBy:sortBy});
    }

    resetLayout() {
        this.nextPage = 1;
        this.layout.innerHTML = "";
        this.populateLayout();
    }

    buildLayout() {
        // Locate the template
        const templatesFor = Array.from(document.querySelectorAll(`template[data-for=${this.id}]`));
        this.log.debug("Layout Templates", templatesFor);

        this.generateFilters(templatesFor);

        this.nextPage = 1;

        const templates = templatesFor.filter(template => !("filter" in template.dataset));
        templates.forEach(template => {
            // Create the template controller and populate
            const templateContoller = this.createTemplateController(template);
            this.templateContollers.push(templateContoller);
        });
        this.populateLayout();
    }

    objectNode(object) {
        return this.layout.querySelector(`[data-id="${object.id}"]`);
    }

    loadmore() {
        const method = this.layout.dataset.loadmore||"none";
        switch (method.trim()) {
            case "infinite":
                this.infiniteLoad();
                break;
            case "infinite-touch-button":
                this.isTouch() ? this.buttonLoad() : this.infiniteLoad();
                break;
            case "button":
                this.buttonLoad();
                break;
            case "paginate":
                this.paginateLoad();
                break;
        }
    }

    buttonLoad() {
        // exit if the button has already been setup
        if (this.buttonTrigger) return;

        this.showButton();
        this.buttonTrigger = this.layout.parentNode.querySelector(".loadmore-button a,.loadmore-button button");
        this.buttonTrigger.addEventListener("click", (event) => {
            this.loadmoreItems();
            event.preventDefault();
        });
    }

    showButton() {
        const buttonWrapper = this.layout.parentNode.querySelector(".loadmore-button");
        if (buttonWrapper) buttonWrapper.classList.add("show");
    }

    deleteButton() {
        const buttonWrapper = this.layout.parentNode.querySelector(".loadmore-button");
        if (buttonWrapper) this.layout.parentNode.removeChild(buttonWrapper);
    }

    paginateLoad() {

    }

    infiniteLoad() {
        if (!this.infiniteTrigger) {
            // Setup new trigger
            const trigger = document.createElement("div");
            trigger.classList.add("scroll-loadmore");
            // Add it below the layout
            this.infiniteTrigger = this.layout.parentNode.insertBefore(trigger, this.layout.nextSibling);
            this.infiniteTrigger.triggered = false;
        }

        // Delete the button since we are going to use infinite
        this.deleteButton();

        // Load the loadmore trigger
        if (this.infiniteTrigger.triggered !== true) {
            this.infiniteTrigger.triggered = true;
            imagesLoaded(this.layout, () => {
                this.infiniteTrigger.triggered = false;
                $(this.infiniteTrigger).onImpression({
                    offset:600,
                    callback:() => this.loadmoreItems()
                });
            });
        }
    }

    loadmoreItems() {
        // don't load more items if there is a search
        if (this.isSearching()) return;

        this.nextPage++;
        this.log.debug(`loadmoreItems: page ${this.nextPage}`);

        this.templateContollers.forEach(templateContoller => {
            templateContoller.populateTemplate(this.nextPage).then(data => {
                // Stop if there is no data
                if (data.length === 0) return;
                // loadmore for non-static controllers
                if (!templateContoller.static) this.loadmore();
                // update disqus comment counts for new posts
                if (typeof DISQUSWIDGETS !== "undefined") {
                    DISQUSWIDGETS.getCount({reset:true});
                }
                // Apply fliters
                this.applyFilters(data);
            });
        });
    }

    createTemplateController(template) {
        let query = {};

        if (template.getAttribute("data-static") === null) {
            if (template.dataset.query) {
                // Get the query for the template
                query = JSON.parse(template.dataset.query);
                // Use the main query if template scope is set to inherit
                if (query.scope === "inherit") query = JSON.parse(this.layout.dataset.query);
            } else {
                if (!this.layout.dataset.query) {
                    console.warn("Layout Template has no query defined");
                    return null;
                }
                query = JSON.parse(this.layout.dataset.query);
            }
            this.log.debug("Layout Query", query);
        }

        return new TotalTemplate({
            query      : query,
            template   : template,
            layout     : this.layout,
        });
    }

    clearSearch() {
        this.isSearching(false);
        this.resetLayout();
    }

    isSearching(enable) {
        const searchClass = "search-results";
        if (enable === true)  this.layout.classList.add(searchClass);
        if (enable === false) this.layout.classList.remove(searchClass);
        return this.layout.classList.contains(searchClass);
    }

    registerButton(buttonClass, callback) {
        const buttons = Array.from(document.getElementsByClassName(buttonClass));
        buttons.forEach(button => {
            // if there is a key defined, make sure that its this key
            if (button.dataset.key && this.layout.dataset.key && button.dataset.key !== this.layout.dataset.key) return;
            button.addEventListener("click", (event) => {
                if (typeof callback === "function") callback(button);
                event.preventDefault();
                return false;
            });
        });
    }

    registerButtons() {
        // <button class="cms-quick-search" data-key="search" data-search="search term"></button>
        this.registerButton("cms-quick-search", button => this.search(button.dataset.search));
        // <button class="cms-reset-search" data-key="search"></button>
        this.registerButton("cms-reset-search", () => this.clearSearch());
        // <button class="cms-shuffle" data-key="search"></button>
        this.registerButton("cms-shuffle", () => this.shuffleLayout());
        // <button class="cms-sort" data-key="search" data-sort="sortProperty" data-sort-by="sortProperty"></button>
        this.registerButton("cms-sort", button => {
            const sort   = button.dataset.sort||"";
            const sortBy = button.dataset.sortby ? button.dataset.sortby.split(",") : [];
            this.sortLayout(sort,sortBy);
        });
    }

    search(query) {
        if (!query) return this.clearSearch();
        this.isSearching(true);
        this.templateContollers.forEach(tc => tc.searchLayout(query));
    }
}


// loadmoreposts = function(){
//     $.debug("Loading More Posts...");
//     var loadcount = posts.length > count ? count : posts.length;

//     for (var i = 0; i < loadcount; i++) {
//         var post = $(Mustache.render(template,posts.shift()));
//         post.imagesLoaded(postImageLoad);
//         processDates($('.post-date',post));
//         processReadTime($('.post-words',post));
//         stack.append( post.hide().fadeIn(600) );
//     }

//     if (posts.length === 0) {
//         // Hide the button if there is no more posts to load
//         button.fadeOut();
//     }
//     else {
//         // Enable infinity scroll again
//         infinityPosts();
//         $(window).trigger('resize');
//     }
//     if (typeof DISQUSWIDGETS !== 'undefined') {
//         // update disqus comment counts for new posts
//         DISQUSWIDGETS.getCount({reset:true});
//     }
// };

// // Load More button if needed
// if (posts.length > 0) {
//     button.click(loadmoreposts);

//     if (button.hasClass('mobile-button') && $.isMobile()) {
//         button.fadeIn();
//     }
//     else {
//         infinityPosts();
//     }
// }
// else {
//     // Hide the button if there is no more posts to load
//     button.fadeOut();
// }