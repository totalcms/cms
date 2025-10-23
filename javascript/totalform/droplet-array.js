import Droplet from './droplet';

//-----------------------------------------------
// Total CMS Array Droplet for Gallery and Depot
//-----------------------------------------------
export default class DropletArray extends Droplet {

    constructor(container, options) {
        super(container, options);
		// Disable single file mode
        this.options.singleMode = false;
    }
}
