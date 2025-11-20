import lightGallery from 'lightgallery';

// Plugins
import lgThumbnail from 'lightgallery/plugins/thumbnail';
import lgZoom from 'lightgallery/plugins/zoom';
import lgAutoplay from 'lightgallery/plugins/autoplay';
import lgFullscreen from 'lightgallery/plugins/fullscreen';
import lgHash from 'lightgallery/plugins/hash';
import lgPager from 'lightgallery/plugins/pager';

/**
 * Gallery class for standard grid-based galleries.
 * Handles initialization of lightGallery on elements with the cms-gallery class.
 */
class Gallery {
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
	 * Initialize a gallery instance
	 * @param {HTMLElement} element - The gallery container element
	 */
	constructor(element) {
		this.element = element;
		this.dynamicEl = this.parseDynamicElements();
		this.settings = this.parseSettings();
		this.handleMaxVisible();
		this.init();
	}

	/**
	 * Parse dynamic elements from sibling template (for featuredOnly mode)
	 * @returns {Array|null} Array of gallery items or null
	 */
	parseDynamicElements() {
		const template = this.element.nextElementSibling;
		if (template && template.matches('template.cms-gallery-dynamic')) {
			try {
				return JSON.parse(template.innerHTML);
			} catch (error) {
				console.error('Failed to parse dynamic gallery data:', error);
			}
		}
		return null;
	}

	/**
	 * Parse settings from data attributes
	 * @returns {Object} Gallery settings
	 */
	parseSettings() {
		const settings = this.element.dataset.settings
			? JSON.parse(this.element.dataset.settings)
			: {};

		// Add license key and selector
		settings.licenseKey = '52B84B19-E338-4655-A3BF-DBF401D75F02';
		settings.selector = '.cms-gallery-item';

		// Map plugin names to plugin objects
		if (settings.plugins) {
			settings.plugins = settings.plugins.map(plugin => Gallery.lgPlugins[plugin]);
		}

		return settings;
	}

	/**
	 * Handle maxVisible functionality to limit displayed thumbnails
	 */
	handleMaxVisible() {
		const maxVisible = parseInt(this.element.dataset.maxVisible) || 0;

		if (maxVisible > 0) {
			const allFigures = this.element.querySelectorAll('figure');
			allFigures.forEach((figure, index) => {
				if (index >= maxVisible) {
					figure.style.display = 'none';
				}
			});

			// Add "View All" indicator if there are hidden images
			if (allFigures.length > maxVisible) {
				this.addViewAllIndicator(allFigures, maxVisible);
			}
		}
	}

	/**
	 * Add a "View All" indicator to the last visible thumbnail
	 * @param {NodeList} allFigures - All figure elements
	 * @param {number} maxVisible - Number of visible thumbnails
	 */
	addViewAllIndicator(allFigures, maxVisible) {
		const lastVisibleFigure = allFigures[maxVisible - 1];
		const viewAllIndicator = document.createElement('div');
		viewAllIndicator.className = 'gallery-view-all';

		// Get custom text pattern or use default
		const viewAllText = this.element.dataset.viewAllText || '+{count} more';
		const remainingCount = allFigures.length - maxVisible;

		// Replace {count} placeholder with actual count
		viewAllIndicator.innerHTML = viewAllText.replace('{count}', remainingCount);

		lastVisibleFigure.appendChild(viewAllIndicator);
	}

	/**
	 * Initialize the lightGallery instance
	 */
	init() {
		// If we have dynamic elements (featuredOnly mode), use dynamic mode
		if (this.dynamicEl) {
			this.initDynamicMode();
		} else {
			this.instance = lightGallery(this.element, this.settings);
		}
	}

	/**
	 * Initialize in dynamic mode for featuredOnly galleries
	 * Grid shows featured images, lightbox shows all images
	 */
	initDynamicMode() {
		// Configure for dynamic mode
		const dynamicSettings = {
			...this.settings,
			dynamic: true,
			dynamicEl: this.dynamicEl,
		};

		// Remove selector as we're using dynamic mode
		delete dynamicSettings.selector;

		// Initialize lightGallery on the container
		this.instance = lightGallery(this.element, dynamicSettings);

		// Bind click handlers to grid items
		const gridItems = this.element.querySelectorAll('.cms-gallery-item');
		gridItems.forEach(item => {
			item.addEventListener('click', (e) => {
				e.preventDefault();
				const imageName = item.dataset.galleryImage;
				const index = this.dynamicEl.findIndex(el => el.name === imageName);
				this.instance.openGallery(index >= 0 ? index : 0);
			});
		});
	}

	/**
	 * Destroy the gallery instance
	 */
	destroy() {
		if (this.instance) {
			this.instance.destroy();
		}
	}
}

export default Gallery;
