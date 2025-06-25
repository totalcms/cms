import lightGallery from 'lightgallery';

// Plugins
import lgThumbnail from 'lightgallery/plugins/thumbnail'
import lgZoom from 'lightgallery/plugins/zoom'
import lgAutoplay from 'lightgallery/plugins/autoplay'
// import lgComment from 'lightgallery/plugins/comment'
import lgFullscreen from 'lightgallery/plugins/fullscreen'
import lgHash from 'lightgallery/plugins/hash'
import lgPager from 'lightgallery/plugins/pager'
// import lgMediumZoom from 'lightgallery/plugins/mediumZoom'
// import lgRelativeCaption from 'lightgallery/plugins/relativeCaption'
// import lgRotate from 'lightgallery/plugins/rotate'
// import lgShare from 'lightgallery/plugins/share'
// import lgVideo from 'lightgallery/plugins/video'
// import lgVimeoThumbnail from 'lightgallery/plugins/vimeoThumbnail'

document.addEventListener("DOMContentLoaded", event => {
	const lgPlugins = {
		thumbnail  : lgThumbnail,
		zoom       : lgZoom,
		autoplay   : lgAutoplay,
		fullscreen : lgFullscreen,
		hash       : lgHash,
		pager      : lgPager,
		// mediumZoom : lgMediumZoom,
		// lgRelativeCaption : lgRelativeCaption,
		// lgComment : lgComment,
		// lgRotate : lgRotate,
		// lgShare : lgShare,
		// lgVideo : lgVideo,
		// lgVimeoThumbnail : lgVimeoThumbnail
	};
	const galleries = Array.from(document.getElementsByClassName('cms-gallery'));
	galleries.forEach(gallery => {
		const settings = gallery.dataset.settings ? JSON.parse(gallery.dataset.settings) : {};
		settings.licenseKey = '52B84B19-E338-4655-A3BF-DBF401D75F02';
		
		// Check if we need to limit visible thumbnails
		const maxVisible = parseInt(gallery.dataset.maxVisible) || 0;
		if (maxVisible > 0) {
			const allItems = gallery.querySelectorAll('a');
			allItems.forEach((item, index) => {
				if (index >= maxVisible) {
					item.style.display = 'none';
				}
			});
			
			// Add a "View All" indicator if there are hidden images
			if (allItems.length > maxVisible) {
				const lastVisible = allItems[maxVisible - 1];
				const viewAllIndicator = document.createElement('div');
				viewAllIndicator.className = 'gallery-view-all';
				
				// Get custom text pattern or use default
				const viewAllText = gallery.dataset.viewAllText || '+{count} more';
				const remainingCount = allItems.length - maxVisible;
				
				// Replace {count} placeholder with actual count
				viewAllIndicator.innerHTML = viewAllText.replace('{count}', remainingCount);
				
				lastVisible.appendChild(viewAllIndicator);
			}
		}
		
		if (settings.plugins) {
			settings.plugins = settings.plugins.map(plugin => lgPlugins[plugin]);
		}
		lightGallery(gallery, settings);
	});
});
