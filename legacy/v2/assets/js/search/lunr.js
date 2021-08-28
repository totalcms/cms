
class LunrSearch extends TotalCMS {

    constructor(options) {
        super(...arguments);

        // Define option defaults
        const defaults = {
            query: {},
        };
        this.options = Object.assign({}, this.options, defaults, options);

        this.query      = new TotalQuery(this.options.query);
        this.key        = `lunr${this.query.baseapi}`;
        this.collection = this.query.collection || null;
        this.idx        = null;

        if (!this.collection) {
            console.warn("Unable to find collection name inside query for search");
        }
    }

    index() {
        // this.cache is not taken into account. Search index will always be cached.
        if (this.sessionStorage.isSet(this.key) &&
            this.localStorage.isSet(this.key)   &&
            this.localStorage.get(this.expireKey+this.key) > Date.now()) {
                return this.loadCachedIndex();
        }
        this.newIndex();
    }

    loadCachedIndex() {
        this.idx = lunr.Index.load(this.localStorage.get(this.key));
    }

    newIndex() {
        this.query.fetchAllData().then(data => {
            this.idx = lunr(function(){
                // The ID field is the unique key
                this.ref("id");
                // Add all of the fields for the objects
                // using the first record as the sample
                for (const property in data[0]) {
                    // if (property === "id") continue;
                    this.field(property);
                }
                // Add all objects to the index
                data.forEach((object) => this.add(object));
            });

            // Cache response in storage
            this.localStorage.set(`${this.expireKey}/${this.key}`, Date.now()+this.expireStorage);
            this.localStorage.set(this.key, this.idx);
            this.sessionStorage.set(this.key, true);
        });
    }

    search(searchString) {
        if (this.idx === null) return;

        const results = this.idx.search(searchString);
        results.push(...this.searchMore(searchString));

        this.log.debug("Search Results",results);
        return results;
    }

    searchMore(searchString) {
        if (this.idx === null) return;

        // searchString = searchString.match(/\*/) ? searchString : searchString+"*";
        // const results = this.idx.search(searchString.trim());

        const titleCase = function(string) {
            return string.charAt(0).toUpperCase() + string.substr(1).toLowerCase();
        };

        if (searchString.length > 2) {
            // strip last char for better search results
            // yes, this is hacky but it makes things better
            searchString = searchString.substring(0, searchString.length - 1);
        }

        const results = this.idx.query(q => {
            q.term(searchString,               {  boost: 75 });
            q.term(searchString.toLowerCase(), {  boost: 50, wildcard: lunr.Query.wildcard.TRAILING|lunr.Query.wildcard.LEADING });
            q.term(searchString.toUpperCase(), {  boost: 50, wildcard: lunr.Query.wildcard.TRAILING|lunr.Query.wildcard.LEADING });
            q.term(titleCase(searchString),    {  boost: 50, wildcard: lunr.Query.wildcard.TRAILING|lunr.Query.wildcard.LEADING });
            // q.term(searchString, { editDistance: 1, wildcard: lunr.Query.wildcard.TRAILING|lunr.Query.wildcard.LEADING });
        });

        this.log.debug("Search More Results",results);
        return results;
    }
}

