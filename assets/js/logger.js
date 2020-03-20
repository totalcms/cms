/* eslint no-console: ["off"] */
/* -----------------------------------------------------------
    Console Logger
/* -----------------------------------------------------------

    // Create new instance
    const log = new Logger();

    // Standard methods
    log.error('error message',var1,var2); // loglevel=0
    log.warn('error message',var1,var2);  // loglevel=1 (default)
    log.info('info message',var1,var2);   // loglevel=2
    log.log('info message',var1,var2);    // loglevel=2
    log.debug('debug message',var1,var2); // loglevel=3
    log.count('debug message',var1,var2); // loglevel=3
    log.table(tabularData); 			  // loglevel=3

    // Grouping Logs (levellevel > 1)
    log.group('Group for my logs');
    log.info('info');
    log.debug('data',data);
    log.groupEnd();

    // Timing functions
    log.timer('How long does it took me to respond...', function(){
        alert('Testing the Logger Timer. Click OK now.');
    });

----------------------------------------------------------- */

/**
 * Fancy Console Logger
 * @class Logger
 */
class Logger {
    /**
     * Creates an instance of Logger.
     * @constructor
     * @memberof Logger
     */
    constructor(options) {
        // Define option defaults
        const defaults = {
            loglevel: 1, // warning
            group: "logger"
        };
        this.options = Object.assign({}, defaults, options);

        // You can easily override the default loglevel two ways. Loglevel 3 used as example
        // 1. Pass loglevel=3 as a url parameter
        // 2. Set window.loglevel=3
        this.loglevel = parseInt(this.getUrlParameter("loglevel")||window.loglevel)||this.options.loglevel;
    }

    // This is a utility method to get a parameter from the url query string
    getUrlParameter(name) {
        name = name.replace(/[[]/, "\\[").replace(/[\]]/, "\\]");
        var regex = new RegExp("[\\?&]" + name + "=([^&#]*)");
        var results = regex.exec(location.search);
        return results === null ? "" : decodeURIComponent(results[1].replace(/\+/g, " "));
    }

    // End a group that was started
    groupEnd() {
        console.groupEnd();
    }

    //---------------------------------------
    // LOGLEVEL 0
    //---------------------------------------
    error() {
        console.error(...arguments);
    }

    //---------------------------------------
    // LOGLEVEL 1
    //---------------------------------------
    warn() {
        if (this.loglevel >= 1) console.warn(...arguments);
    }

    //---------------------------------------
    // LOGLEVEL 2
    //---------------------------------------
    info() {
        if (this.loglevel >= 2) console.info(...arguments);
    }

    log() {
        if (this.loglevel >= 2) console.log(...arguments);
    }

    // Create a new log group. If you pass true to the collapse parameter,
    // the group will be collapsed by default in the console.
    group(group, collapse = false) {
        if (this.loglevel >= 2) {
            if (collapse) {
                console.groupCollapsed(group||this.options.group);
            }
            else {
                console.group(group||this.options.group);
            }
        }
    }

    //---------------------------------------
    // LOGLEVEL 3
    //---------------------------------------
    debug() {
        if (this.loglevel >= 3) console.debug(...arguments);
    }

    // Similar to the debug method but will add a counter for how many times its occurred
    count() {
        if (this.loglevel >= 3) console.count(...arguments);
    }

    // If you pass it tabular data structure or object, the data will be displayed in a table
    table() {
        if (this.loglevel >= 3) console.table(...arguments);
    }

    // The ability to time a funcion that you can pass as a callback
    // If you pass alwaysRun as true, then the callback will always be ran
    // regardless of loglevel
    timer(label, callback, alwaysRun = false) {
        if (this.loglevel >= 3) {
            console.time(label);
            try {
                callback();
            }
            finally {
                console.timeEnd(label);
            }
        }
        else {
            if (alwaysRun === true) callback();
        }
    }

}