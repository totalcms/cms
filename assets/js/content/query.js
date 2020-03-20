//-----------------------------------------------
// Total CMS Query Builder
//-----------------------------------------------
class TotalQuery extends TotalCMS {

    constructor(options) {
        super(...arguments);

        // Define option defaults
        const defaults = {
            collection : "totalcms",
            scope      : "all",
            property   : "cards",
            template   : "",
            display    : "all",
            count      : 10,
            sort       : "none", // none, sort, reverse, shuffle
            sortBy     : "",
            jsql       : {},
        };
        this.options = Object.assign({}, this.options, defaults, options);

        this.displayAll = (this.options.display === "all");
        this.collection = this.options.collection;
        this.property   = this.options.property;
        this.count      = parseInt(this.options.count);
        this.sortBy     = this.options.sortBy.trim().replace(/\s+/g, "").split(",");
        this.scope      = this.options.scope;
        this.sort       = this.options.sort;
        this.baseapi    = "/collections/" + this.collection;
        this.jsql       = this.options.jsql;

        this.queryData = [];

        if (this.scope === "property") {
            this.baseapi = `${this.baseapi}/${this.getObjectId()}`;
        }

        if (this.options.template.length > 0) {
            const template = document.getElementById(this.options.template);
            if (template) this.jsql = JSON.parse(template.innerHTML);
        }
    }

    sortData(data,options={}) {
        const sort = typeof options.sort === "string" ? options.sort : this.sort;
        // No sort
        if (sort === "none") return data;
        // shuffle
        if (sort === "shuffle") return data.shuffle();
        // Sort by if there are any defined
        let sortBy = options.sortBy||this.sortBy;
        if (typeof sortBy === "string") sortBy = [sortBy];
        if (sortBy.length > 0) {
            if (sort === "reverse") {
                return data.sortBy(...sortBy).reverse();
            }
            return data.sortBy(...sortBy);
        }
        // No sort
        return data;
    }

    paginateData(data,page = 1) {
        this.log.debug(`paginateData for page ${page}`);
        if (!this.displayAll) {
            const start = (page-1)*this.count;
            const end = start+this.count;
            this.log.debug(`paginateData start: ${start} end: ${end}`);
            return data.slice(start,end);
        }
        return data;
    }

    // Example date conversion with SearchJS
    // SEARCHJS.matchArray(data,{releaseDate:{from:  moment().subtract(1,"year").toDate() }});
    // SEARCHJS.matchArray(data,{releaseDate:{to:    moment().add(1,"year").toDate() }});
    // SEARCHJS.matchArray(data,{releaseDate:{to:   "moment().add(1,'year')" }});
    // SEARCHJS.matchArray(data,{releaseDate:{from: "2013-08-26T08:00:00+01:00" }});
    dateConvert(string) {
        if (typeof string !== "string") return string;
        if (string === "now") {
            return new Date();
        }
        if (string.match(/^\s*moment\(/)) {
            const moment = Function("\"use strict\";return (" + string + ")")();
            return moment.toDate();
        }
        const date = new Date(string);
        if (date instanceof Date && !isNaN(date)) {
            return date;
        }
        console.warn(`Warning: dateConvert unable to convert to date (${string})`);
        return string;
    }

    dateKeys(object) {
        return Object.keys(object).filter(key => key.match(/date/i));
    }

    enrichData(data) {
        // Sample first object since all objects should be identical
        const keys = this.dateKeys(data[0]);
        // Convert date objects
        data.map(object => {
            keys.map(k => object[k] = this.dateConvert(object[k]));
        });
        return data;
    }

    enrichJSQL() {
        if (this.enrichedJSQL === true) return;
        const keys = this.dateKeys(this.jsql);
        keys.map(key => {
            ["to","from"].map(k => {
                if (this.jsql[key].hasOwnProperty(k)) {
                    this.jsql[key][k] = this.dateConvert(this.jsql[key][k]);
                }
            });
        });
        this.enrichedJSQL = true;
    }

    filterData(data) {
        // Enrich date with Date objects, etc.
        data = this.enrichData(data);
        this.enrichJSQL();
        return SEARCHJS.matchArray(data, this.jsql);
    }

    fetchAllData() {
        return this.fetchCachedAPI(this.baseapi);
    }

    clearStoredData() {
        this.queryData = [];
    }

    fetchQueryData(page=1,options={}) {
        this.log.debug(`fetchQueryData for page ${page}`);
        return new Promise((resolve, reject) => {

            if (this.queryData.length > 0 && Object.keys(options).length === 0) {
                // if there are no options passed, use stored data
                // options can be used to
                resolve(this.paginateData(this.queryData,page));
                return;
            }

            this.fetchAllData().then(data => {
                if (this.scope === "property") {
                    data = data[this.property];
                }
                else if (this.scope !== "all") {
                    data = this.filterData(data);
                }
                data = this.sortData(data,options);

                // If there is no data saved, save it
                // We don't want to override original query data
                // for potentially just one off queries
                if (this.queryData.length === 0) {
                    this.queryData = data;
                }
                resolve(this.paginateData(data,page));

            }).catch(error => {
                this.log.error("Error fetching query data: " + error);
                reject(error);
            });
        });
    }
}