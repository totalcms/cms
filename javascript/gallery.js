import Gallery from './gallery-base';
import DynamicGallery from './gallery-dynamic';

document.addEventListener("DOMContentLoaded", event => {
	// Initialize standard grid-based galleries
	const galleries = Array.from(document.getElementsByClassName('cms-gallery'));
	galleries.forEach(gallery => new Gallery(gallery));

	// Initialize dynamic galleries from template elements
	const dynamicGalleryTemplates = Array.from(document.querySelectorAll('template[data-gallery-id]'));
	dynamicGalleryTemplates.forEach(template => new DynamicGallery(template));
});
