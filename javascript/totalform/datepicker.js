//-----------------------------------------------
// Total CMS Date Picker Field
//-----------------------------------------------
class DatePicker extends TotalField {

    constructor(container, options) {
        super(container, options);

        // Define option defaults
        const defaults = {
            // decade=4, year=3, month=2, day=1, hour=0
            startView          : 2,
            minView            : 2,
            maxView            : 4,
            // formats: dd, mm, yyyy, hh, ii
            format             : "mm/dd/yyyy",
            startDate          : null,
            endDate            : null,
            initialDate        : null,
            daysOfWeekDisabled : [],
            datesDisabled      : [],
        };
        this.options = Object.assign({}, this.options, defaults, options);

        // convert passed format to moment.js format
        this.momentFormat = this.toMomentFormat(this.options.format);

        // The datepicker works better when we preformat date string to objects
        this.convertDaysOfWeek();
        this.convertDisabledDates();
        this.convertDates();

        this.initDatePicker();
    }

    toMomentFormat(format) {
        format = format||this.options.format;
        // convert the foundation datepicker format to moment.js
        return format.replace(/d/g,"D").replace(/m/g,"M").replace(/y/g,"Y").replace(/h/g,"H").replace(/i/g,"s");
    }

    stringToDate(string) {
        if (typeof string !== "string") {
            return null;
        }
        string = string.trim();
        // Today
        if (string === "today") {
            return new Date();
        }
        // today+1d, today+3m, today+1y
        const matchAdd = /today\+(\d+)(\w+)/;
        if (string.match(matchAdd)) {
            const [match,num,unit] = string.match(matchAdd);
            return moment().add(Number.parseInt(num),unit).toDate();
        }
        // today-1d, today-3m, today-1y
        const matchSubtract = /today-(\d+)(\w+)/;
        if (string.match(matchSubtract)) {
            const [match,num,unit] = string.match(matchSubtract);
            return moment().subtract(Number.parseInt(num),unit).toDate();
        }
        // Process the date string against the format
        const date = moment(string, this.momentFormat);
        return date.isValid() ? date.toDate() : null;
    }

    convertDaysOfWeek() {
        if (typeof this.options.daysOfWeekDisabled === "string") {
            this.options.daysOfWeekDisabled = this.stringToArray(this.options.daysOfWeekDisabled);
        }
    }

    convertDisabledDates() {
        if (typeof this.options.datesDisabled === "string") {
            this.options.datesDisabled = this.stringToArray(this.options.datesDisabled);
        }
        const now = moment();
        this.options.datesDisabled = this.options.datesDisabled.map(date => {
            if (date.match(/^\d+[/.-]\d+$/)) {
                const match = moment(date,"MM/DD");
                if (match.isBefore(now)) {
                    match.add(1,"year");
                }
                return match.toDate();
            }
            return this.stringToDate(date);
        });
    }

    convertDates() {
        this.options.initialDate = this.stringToDate(this.options.initialDate);
        this.options.startDate   = this.stringToDate(this.options.startDate);
        this.options.endDate     = this.stringToDate(this.options.endDate);
    }

    toTimestamp() {
        // use moment to convert to timestamp
        // return moment(this.input.value, this.momentFormat).unix();
        return moment(this.input.value, this.momentFormat).utc().format();
    }

    getValue() {
        return this.toTimestamp();
    }

    setValue(newtime) {
        // if a timestamp, convert to standard format
        if (newtime.match(/^\d+$/)) newtime = moment(newtime*1000).utc().format();
        this.input.dataset.timestamp = newtime;
        this.input.value = moment(newtime).format(this.toMomentFormat());
    }

    schema() {
        return {
            "type":"string",
            "fieldset":"date"
        };
    }

    initDatePicker() {
        // jQuery - sad panda
        $(this.input).fdatepicker({
            initialDate        : this.options.initialDate,
            language           : this.options.locale,
            startView          : this.options.startView,
            minView            : this.options.minView,
            maxView            : this.options.maxView,
            format             : this.options.format,
            startDate          : this.options.startDate,
            endDate            : this.options.endDate,
            leftArrow          : "<?xml version=\"1.0\"?><svg xmlns=\"http://www.w3.org/2000/svg\" viewBox=\"0 0 320 512\"><path d=\"M153.1 247.5l117.8-116c4.7-4.7 12.3-4.7 17 0l7.1 7.1c4.7 4.7 4.7 12.3 0 17L192.7 256l102.2 100.4c4.7 4.7 4.7 12.3 0 17l-7.1 7.1c-4.7 4.7-12.3 4.7-17 0L153 264.5c-4.6-4.7-4.6-12.3.1-17zm-128 17l117.8 116c4.7 4.7 12.3 4.7 17 0l7.1-7.1c4.7-4.7 4.7-12.3 0-17L64.7 256l102.2-100.4c4.7-4.7 4.7-12.3 0-17l-7.1-7.1c-4.7-4.7-12.3-4.7-17 0L25 247.5c-4.6 4.7-4.6 12.3.1 17z\"></path></svg>",
            rightArrow         : "<?xml version=\"1.0\"?><svg xmlns=\"http://www.w3.org/2000/svg\" viewBox=\"0 0 320 512\"><path d=\"M166.9 264.5l-117.8 116c-4.7 4.7-12.3 4.7-17 0l-7.1-7.1c-4.7-4.7-4.7-12.3 0-17L127.3 256 25.1 155.6c-4.7-4.7-4.7-12.3 0-17l7.1-7.1c4.7-4.7 12.3-4.7 17 0l117.8 116c4.6 4.7 4.6 12.3-.1 17zm128-17l-117.8-116c-4.7-4.7-12.3-4.7-17 0l-7.1 7.1c-4.7 4.7-4.7 12.3 0 17L255.3 256 153.1 356.4c-4.7 4.7-4.7 12.3 0 17l7.1 7.1c4.7 4.7 12.3 4.7 17 0l117.8-116c4.6-4.7 4.6-12.3-.1-17z\"></path></svg>",
            closeButton        : false,
            keyboardNavigation : true,
            daysOfWeekDisabled : this.options.daysOfWeekDisabled,
            datesDisabled      : this.options.datesDisabled
        }).on("input change changeDate", (el) => {
            this.input.dataset.timestamp = this.toTimestamp();
        });
    }
}
