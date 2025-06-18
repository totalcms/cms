<?php

/**
 * HTML Sanitizer Configuration Sample.
 *
 * Copy this file to html-sanitizer.php and customize the settings below
 * to control which HTML tags and attributes are allowed in your content.
 *
 * This configuration provides XSS protection while allowing rich content.
 *
 * IMPORTANT: HTML sanitization is ENABLED BY DEFAULT for security.
 * To disable it globally, set 'htmlpurify' => false in your config.
 * To disable per-field, use ['htmlpurify' => false] in field settings.
 */

return [
	'html_sanitizer' => [
		/**
		 * Allowed HTML tags and attributes for rich content fields.
		 *
		 * Format: 'tag[attr1|attr2],tag2[attr1|attr2]'
		 *
		 * Note: Limited to HTMLPurifier-supported tags. Modern HTML5 elements
		 * like video, audio, picture, figure are not natively supported and
		 * will be stripped out during sanitization.
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
		'allowed_css' =>
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
		'safe_iframe_domains' => [
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
		 * Strict mode allowed tags (for user comments, untrusted content).
		 *
		 * Much more restrictive - only basic formatting allowed
		 */
		'strict_allowed_tags' => 'p,br,strong,b,em,i,a[href|title],ul,ol,li,blockquote,code',

		/**
		 * Formatting options.
		 */
		'auto_paragraph' => false,    // Automatically wrap content in <p> tags
		'remove_empty'   => false,      // Remove empty tags like <p></p>

		/**
		 * Cache path for HTMLPurifier.
		 *
		 * Leave as null to use system temp directory
		 */
		'cache_path' => null,
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
 * IMPORTANT: HTML5 Media Element Limitations
 * ==========================================
 * HTMLPurifier does not natively support HTML5 elements like:
 * - <video>, <audio>, <source>, <track>
 * - <picture>, <figure>, <figcaption>
 * - <article>, <section>, <header>, <footer>
 * - <details>, <summary>, <mark>, <time>
 *
 * These elements will be stripped during sanitization. For media content:
 *
 * 1. Use iframe embeds from trusted sources (YouTube, Vimeo, etc.)
 * 2. Store media files separately and reference them via custom shortcodes
 * 3. Implement a custom media gallery system outside of HTML sanitization
 * 4. Consider disabling sanitization for trusted admin users (not recommended)
 *
 * If you absolutely need HTML5 elements, you would need to:
 * - Extend HTMLPurifier with custom element definitions
 * - Use a different sanitization library
 * - Implement selective sanitization based on user trust levels
 */
