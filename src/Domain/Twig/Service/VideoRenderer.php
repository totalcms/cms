<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Twig\Service;

use TotalCMS\Domain\Rendering\Utilities\EmbedBuilder;
use TotalCMS\Domain\Rendering\Utilities\HTMLUtils;
use TotalCMS\Domain\Twig\Adapter\DataTwigAdapter;
use TotalCMS\Domain\Twig\Adapter\MediaTwigAdapter;
use TotalCMS\Domain\Video\Data\VideoInfo;
use TotalCMS\Domain\Video\Provider\VideoProvider;
use TotalCMS\Domain\Video\Service\VideoUrlResolver;

/**
 * Renders a `video` field value as HTML for cms.render.video(): a lazy
 * iframe for a hosted provider, a `<video>` element for a direct file, or a
 * click-to-play facade (poster + play button, `video-facade.js` swaps in the
 * embed). Also handles a plain `file`-field upload with a video mime.
 *
 * RenderTwigAdapter::video() is the Twig-facing entry point and delegates
 * here, the same way the grid, depot browser and htmx helpers are their own
 * services. Needs the media adapter for poster/stream URLs and the data
 * adapter to fetch a stored value by id.
 */
class VideoRenderer
{
	public function __construct(
		private readonly MediaTwigAdapter $media,
		private readonly DataTwigAdapter $data,
	) {
	}

	/**
	 * Render a `video` field property (or a local `file`-field video) as an
	 * embed, `<video>` element, or click-to-play facade — the body of
	 * cms.render.video().
	 *
	 * @param string|array<string,mixed>|null $idOrObject Object array or object ID string
	 * @param array<string,mixed> $options collection, property, autoplay, loop, muted, controls, class, poster, facade, imageworks
	 *   `imageworks` is an ImageWorks parameter array (`{w: 800, fm: 'webp'}`) applied to an
	 *   uploaded poster — the same array cms.render.image() takes positionally. It never
	 *   touches a vendor thumbnail or a `poster` URL override, neither of which is ours.
	 */
	public function render(string|array|null $idOrObject, array $options = []): string
	{
		$options = array_merge([
			'collection' => 'video',
			'property'   => 'video',
			'autoplay'   => false,
			'loop'       => false,
			'muted'      => false,
			'controls'   => true,
			'class'      => '',
			'poster'     => '',
			'facade'     => true,
			'imageworks' => [],
		], $options);

		if (in_array($idOrObject, [null, '', []], true)) {
			return '';
		}

		$value = $this->resolveVideoValue($idOrObject, $options);
		if ($value === null) {
			return '';
		}

		// A plain `file`-field upload (video/* mime) — not the composite
		// `video` field shape. Render it through cms.media.stream() directly.
		if (array_key_exists('name', $value) && is_string($value['mime'] ?? null) && str_starts_with($value['mime'], 'video/')) {
			return $this->fileFieldVideo($idOrObject, $options);
		}

		$url = trim((string)($value['url'] ?? ''));
		if ($url === '') {
			return '';
		}

		// A URL with an unsafe scheme (javascript:, data:, ftp:, …) is never
		// safe to embed — checked directly against the raw stored url so it
		// doesn't depend on how a provider happens to resolve it. Covers both
		// the facade and eager branches below; the file branch is unreachable
		// for such URLs after this guard. A schemeless relative URL or a
		// protocol-relative one is NOT unsafe and renders as before.
		if (VideoUrlResolver::hasUnsafeScheme($url)) {
			return '';
		}

		$info = (new VideoUrlResolver(VideoUrlResolver::defaultProviders()))->resolve($url);

		$title            = (string)($value['title'] ?? '');
		$posterImageworks = is_array($options['imageworks']) ? $options['imageworks'] : [];
		$storedRatio      = (string)($value['aspectRatio'] ?? '');
		$aspectRatio      = $storedRatio !== '' ? $storedRatio : $info->aspectRatio;

		if ($info->provider === 'file') {
			$posterUrl = (string)$options['poster'] !== '' ? (string)$options['poster'] : $this->media->videoPoster($idOrObject, $posterImageworks, $options);

			return $this->fileVideo($info->embedUrl !== '' ? $info->embedUrl : $url, $posterUrl, $aspectRatio, $options);
		}

		// The facade is the default (`facade: false` forces an eager iframe):
		// a poster or vendor thumbnail with a play button costs one image
		// instead of a player, and video-facade.js swaps the iframe in on
		// click. An `unknown` embed URL is the author's own pasted URL,
		// verbatim — never mutated with a query string, facade or not
		// (EmbedBuilder::iframe() matches today's embed() behaviour; forcing
		// autoplay=1 onto an arbitrary URL isn't safe to assume).
		if (!empty($options['facade'])) {
			$posterUrl = (string)$options['poster'] !== '' ? (string)$options['poster'] : $this->media->videoPoster($idOrObject, $posterImageworks, $options);

			// No resolvable poster (no upload, no vendor thumbnail, no override) —
			// a facade with no image to show isn't useful; fall through to the
			// same eager embed a non-facade call would render.
			if ($posterUrl !== '') {
				$embed = $info->provider === 'unknown' ? $url : $this->buildVideoEmbedUrl($info, $options, true);

				return $this->videoFacade($embed, $posterUrl, $title, $aspectRatio, $options);
			}
		}

		if ($info->provider === 'unknown') {
			return $this->wrapVideoEmbed(EmbedBuilder::iframe($url), $aspectRatio, $options);
		}

		$embed = $this->buildVideoEmbedUrl($info, $options);

		return $this->iframeVideo($embed, $title, $aspectRatio, $options);
	}

	/**
	 * Resolve the raw property value for cms.render.video(), descending
	 * dotted `property` paths the same way image()/imagePath() do.
	 *
	 * @param string|array<string,mixed> $idOrObject
	 * @param array<string,mixed> $options
	 *
	 * @return array<string,mixed>|null
	 */
	private function resolveVideoValue(string|array $idOrObject, array $options): ?array
	{
		[$rootProp, $segments] = MediaTwigAdapter::splitDottedProperty((string)$options['property']);

		if (is_array($idOrObject)) {
			$value = MediaTwigAdapter::descendDottedPath($idOrObject, $rootProp, $segments);
		} else {
			$value = $this->data->raw((string)$options['collection'], $idOrObject, $rootProp);
			foreach ($segments as $segment) {
				$value = is_array($value) ? ($value[$segment] ?? null) : null;
			}
		}

		return is_array($value) && $value !== [] ? $value : null;
	}

	/**
	 * A local upload through a plain `file` field (video/* mime) rather than
	 * the composite `video` field shape.
	 *
	 * @param string|array<string,mixed> $idOrObject
	 * @param array<string,mixed> $options
	 */
	private function fileFieldVideo(string|array $idOrObject, array $options): string
	{
		$streamUrl = $this->media->stream($idOrObject, $options);
		if ($streamUrl === '') {
			return '';
		}

		$posterOverride = (string)($options['poster'] ?? '');

		return HTMLUtils::element('video', '', [
			'src'         => $streamUrl,
			'playsinline' => true,
			'controls'    => !empty($options['controls']),
			'autoplay'    => !empty($options['autoplay']),
			'loop'        => !empty($options['loop']),
			'muted'       => !empty($options['muted']),
			'poster'      => $posterOverride !== '' ? $posterOverride : null,
		]);
	}

	/**
	 * The `file` provider (a direct .mp4/.webm/... URL) rendered as a bare
	 * `<video>` element inside the standard aspect-ratio wrapper.
	 *
	 * @param array<string,mixed> $options
	 */
	private function fileVideo(string $url, string $posterUrl, string $aspectRatio, array $options): string
	{
		$video = HTMLUtils::element('video', '', [
			'src'         => $url,
			'playsinline' => true,
			'controls'    => !empty($options['controls']),
			'autoplay'    => !empty($options['autoplay']),
			'loop'        => !empty($options['loop']),
			'muted'       => !empty($options['muted']),
			'poster'      => $posterUrl !== '' ? $posterUrl : null,
		]);

		return $this->wrapVideoEmbed($video, $aspectRatio, $options);
	}

	/**
	 * A known iframe provider (YouTube, Vimeo, Livid, Bunny, Cloudflare,
	 * Loom, Wistia) rendered as an eagerly-loaded iframe.
	 *
	 * @param array<string,mixed> $options
	 */
	private function iframeVideo(string $embedUrl, string $title, string $aspectRatio, array $options): string
	{
		$iframe = HTMLUtils::element('iframe', '', [
			'src'             => $embedUrl,
			'title'           => $title !== '' ? $title : null,
			'loading'         => 'lazy',
			'allow'           => 'accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture; web-share',
			'allowfullscreen' => true,
			'referrerpolicy'  => 'strict-origin-when-cross-origin',
		]);

		return $this->wrapVideoEmbed($iframe, $aspectRatio, $options);
	}

	/**
	 * The click-to-play facade: a poster image + play button. `video-facade.js`
	 * builds the real iframe from `data-embed` on click.
	 *
	 * @param array<string,mixed> $options
	 */
	private function videoFacade(string $embedUrl, string $posterUrl, string $title, string $aspectRatio, array $options): string
	{
		$class = HTMLUtils::mergeClasses('cms-video-facade', (string)($options['class'] ?? ''));

		$img = HTMLUtils::inlineElement('img', [
			'src'     => $posterUrl,
			'alt'     => $title,
			'loading' => 'lazy',
		]);

		$button = HTMLUtils::element('button', '', [
			'type'       => 'button',
			'aria-label' => trim('Play ' . $title),
		]);

		return HTMLUtils::element('div', $img . $button, [
			'class'      => $class,
			'data-embed' => $embedUrl,
			'style'      => '--cms-video-ratio: ' . self::aspectRatioStyle($aspectRatio),
		]);
	}

	/** @param array<string,mixed> $options */
	private function wrapVideoEmbed(string $inner, string $aspectRatio, array $options): string
	{
		$class = HTMLUtils::mergeClasses('cms-video-embed', (string)($options['class'] ?? ''));

		return HTMLUtils::element('div', $inner, [
			'class' => $class,
			'style' => '--cms-video-ratio: ' . self::aspectRatioStyle($aspectRatio),
		]);
	}

	/**
	 * Build the embed URL's query string via the matching provider's
	 * embedQuery(). Providers with no real implementation (AbstractVideoProvider's
	 * default) still get `autoplay=1` when autoplay is requested — "other
	 * providers get at most autoplay=1".
	 *
	 * @param array<string,mixed> $options
	 */
	private function buildVideoEmbedUrl(VideoInfo $info, array $options, bool $forceAutoplay = false): string
	{
		$provider = $this->findVideoProvider($info->provider);
		$autoplay = $forceAutoplay || !empty($options['autoplay']);

		$query = $provider instanceof VideoProvider ? $provider->embedQuery([
			'autoplay' => $autoplay,
			'muted'    => !empty($options['muted']),
			'loop'     => !empty($options['loop']),
			'videoId'  => $info->videoId,
		]) : '';

		if ($query === '' && $autoplay) {
			$query = 'autoplay=1';
		}

		if ($query === '') {
			return $info->embedUrl;
		}

		return $info->embedUrl . (str_contains($info->embedUrl, '?') ? '&' : '?') . $query;
	}

	private function findVideoProvider(string $id): ?VideoProvider
	{
		foreach (VideoUrlResolver::defaultProviders() as $provider) {
			if ($provider->id() === $id) {
				return $provider;
			}
		}

		return null;
	}

	/**
	 * "16:9" -> "16 / 9"; falls back to "16 / 9" for anything malformed or empty.
	 *
	 * Emitted as the `--cms-video-ratio` custom property on the wrapper and the
	 * facade rather than a direct inline `aspect-ratio`, so the stylesheet keeps
	 * the last word and a site rule can change the shape without `!important`.
	 */
	public static function aspectRatioStyle(string $ratio): string
	{
		if (preg_match('/^(\d+(?:\.\d+)?):(\d+(?:\.\d+)?)$/', $ratio, $m) === 1) {
			return "{$m[1]} / {$m[2]}";
		}

		return '16 / 9';
	}
}
