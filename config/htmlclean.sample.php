<?php

declare(strict_types=1);

/**
 * HTML Cleaning Configuration Sample.
 *
 * Copy this file to htmlclean.php and customize the settings below
 * to control which HTML tags and attributes are allowed in your content.
 *
 * This configuration provides XSS protection while allowing rich content.
 *
 * IMPORTANT: HTML sanitization is ENABLED BY DEFAULT for security.
 * To disable it globally, set 'htmlclean' => ['enabled' => false] in your config.
 * To disable per-field, use ['htmlclean' => false] in field settings.
 */

return [
	'htmlclean' => [
		/**
		 * Allowed HTML tags and attributes for rich content fields.
		 *
		 * Format: 'tag[attr1|attr2],tag2[attr1|attr2]'
		 *
		 * Note: Total CMS supports modern HTML5 elements including
		 * video, audio, picture, figure, section, article, and more.
		 * Configure allowed_tags below to control which elements are preserved.
		 *
		 * For media content, consider using iframe embeds from trusted sources
		 * or implement a custom media handling system outside of HTML sanitization.
		 */
		'allowed_tags' =>
			// Text formatting and structure
			'p,br,strong,b,em,i,u,strike,del,small,sub,sup,var,kbd,samp,' .
			'a[href|title|target|rel],span[class|id|style],' .
			'div[class|id|style],' .

			// Lists and headings
			'ul,ol,li,h1,h2,h3,h4,h5,h6,blockquote,cite,q[cite],' .

			// Code and preformatted text
			'code,pre,abbr[title],' .

			// Tables
			'table[class|id],thead,tbody,tfoot,tr[class|id],' .
			'td[colspan|rowspan|class],th[colspan|rowspan|class],caption,' .

			// Images and embeds
			'img[src|alt|title|width|height|class|style],' .
			'iframe[src|width|height|frameborder],' .

			// Horizontal rule
			'hr[class|id]',

		/**
		 * Allowed CSS properties for inline styles.
		 *
		 * Only applies to elements that allow the 'style' attribute above
		 */
		'allowed_css_properties' =>
			// Colors and backgrounds
			'color,background-color,background-image,background-position,' .
			'background-repeat,background-size,background-attachment,' .

			// Typography
			'font-size,font-weight,font-family,font-style,line-height,' .
			'letter-spacing,word-spacing,text-align,text-decoration,' .
			'text-indent,text-transform,text-shadow,' .

			// Box model
			'margin,margin-top,margin-right,margin-bottom,margin-left,' .
			'padding,padding-top,padding-right,padding-bottom,padding-left,' .
			'border,border-top,border-right,border-bottom,border-left,' .
			'border-color,border-style,border-width,border-radius,' .

			// Layout and positioning
			'width,height,max-width,max-height,min-width,min-height,' .
			'display,position,top,right,bottom,left,float,clear,' .
			'opacity,z-index,overflow,overflow-x,overflow-y,' .
			'vertical-align,white-space,' .

			// Flexbox and Grid (basic)
			'flex,flex-direction,flex-wrap,justify-content,align-items,' .
			'grid-template-columns,grid-template-rows,grid-gap',

		/**
		 * Safe iframe domains for embeds.
		 *
		 * Only iframes from these domains will be allowed
		 * Add your trusted embed providers here
		 */
		'allowed_iframe_domains' => [
			// Video platforms
			'www.youtube.com/embed/',
			'www.youtube-nocookie.com/embed/',
			'player.vimeo.com/video/',
			'www.dailymotion.com/embed/',
			'player.twitch.tv/',

			// Code sharing
			'codepen.io/embed/',
			'jsfiddle.net/embedded/',
			'codesandbox.io/embed/',

			// Presentations and documents
			'slides.com/',
			'docs.google.com/',
			'drive.google.com/',
			'www.slideshare.net/',

			// Maps
			'maps.google.com/embed/',
			'www.google.com/maps/embed/',
			'www.openstreetmap.org/export/embed/',

			// Social media
			'www.facebook.com/plugins/',
			'platform.twitter.com/embed/',
			'www.instagram.com/embed/',

			// Add your custom domains here
			// 'your-trusted-domain.com/embed/',
		],

		/**
		 * Enable/disable HTML sanitization globally.
		 */
		'enabled' => true,
	],
];

/**
 * Examples of additional customization:
 *
 * To be more restrictive with CSS:
 * 'allowed_css' => 'color,font-size,font-weight,text-align,margin,padding'
 *
 * To allow specific video platforms only:
 * 'safe_iframe_domains' => ['www.youtube.com/embed/', 'player.vimeo.com/video/']
 *
 * For strict security (comments, untrusted content):
 * 'allowed_tags' => 'p,br,strong,b,em,i,a[href|title]'
 *
 * HTML5 Media Element Support
 * ===========================
 * Total CMS's HTML sanitizer supports modern HTML5 elements including:
 * - <video>, <audio>, <source>, <track>
 * - <picture>, <figure>, <figcaption>
 * - <article>, <section>, <header>, <footer>
 * - <details>, <summary>, <mark>, <time>
 *
 * These elements are preserved during sanitization when included in allowed_tags.
 * For additional security with media content:
 *
 * 1. Use iframe embeds from trusted domains (configured above)
 * 2. Enable file upload validation for media files
 * 3. Consider Content Security Policy headers
 * 4. For untrusted content, use strict mode sanitization
 *
 * Configuration options:
 * - Set 'htmlclean' => ['enabled' => false] to disable globally
 * - Use per-field ['htmlclean' => false] to disable selectively
 * - Customize allowed_tags, allowed_css_properties, and allowed_iframe_domains
 */
