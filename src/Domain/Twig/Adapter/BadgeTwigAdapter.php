<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Twig\Adapter;

/**
 * Twig Adapter for the "Built with Total CMS" badges: `cms.badge.pill()`,
 * `.stacked()`, `.text()`, `.profile(handle)`, `.earned(handle)`,
 * `.builtBy(handle)` and `.builtByText(handle)`.
 *
 * Every badge links to totalcms.co, and the images are served from there
 * (`/badges/{style}-{theme}.svg`, `/@{handle}/badge.svg`), so a member's
 * badge stays current — a new count, a new photo — without the site that
 * shows it changing. This writes only the linked markup, the same HTML the
 * badges page on totalcms.co hands out to paste.
 *
 * Themes: `light` (default), `dark`, or `auto`, which follows the visitor's
 * colour scheme through a <picture>. The text badge is inline HTML that
 * inherits the host's text colour; its themes are `color` and `mono`.
 *
 * The profile and earned badges add rel="me" to their link unless
 * `me: false`: the claim that the site and the totalcms.co profile belong to
 * the same person, since those badges go on the designer's own site. The
 * built-by badges credit the designer on a client's site, so they never
 * make that claim.
 */
readonly class BadgeTwigAdapter
{
	public const HOST = 'https://totalcms.co';

	/** The handle rule of the totalcms.co users collection. */
	private const HANDLE = '/^[a-z0-9][a-z0-9\-]{1,28}[a-z0-9]$/';

	/** Fixed sizes of the generic image badges, as served. */
	private const SIZES = ['pill' => [167, 32], 'stacked' => [134, 46]];

	/** Heights of the profile badges; their width follows the member. */
	private const HEIGHTS = ['card' => 52, 'earned' => 32, 'builtby' => 32, 'builtbytext' => 16];

	private const TEXT_STYLE = 'display:inline-flex;align-items:center;gap:5px;font-size:12px;line-height:1;color:inherit;text-decoration:none';

	/**
	 * "Built with Total CMS" on one line.
	 *
	 * @param array<string,mixed> $options theme: light|dark|auto
	 */
	public function pill(array $options = []): string
	{
		return $this->generic('pill', $options);
	}

	/**
	 * "BUILT WITH" over "Total CMS", beside the flame.
	 *
	 * @param array<string,mixed> $options theme: light|dark|auto
	 */
	public function stacked(array $options = []): string
	{
		return $this->generic('stacked', $options);
	}

	/**
	 * "Powered by Total CMS" as inline text in the host's own text colour,
	 * for a footer line.
	 *
	 * @param array<string,mixed> $options theme: color|mono
	 */
	public function text(array $options = []): string
	{
		$mono = ($options['theme'] ?? '') === 'mono';

		return '<a href="' . $this->esc($this->href('text', null)) . '" style="' . self::TEXT_STYLE . '">'
			. $this->flame($mono) . 'Powered by Total CMS</a>';
	}

	/**
	 * The member's profile card: photo, "Total CMS designer" and @handle,
	 * linking to their profile on totalcms.co. An invalid handle gives the
	 * pill, as totalcms.co does for a profile it cannot show.
	 *
	 * @param array<string,mixed> $options theme: light|dark|auto, me: bool (default true)
	 */
	public function profile(string $handle, array $options = []): string
	{
		return $this->member('card', $handle, $options);
	}

	/**
	 * "Total CMS designer" with the member's approved sites and extensions,
	 * linking to their profile on totalcms.co. The count is drawn by
	 * totalcms.co, so it stays current.
	 *
	 * @param array<string,mixed> $options theme: light|dark|auto, me: bool (default true)
	 */
	public function earned(string $handle, array $options = []): string
	{
		return $this->member('earned', $handle, $options);
	}

	/**
	 * "Built by {studio}" in a pill beside the flame, for a client's site.
	 * totalcms.co draws the studio, the member's company else their name, so
	 * it stays current; `byName: true` credits the person even when they have
	 * a company. Never rel="me": the site is the client's, not the designer's.
	 *
	 * @param array<string,mixed> $options theme: light|dark|auto, byName: bool
	 */
	public function builtBy(string $handle, array $options = []): string
	{
		return $this->builtByBadge('builtby', $handle, $options);
	}

	/**
	 * The same credit as builtBy() without the pill: the flame and
	 * "Built by {studio}" in ink on a transparent image, 16px high, to sit in
	 * a footer line. `light` is dark ink for a light footer, `dark` light ink,
	 * and `mono` draws the words and flame in `color` (3 or 6 hex digits, #
	 * optional; the server's default ink when it is missing or invalid).
	 *
	 * @param array<string,mixed> $options theme: light|dark|auto|mono, color: hex, byName: bool
	 */
	public function builtByText(string $handle, array $options = []): string
	{
		return $this->builtByBadge('builtbytext', $handle, $options);
	}

	/** @param array<string,mixed> $options */
	private function generic(string $style, array $options): string
	{
		[$width, $height] = self::SIZES[$style];
		$src              = static fn (string $theme): string => self::HOST . '/badges/' . $style . '-' . $theme . '.svg';

		return $this->link($this->href($style, null), $this->image($src, $this->theme($options), 'Built with Total CMS', $height, $width));
	}

	/** @param array<string,mixed> $options */
	private function member(string $style, string $handle, array $options): string
	{
		$handle = $this->handle($handle);
		if ($handle === null) {
			return $this->generic('pill', $options);
		}

		$src = static fn (string $theme): string => self::HOST . '/@' . $handle . '/badge.svg?'
			. http_build_query(['style' => $style, 'theme' => $theme]);

		// Height only: the width follows the handle and, for earned, the
		// member's counts, so the browser takes it from the SVG itself.
		$image = $this->image($src, $this->theme($options), '@' . $handle . ' on Total CMS', self::HEIGHTS[$style]);

		// rel="me" unless `me: false`: a member's badge is normally on their own site
		return $this->link($this->href($style, $handle), $image, ($options['me'] ?? true) !== false);
	}

	/** The handle, trimmed and lowercased, or null when it is not a valid one. */
	private function handle(string $handle): ?string
	{
		$handle = strtolower(trim($handle));

		return preg_match(self::HANDLE, $handle) === 1 ? $handle : null;
	}

	/**
	 * A built-by badge. Its width follows the studio name, so it gets a
	 * height only; the alt names the handle, since only totalcms.co knows
	 * the studio.
	 *
	 * @param array<string,mixed> $options
	 */
	private function builtByBadge(string $style, string $handle, array $options): string
	{
		$handle = $this->handle($handle);
		if ($handle === null) {
			return $this->generic('pill', $options);
		}

		$theme = $style === 'builtbytext' && ($options['theme'] ?? '') === 'mono' ? 'mono' : $this->theme($options);
		$color = $theme === 'mono' ? $this->hex($options['color'] ?? null) : null;
		$extra = (($options['byName'] ?? false) === true ? ['by' => 'name'] : []) + ($color !== null ? ['color' => $color] : []);
		$src   = static fn (string $theme): string => self::HOST . '/@' . $handle . '/badge.svg?'
			. http_build_query(['style' => $style, 'theme' => $theme] + $extra);

		$image = $this->image($src, $theme, 'Built by @' . $handle, self::HEIGHTS[$style]);

		return $this->link($this->href($style, $handle), $image);
	}

	/** A 3- or 6-digit hex colour, # optional, as 6 lowercase digits; null when it is not one. */
	private function hex(mixed $color): ?string
	{
		if (!is_string($color) || preg_match('/^#?([0-9a-f]{3}|[0-9a-f]{6})$/i', trim($color), $match) !== 1) {
			return null;
		}

		$hex = strtolower($match[1]);

		return strlen($hex) === 3 ? $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2] : $hex;
	}

	/** @param array<string,mixed> $options */
	private function theme(array $options): string
	{
		$theme = $options['theme'] ?? 'light';

		return in_array($theme, ['light', 'dark', 'auto'], true) ? $theme : 'light';
	}

	/**
	 * The link, with Matomo campaign parameters. Profile badges go through
	 * /badges/{handle}, which forwards to the profile while it is public and
	 * to the home page once it is not, so a pasted link never lands on a 404.
	 */
	private function href(string $style, ?string $handle): string
	{
		$query = ['mtm_campaign' => 'badge'];
		if ($handle !== null) {
			$query['mtm_source'] = $handle;
		}
		$query['mtm_content'] = $style;
		$query['mtm_medium']  = 'twig';

		return self::HOST . ($handle !== null ? '/badges/' . $handle : '/') . '?' . http_build_query($query);
	}

	/** @param \Closure(string):string $src */
	private function image(\Closure $src, string $theme, string $alt, int $height, ?int $width = null): string
	{
		$size = ($width !== null ? ' width="' . $width . '"' : '') . ' height="' . $height . '"';

		if ($theme !== 'auto') {
			return '<img src="' . $this->esc($src($theme)) . '" alt="' . $this->esc($alt) . '"' . $size . '>';
		}

		return '<picture><source srcset="' . $this->esc($src('dark')) . '" media="(prefers-color-scheme: dark)">'
			. '<img src="' . $this->esc($src('light')) . '" alt="' . $this->esc($alt) . '"' . $size . '></picture>';
	}

	private function link(string $href, string $inner, bool $me = false): string
	{
		return '<a href="' . $this->esc($href) . '"' . ($me ? ' rel="me"' : '') . '>' . $inner . '</a>';
	}

	private function esc(string $value): string
	{
		return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
	}

	/**
	 * The Total CMS flame for the text badge, 14px: the brand gradient, or
	 * currentColor when `mono`. Gradient ids are prefixed so they do not
	 * collide with the host page's.
	 */
	private function flame(bool $mono): string
	{
		$outer = 'M4595.595,5554.8c-635.41,518.295 -1469.952,512.459 -2073.163,184.497c-603.211,-327.962 -893.103,-1024.883 -816.97,-1625.168c76.133,-600.286 325.034,-937.024 831.61,-1481.671c506.58,-544.647 587.927,-898.961 587.927,-898.961c0,0 76.777,172.764 70.919,585.642c-5.854,412.878 -131.769,562.216 -213.451,922.38c-81.686,360.186 -35.446,442.176 37.759,614.929c34.526,81.46 100.95,153.158 161.519,206.179c-154.222,-286.72 -78.935,-659.073 140.095,-1023.149c225.456,-374.812 591.478,-931.17 486.072,-1742.283c-105.407,-811.113 -816.206,-1297.195 -816.206,-1297.195c0,0 -0.761,339.67 -47.613,699.842c-46.849,360.168 -336.741,705.696 -975.094,1364.542c-638.345,658.847 -866.748,1241.546 -922.384,1762.777c-55.635,521.231 26.355,1320.622 752.549,1938.487c726.197,617.83 1628.083,468.492 1628.083,468.492c885.449,-155.527 1374.209,-685.92 1607.059,-1152.75c-112.622,159.772 -256.477,324.779 -438.71,473.409Z';
		$inner = 'M3020.226,3856.448c-73.205,-172.753 -119.445,-254.744 -37.759,-614.929c81.683,-360.164 207.598,-509.502 213.451,-922.38c5.858,-412.878 -70.919,-585.642 -70.919,-585.642c0,0 -81.347,354.314 -587.927,898.961c-506.577,544.647 -755.477,881.385 -831.61,1481.671c-76.133,600.286 213.759,1297.206 816.97,1625.168c603.211,327.962 1437.754,333.799 2073.163,-184.497c182.233,-148.63 326.088,-313.637 438.71,-473.409c109.085,-218.63 162.071,-423.323 173.284,-569.018c35.159,-456.82 -5.836,-937.02 -421.661,-1455.326c-415.789,-518.292 -368.958,-1057.084 -368.958,-1057.084c-225.456,193.261 -389.438,450.944 -430.433,764.263c-40.996,313.319 0,415.803 70.283,767.196c70.283,351.378 -210.849,696.92 -480.236,711.564c-269.388,14.608 -380.666,-155.209 -380.666,-155.209c-4.941,-8.312 -9.631,-16.695 -14.173,-25.149c-60.57,-53.022 -126.994,-124.719 -161.519,-206.179Z';

		$defs = $mono ? '' : '<defs><linearGradient id="tcms-badge-a" x1="0" y1="0" x2="1" y2="0" gradientUnits="userSpaceOnUse" gradientTransform="matrix(-131.769295,4875.469665,-4875.469665,-131.769295,3114.28138,1578.255403)"><stop offset="0" stop-color="#f37420"/><stop offset="0.7" stop-color="#e03649"/><stop offset="1" stop-color="#d31b60"/></linearGradient><linearGradient id="tcms-badge-b" x1="0" y1="0" x2="1" y2="0" gradientUnits="userSpaceOnUse" gradientTransform="matrix(-52.707792,2793.509672,-2793.509672,-52.707792,3481.222929,2929.887111)"><stop offset="0" stop-color="#faa61a"/><stop offset="1" stop-color="#f26922"/></linearGradient></defs>';

		return '<svg width="14" height="14" viewBox="0 0 6250 6250" aria-hidden="true">' . $defs
			. '<path fill="' . ($mono ? 'currentColor' : 'url(#tcms-badge-a)') . '" d="' . $outer . '"/>'
			. '<path fill="' . ($mono ? 'currentColor' : 'url(#tcms-badge-b)') . '" d="' . $inner . '"/></svg>';
	}
}
