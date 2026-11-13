<?php

// No strict_types: Twig callers pass numbers where Glide's color and crop
// helpers take strings, and those have always been coerced here.

namespace TotalCMS\Domain\ImageWorks\Service;

use TotalCMS\Domain\ImageWorks\Data\ImageLocation;
use TotalCMS\Domain\ImageWorks\Filter\ImageFilterRegistry;
use TotalCMS\Domain\License\Data\EditionFeature;
use TotalCMS\Domain\License\Service\EditionFeatureService;
use TotalCMS\Domain\Property\Data\ImageData;
use TotalCMS\Domain\Property\Service\PropertyMetaResolver;
use TotalCMS\Support\Config;

/**
 * Turns the params an ImageWorks request asks for into the params Glide
 * renders: presets resolved, sizes snapped and capped at the source, colors
 * and crops taken from the image, and the property's watermark enforced.
 * Below Pro the layered marks are taken out (BasicMarks): the image still
 * renders, with only the marks every edition draws.
 */
final readonly class ImageRenderParams
{
	public function __construct(
		private PropertyMetaResolver $metaResolver,
		private Config $config,
		private EditionFeatureService $editions,
		private ImageFilterRegistry $filters,
	) {
	}

	/**
	 * The params to render with, or [] when the original file is served as is.
	 *
	 * @SuppressWarnings("PHPMD.CyclomaticComplexity")
	 * @SuppressWarnings("PHPMD.NPathComplexity")
	 *
	 * @param array<string,mixed> $params
	 *
	 * @return array<string,mixed>
	 */
	public function clean(array $params, ImageData $imageData, ImageLocation $location, ?SizeLadder $sizeLadder = null): array
	{
		// Remove metadata params that don't affect image processing
		unset($params['id'], $params['collection'], $params['property'], $params['name'], $params['cache']);

		$defaults         = $this->config->imageworks['defaults'] ?? [];
		$hasDefaults      = is_array($defaults) && $defaults !== [];
		$schemaWatermarks = $this->watermarkSettings($location->collection, $location->property, $location->id);

		// If no params are provided, no defaults configured, and no watermark settings, return the original image
		// The Action class automatically adds the format to the params so we need to check for that
		// If the only param is 'fm' and it matches the original image's format, return original
		// But if defaults or watermarks exist, we must let Glide process the image
		if (!$hasDefaults && $schemaWatermarks === [] && $this->isOriginal($params, $imageData)) {
			return [];
		}

		// Resolve preset first so dimension constraints apply to preset values too
		if (isset($params['p'])) {
			$params = $this->resolvePreset($params);
		}

		if ($sizeLadder instanceof SizeLadder) {
			$params = $sizeLadder->snap($params, $imageData);
		}

		// Make sure that the requested width and height are not larger than the original image
		if (isset($params['w']) && $params['w'] > $imageData->width && $imageData->width > 0) {
			$params['w'] = $imageData->width;
		}

		if (isset($params['w']) && $params['w'] == 0) {
			unset($params['w']);
		}

		if (isset($params['h']) && $params['h'] > $imageData->height && $imageData->height > 0) {
			$params['h'] = $imageData->height;
		}

		if (isset($params['h']) && $params['h'] == 0) {
			unset($params['h']);
		}

		if (isset($params['fit'])) {
			$params['fit'] = GlideFactory::cropFocalpoint($params['fit'], $imageData->focalpoint);
		}

		if (isset($params['bg'])) {
			$params['bg'] = GlideFactory::updateBackgroundColor($params['bg'], $imageData->palette);
		}

		if (isset($params['border'])) {
			$params['border'] = GlideFactory::updateBorderColor($params['border'], $imageData->palette);
		}

		// The property's watermark is drawn on top of anything the URL asks
		// for, as its own layers (see MarkLayers::withSettings), so no URL key
		// can change it. Below its size limit the image is left unmarked.
		if ($schemaWatermarks !== [] && $this->shouldApplyWatermark($params, $schemaWatermarks)) {
			unset($schemaWatermarks['limit']);
			$params['marksettings'] = $schemaWatermarks;
		}

		$params = $this->cleanFilters($params, $imageData->palette);

		// An unknown-only `filt` is gone now: serve the original as it would be with no `filt`
		if (!$hasDefaults && $schemaWatermarks === [] && $this->isOriginal($params, $imageData)) {
			return [];
		}

		if (!$this->editions->can(EditionFeature::IMAGE_LAYERS)) {
			$params = BasicMarks::only($params);
		}

		// Only filter out null and empty string values, preserve 0 and other falsy values that are valid for Glide
		return array_filter($params, fn ($value): bool => $value !== null && $value !== '');
	}

	/**
	 * Nothing asked for, or only the source's own format.
	 *
	 * @param array<string,mixed> $params
	 */
	private function isOriginal(array $params, ImageData $imageData): bool
	{
		return $params === [] || (count($params) === 1 && isset($params['fm']) && str_ends_with($imageData->name, (string)$params['fm']));
	}

	/**
	 * The filter params, held to the same rules whether they came from a URL,
	 * a preset, the defaults or a template: only known names (at most 3), a
	 * strength from 1 to 99 (0 is no filter), a normalized duotone pair only
	 * for `duotone`, and a `filtv` of our own so a changed recipe is a new
	 * cache file.
	 *
	 * Glide merges `imageworks.defaults` after this runs, so a default `filt`
	 * is cleaned here too: it is copied in when the request has none, or when
	 * every name the request gave was dropped.
	 *
	 * @param array<string,mixed> $params
	 * @param array<string>       $palette
	 *
	 * @return array<string,mixed>
	 */
	private function cleanFilters(array $params, array $palette): array
	{
		$defaults = $this->config->imageworks['defaults'] ?? [];
		$defaults = is_array($defaults) ? $defaults : [];

		$names = $this->filterNames($params['filt'] ?? null);
		if ($names === [] && !empty($defaults['filt'])) {
			unset($params['filt'], $params['filtamt'], $params['duo']);
			foreach (['filt', 'filtamt', 'duo'] as $key) {
				if (isset($defaults[$key])) {
					$params[$key] = $defaults[$key];
				}
			}
			$names = $this->filterNames($params['filt']);
		}

		// Never trust an incoming version: it is ours to set.
		unset($params['filtv']);

		if ($names === []) {
			unset($params['filt'], $params['filtamt'], $params['duo']);

			return $params;
		}

		$params['filt'] = implode(',', $names);

		if (isset($params['filtamt']) && is_numeric($params['filtamt'])) {
			$amount = (int)round((float)$params['filtamt']);
			if ($amount >= 100) {
				unset($params['filtamt']);
			} elseif ($amount <= 0) {
				// No strength is no filter. A default `filt` would come back
				// with Glide's defaults, so then the 0 stays to switch it off.
				unset($params['filt'], $params['filtamt'], $params['duo']);
				if (!empty($defaults['filt'])) {
					$params['filtamt'] = 0;
				}

				return $params;
			} else {
				$params['filtamt'] = $amount;
			}
		} else {
			unset($params['filtamt']);
		}

		if (!in_array('duotone', $names, true)) {
			unset($params['duo']);
		} else {
			$params['duo'] = $this->duoColors($params['duo'] ?? null, $palette);
		}

		$version = $this->filters->chainVersion($names);
		if ($version !== null) {
			$params['filtv'] = $version;
		}

		return $params;
	}

	/**
	 * The duotone pair as two lowercase 6-digit hex colors, `paletteN`
	 * resolved from the image. A 3-digit color is expanded, so `fff` and
	 * `FFFFFF` are one cache file; a pair with anything else in it is the
	 * default `000000,palette0`.
	 *
	 * @param array<string> $palette
	 */
	private function duoColors(mixed $duo, array $palette): string
	{
		$colors = is_scalar($duo) ? array_map('trim', explode(',', (string)$duo)) : [];
		if (count($colors) !== 2 || in_array(null, array_map(self::duoColor(...), $colors), true)) {
			$colors = ['000000', 'palette0'];
		}

		return implode(',', array_map(
			fn (string $color): string => self::duoColor(GlideFactory::updateBackgroundColor((string)self::duoColor($color), $palette)) ?? 'ffffff',
			$colors
		));
	}

	/** A hex color as 6 lowercase digits, `paletteN` as it is, anything else null. */
	private static function duoColor(string $color): ?string
	{
		$color = strtolower($color);
		if (preg_match('/^palette\d{1,2}$/', $color) === 1) {
			return $color;
		}
		if (preg_match('/^#?([0-9a-f]{3}|[0-9a-f]{6})$/', $color, $match) !== 1) {
			return null;
		}

		$hex = $match[1];

		return strlen($hex) === 3 ? $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2] : $hex;
	}

	/**
	 * The known filter names a `filt` value holds: lowercased, matching the
	 * name pattern, at most 3.
	 *
	 * @return list<string>
	 */
	private function filterNames(mixed $filt): array
	{
		if (!is_string($filt) || $filt === '') {
			return [];
		}

		$names = [];
		foreach (explode(',', strtolower($filt)) as $name) {
			$name = trim($name);
			if (preg_match(ImageFilterRegistry::NAME_PATTERN, $name) === 1 && $this->filters->get($name) !== null) {
				$names[] = $name;
			}
		}

		return array_slice(array_values(array_unique($names)), 0, 3);
	}

	/**
	 * The watermark settings one property resolves to: schema, then
	 * collection, then the object's own override. [] when it has none.
	 * Watermarks are enforced and cannot be overridden via URL parameters.
	 *
	 * @return array<string,string|int>
	 */
	public function watermarkSettings(string $collection, string $property, string $id = ''): array
	{
		try {
			$settings = $this->metaResolver->resolveSettings($collection, $property, $id);

			return $settings['watermark'] ?? [];
		} catch (\Exception) {
			return [];
		}
	}

	/**
	 * The params without any `mark*` key: what Glide renders before the
	 * watermarks are added.
	 *
	 * @param array<string,mixed> $params
	 *
	 * @return array<string,mixed>
	 */
	public static function withoutWatermarks(array $params): array
	{
		return array_filter(
			$params,
			fn ($param): bool => !str_starts_with((string)$param, 'mark'),
			ARRAY_FILTER_USE_KEY
		);
	}

	/**
	 * Resolve preset values into params.
	 * Preset values are merged first, then explicit params override them.
	 * This ensures dimension constraints are applied to preset w/h values.
	 *
	 * @param array<string,mixed> $params
	 *
	 * @return array<string,mixed>
	 */
	private function resolvePreset(array $params): array
	{
		$presets = $this->config->imageworks['presets'] ?? [];
		$merged  = [];
		$found   = false;

		// A comma list applies in order, later presets over earlier, as Glide does
		foreach (explode(',', (string)$params['p']) as $name) {
			$name = trim($name);
			if (isset($presets[$name]) && is_array($presets[$name])) {
				$merged = array_merge($merged, $presets[$name]);
				$found  = true;
			}
		}

		if (!$found) {
			return $params;
		}

		// Remove preset key since we're resolving it manually
		unset($params['p']);

		// Preset values first, then explicit params override them,
		// so dimension constraints apply to preset w/h values
		return array_merge($merged, $params);
	}

	/**
	 * Determine if watermark should be applied based on limit setting.
	 * Watermark is applied if:
	 * - No limit is set (always watermark), OR
	 * - Requested width exceeds limit, OR
	 * - Requested height exceeds limit, OR
	 * - No dimensions requested (original image).
	 *
	 * Sizes count `dpr`: Glide multiplies the output by it, so `w=600&dpr=3`
	 * is an 1800px image.
	 *
	 * @param array<string,string|int> $params Request parameters
	 * @param array<string,string|int> $watermarkSettings Schema watermark settings
	 */
	private function shouldApplyWatermark(array $params, array $watermarkSettings): bool
	{
		// If no limit is set, always apply watermark
		if (!isset($watermarkSettings['limit'])) {
			return true;
		}

		$limit = (int)$watermarkSettings['limit'];
		$dpr   = (float)($params['dpr'] ?? 1);
		// Glide treats anything outside 0–8 as 1.
		$dpr = $dpr > 0 && $dpr <= 8 ? $dpr : 1.0;

		// If no dimensions requested (original image), apply watermark
		if (!isset($params['w']) && !isset($params['h'])) {
			return true;
		}

		// Check if requested width exceeds limit
		if (isset($params['w']) && (int)$params['w'] * $dpr > $limit) {
			return true;
		}

		// Check if requested height exceeds limit
		// Dimensions are below limit, don't apply watermark
		return isset($params['h']) && (int)$params['h'] * $dpr > $limit;
	}
}
