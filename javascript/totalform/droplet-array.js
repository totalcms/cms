import Droplet from './droplet';

//-----------------------------------------------
// Total CMS Array Droplet for Gallery and Depot
//-----------------------------------------------

// https://sortablejs.github.io/Sortable/

export default class DropletArray extends Droplet {

    constructor(container, options) {
        super(container, options);
		// Disable single file mode
        this.options.singleMode = false;
    }
}
