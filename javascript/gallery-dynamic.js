import lightGallery from 'lightgallery';

// Plugins
import lgThumbnail from 'lightgallery/plugins/thumbnail';
import lgZoom from 'lightgallery/plugins/zoom';
import lgAutoplay from 'lightgallery/plugins/autoplay';
import lgFullscreen from 'lightgallery/plugins/fullscreen';
import lgHash from 'lightgallery/plugins/hash';
import lgPager from 'lightgallery/plugins/pager';

/**
 * DynamicGallery class for programmatically-launched galleries.
 * Handles initialization of lightGallery in dynamic mode using template-based data.
 */
class DynamicGallery {
	/**
	 * Plugin mapping for lightGallery
	 * @type {Object<string, any>}
	 */
	static lgPlugins = {
		thumbnail  : lgThumbnail,
		zoom       : lgZoom,
		autoplay   : lgAutoplay,
		fullscreen : lgFullscreen,
		hash       : lgHash,
		pager      : lgPager,
	};

	/**
	 * Initialize a dynamic gallery instance
	 * @param {HTMLTemplateElement} templateElement - The template element containing gallery data
	 */
	constructor(templateElement) {
		this.templateElement = templateElement;
		this.galleryId = templateElement.dataset.galleryId;
		this.dynamicEl = this.parseDynamicElements();
		this.settings = this.parseSettings();
		this.triggers = this.findTriggers();
		this.instance = null;

		this.bindTriggers();
	}

	/**
	 * Parse dynamic elements from template content
	 * @returns {Array<Object>} Array of gallery items
	 */
	parseDynamicElements() {
		try {
			return JSON.parse(this.templateElement.innerHTML);
		} catch (error) {
			console.error('Failed to parse dynamic gallery data:', error);
			return [];
		}
	}

	/**
	 * Parse settings from data attributes
	 * @returns {Object} Gallery settings
	 */
	parseSettings() {
		const settings = this.templateElement.dataset.settings
			? JSON.parse(this.templateElement.dataset.settings)
			: {};

		// Store trigger selector if provided (don't pass to lightGallery)
		this.triggerSelector = settings.trigger || null;
		delete settings.trigger;

		// Add required settings for dynamic mode
		settings.dynamic = true;
		settings.dynamicEl = this.dynamicEl;
		settings.licenseKey = '52B84B19-E338-4655-A3BF-DBF401D75F02';

		// Map plugin names to plugin objects
		if (settings.plugins) {
			settings.plugins = settings.plugins.map(plugin => DynamicGallery.lgPlugins[plugin]);
		}

		return settings;
	}

	/**
	 * Find all trigger elements for this gallery
	 * Combines elements with data-gallery attribute and custom CSS selector
	 * @returns {Array<HTMLElement>} Trigger elements
	 */
	findTriggers() {
		const triggers = [];

		// Find elements with data-gallery attribute
		const dataGalleryTriggers = document.querySelectorAll(`[data-gallery="${this.galleryId}"]`);
		triggers.push(...dataGalleryTriggers);

		// Find elements matching custom CSS selector if provided
		if (this.triggerSelector) {
			try {
				const selectorTriggers = document.querySelectorAll(this.triggerSelector);
				triggers.push(...selectorTriggers);
			} catch (error) {
				console.error(`Invalid trigger selector "${this.triggerSelector}" for gallery "${this.galleryId}":`, error);
			}
		}

		return triggers;
	}

	/**
	 * Bind click handlers to all trigger elements
	 */
	bindTriggers() {
		this.triggers.forEach(trigger => {
			trigger.addEventListener('click', (event) => {
				event.preventDefault();
				this.openGallery(trigger);
			});
		});
	}

	/**
	 * Open the gallery at a specific index
	 * @param {HTMLElement} trigger - The trigger element that was clicked
	 */
	openGallery(trigger) {
		const startIndex = this.getStartIndex(trigger);

		// Initialize gallery if not already done
		if (!this.instance) {
			this.instance = lightGallery(this.templateElement, this.settings);
		}

		// Open at specified index
		this.instance.openGallery(startIndex);
	}

	/**
	 * Get the start index based on trigger data attributes
	 * @param {HTMLElement} trigger - The trigger element
	 * @returns {number} The index to start at (0-based)
	 */
	getStartIndex(trigger) {
		// Check for image name first (most user-friendly)
		const imageName = trigger.dataset.galleryImage;
		if (imageName) {
			const index = this.dynamicEl.findIndex(item => item.name === imageName);
			if (index !== -1) {
				return index;
			}
			console.warn(`Image "${imageName}" not found in gallery "${this.galleryId}". Opening at first image.`);
		}

		// Check for explicit index (1-based for user convenience)
		const galleryIndex = trigger.dataset.galleryIndex;
		if (galleryIndex !== undefined) {
			const index = parseInt(galleryIndex, 10);
			if (index >= 1 && index <= this.dynamicEl.length) {
				return index - 1; // Convert to 0-based for internal use
			}
			console.warn(`Index ${index} out of range for gallery "${this.galleryId}". Opening at first image.`);
		}

		// Default to first image
		return 0;
	}

	/**
	 * Destroy the gallery instance
	 */
	destroy() {
		if (this.instance) {
			this.instance.destroy();
			this.instance = null;
		}
	}

	/**
	 * Update dynamic elements and refresh gallery
	 * @param {Array<Object>} newElements - New gallery items
	 */
	updateElements(newElements) {
		this.dynamicEl = newElements;
		this.settings.dynamicEl = newElements;

		if (this.instance) {
			this.instance.refresh();
		}
	}
}

export default DynamicGallery;
