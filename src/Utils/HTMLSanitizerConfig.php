<?php

namespace TotalCMS\Utils;

/**
 * Configuration for HTML sanitization settings.
 * Allows users to customize which HTML tags and attributes are allowed.
 */
final class HTMLSanitizerConfig
{
	/**
	 * Default allowed HTML tags and attributes for rich content.
	 * Limited to HTMLPurifier-supported tags for maximum compatibility.
	 */
	public const DEFAULT_ALLOWED_TAGS =
		'p,br,strong,b,em,i,u,strike,del,a[href|title|target|rel],' .
		'ul,ol,li,h1,h2,h3,h4,h5,h6,blockquote,code,pre,hr,' .
		'div[class|id|style],span[class|id|style],' .
		'img[src|alt|title|width|height|class|style],' .
		'table,thead,tbody,tfoot,tr,td[colspan|rowspan|class],th[colspan|rowspan|class],caption,' .
		'iframe[src|width|height|frameborder],' .
		'abbr[title],cite,q[cite],small,sub,sup,var,kbd,samp';

	/**
	 * Default allowed CSS properties.
	 */
	public const DEFAULT_ALLOWED_CSS =
		'color,background-color,background-image,background-position,background-repeat,background-size,' .
		'font-size,font-weight,font-family,font-style,line-height,letter-spacing,' .
		'text-align,text-decoration,text-indent,text-transform,' .
		'margin,margin-top,margin-right,margin-bottom,margin-left,' .
		'padding,padding-top,padding-right,padding-bottom,padding-left,' .
		'border,border-top,border-right,border-bottom,border-left,' .
		'border-color,border-style,border-width,border-radius,' .
		'width,height,max-width,max-height,min-width,min-height,' .
		'display,position,top,right,bottom,left,float,clear,' .
		'opacity,z-index,overflow,vertical-align';

	/**
	 * Safe iframe domains for embeds.
	 */
	public const DEFAULT_SAFE_IFRAME_DOMAINS = [
		'www.youtube.com/embed/',
		'player.vimeo.com/video/',
		'www.dailymotion.com/embed/',
		'player.twitch.tv/',
		'codepen.io/embed/',
		'jsfiddle.net/embedded/',
		'slides.com/',
		'docs.google.com/',
		'drive.google.com/',
		'maps.google.com/embed/',
		'www.google.com/maps/embed/',
	];

	/**
	 * Strict mode allowed tags (for user comments, etc.).
	 */
	public const STRICT_ALLOWED_TAGS = 'p,br,strong,b,em,i,a[href|title],ul,ol,li,blockquote,code';

	/**
	 * Get HTML sanitization configuration from Total CMS config or use defaults.
	 *
	 * @param array<string,mixed> $config Configuration array
	 *
	 * @return array<string,mixed> HTMLPurifier configuration array
	 */
	public static function getConfig(array $config = []): array
	{
		return [
			'allowed_tags'        => $config['html_sanitizer']['allowed_tags'] ?? self::DEFAULT_ALLOWED_TAGS,
			'allowed_css'         => $config['html_sanitizer']['allowed_css'] ?? self::DEFAULT_ALLOWED_CSS,
			'safe_iframe_domains' => $config['html_sanitizer']['safe_iframe_domains'] ?? self::DEFAULT_SAFE_IFRAME_DOMAINS,
			'auto_paragraph'      => $config['html_sanitizer']['auto_paragraph'] ?? false,
			'remove_empty'        => $config['html_sanitizer']['remove_empty'] ?? false,
			'cache_path'          => $config['html_sanitizer']['cache_path'] ?? sys_get_temp_dir(),
		];
	}

	/**
	 * Get strict mode configuration for high-security contexts.
	 *
	 * @param array<string,mixed> $config Configuration array
	 *
	 * @return array<string,mixed> HTMLPurifier configuration array
	 */
	public static function getStrictConfig(array $config = []): array
	{
		return [
			'allowed_tags'        => $config['html_sanitizer']['strict_allowed_tags'] ?? self::STRICT_ALLOWED_TAGS,
			'allowed_css'         => '',
			'safe_iframe_domains' => [],
			'auto_paragraph'      => true,
			'remove_empty'        => true,
			'cache_path'          => $config['html_sanitizer']['cache_path'] ?? sys_get_temp_dir(),
		];
	}

	/**
	 * Build HTMLPurifier regex for safe iframe domains.
	 *
	 * @param array<string> $domains List of safe iframe domains
	 */
	public static function buildIframeRegex(array $domains): string
	{
		if (empty($domains)) {
			return '';
		}

		$escaped = array_map(fn ($domain) => preg_quote($domain, '%'), $domains);

		return '%^(https?:)?//(' . implode('|', $escaped) . ')%';
	}
}
