
export default class DropletTestSet {

    // const testset = new DropletTestSet({
    //     height:{min:500,max:1000 },
    //     width:{min:500,max:1000},
    //     size:{min:0,max:1000},
    //     count:{max:10},
    //     orientation:'landscape',
    //     orientation:'4:3', // width:height ratio
	// 	   filetype:['image/jpeg'],
	//     filename:['image.jpg'],
    // });

    constructor() {
        // Grabbing these from the global variable is not the best but will work for now
		// TODO : Need to localize these strings
        this.errorStrings = {
            imgLandscape : "imgLandscape error",
            imgPortrait  : "imgPortrait error",
            imgSquare    : "imgSquare error",
            imgRatio     : "imgRatio error",
            imgMaxSize   : "imgMaxSize error",
            imgMinWidth  : "imgMinWidth error",
            imgMaxWidth  : "imgMaxWidth error",
            imgMinHeight : "imgMinHeight error",
            imgMaxHeight : "imgMaxHeight error",
            countMax     : "countMax error",
            filetype     : "filetype error",
			filename     : "filename error",
        };
        this.rules = arguments[0]||{};
        this.pass = true;
        this.errors = [];
    }

    errors() {
        if (this.errors.length === 0) return null;
        return this.errors.join(". ");
    }

    processRules(file, count) {
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
        if (this.rules.count) {
            this.rules.size.maxError = this.errorStrings.countMax;
			this.rules.count.min = 0;
            this.minMax(count ,this.rules.count);
        }
        if (this.rules.orientation) {
            this.orientation(this.rules.orientation,file.width,file.height);
        }
        if (this.rules.filetype) {
            this.patternMatch(this.rules.filetype, file.type);
        }
        if (this.rules.filename) {
            this.patternMatch(this.rules.filename, file.name);
        }
        return this.pass;
    }

	patternMatch(rules, value) {
		for (const rule of rules) {
			const pattern = new RegExp(rule);
			if (!pattern.test(value)) {
				this.errors.push(this.errorStrings.filetype);
				this.pass = false;
			}
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
