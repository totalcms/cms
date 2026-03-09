import Droplet from './droplet';

//-----------------------------------------------
// Total CMS Array Droplet for Gallery and Depot
//-----------------------------------------------
export default class DropletArray extends Droplet {

    constructor(container, settings) {
        super(container, settings);
		// Disable single file mode
        this.settings.singleMode = false;
    }
}
