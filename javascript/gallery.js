import lightGallery from 'lightgallery';

// Plugins
import lgThumbnail from 'lightgallery/plugins/thumbnail'
import lgZoom from 'lightgallery/plugins/zoom'
import lgAutoplay from 'lightgallery/plugins/autoplay'
// import lgComment from 'lightgallery/plugins/comment'
import lgFullscreen from 'lightgallery/plugins/fullscreen'
import lgHash from 'lightgallery/plugins/hash'
import lgMediumZoom from 'lightgallery/plugins/mediumZoom'
import lgPager from 'lightgallery/plugins/pager'
import lgRelativeCaption from 'lightgallery/plugins/relativeCaption'
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
		mediumZoom : lgMediumZoom,
		pager      : lgPager,
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
		if (settings.plugins) {
			settings.plugins = settings.plugins.map(plugin => lgPlugins[plugin]);
		}
		lightGallery(gallery, settings);
	});
});
