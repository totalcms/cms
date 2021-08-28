class DropletTestSet {

    // const testset = new DropletTestSet({
    //     height:{min:500,max:1000 },
    //     width:{min:500,max:1000},
    //     size:{min:0,max:1000},
    //     orientation:'landscape',
    //     orientation:'4:3', // width:height ratio
    // });

    constructor() {
        // Grabbing these from the global variable is not the best but will work for now
        this.errorStrings = {
            imgLandscape: window.totalcms.options.localizeStrings.imgLandscape||"imgLandscape error",
            imgPortrait : window.totalcms.options.localizeStrings.imgPortrait||"imgPortrait error",
            imgSquare   : window.totalcms.options.localizeStrings.imgSquare||"imgSquare error",
            imgRatio    : window.totalcms.options.localizeStrings.imgRatio||"imgRatio error",
            imgMaxSize  : window.totalcms.options.localizeStrings.imgMaxSize||"imgMaxSize error",
            imgMinWidth : window.totalcms.options.localizeStrings.imgMinWidth||"imgMinWidth error",
            imgMaxWidth : window.totalcms.options.localizeStrings.imgMaxWidth||"imgMaxWidth error",
            imgMinHeight: window.totalcms.options.localizeStrings.imgMinHeight||"imgMinHeight error",
            imgMaxHeight: window.totalcms.options.localizeStrings.imgMaxHeight||"imgMaxHeight error"
        };
        this.rules = arguments[0]||{};
        this.pass = true;
        this.errors = [];
    }

    errors() {
        if (this.errors.length === 0) return null;
        return this.errors.join(". ");
    }

    processRules(file) {
        if (this.rules.height) {
            this.rules.height.maxError = this.errorStrings.imgMaxHeight;
            this.rules.height.minError = this.errorStrings.imgMinHeight;
            this.minMax(file.height,this.rules.height);
        }
        if (this.rules.width) {
            this.rules.width.maxError = this.errorStrings.imgMaxWidth;
            this.rules.width.minError = this.errorStrings.imgMinWidth;
            this.minMax(file.width,this.rules.width);
        }
        if (this.rules.size) {
            this.rules.size.maxError = this.errorStrings.imgMaxSize;
            this.rules.size.minError = this.errorStrings.imgMaxSize;
            this.minMax(file.size/1024 ,this.rules.size);
        }
        if (this.rules.orientation) {
            this.orientation(this.rules.orientation,file.width,file.height);
        }
        return this.pass;
    }

    minMax(value,rule) {
        if (value > rule.max) {
            this.errors.push(rule.maxError);
            this.pass = false;
        }
        if (value < rule.min) {
            this.errors.push(rule.minError);
            this.pass = false;
        }
        return this.pass;
    }

    orientation(orientation, width, height) {
        if (orientation === "landscape" && width < height) {
            this.errors.push(this.errorStrings.imgLandscape);
            this.pass = false;
        }
        else if (orientation === "portrait" && width > height) {
            this.errors.push(this.errorStrings.imgPortrait);
            this.pass = false;
        }
        else if (orientation === "square" && width !== height) {
            this.errors.push(this.errorStrings.imgSquare);
            this.pass = false;
        }
        else if (orientation.match(/\d+:\d+/)) {
            const fields = orientation.match(/(\d+):(\d+)/);
            const ratioRule = fields[1]/field[2];
            const ratio = width/height;

            if (ratio !== ratioRule) {
                this.errors.push(this.errorStrings.imgRatio);
                this.pass = false;
            }
        }
        return this.pass;
    }
}
