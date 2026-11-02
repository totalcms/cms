<?php

// No strict_types: Twig callers pass numbers where Glide's color and crop
// helpers take strings, and those have always been coerced here.

namespace TotalCMS\Domain\ImageWorks\Service;

use TotalCMS\Domain\ImageWorks\Data\ImageLocation;
use TotalCMS\Domain\Property\Data\ImageData;
use TotalCMS\Domain\Property\Service\PropertyMetaResolver;
use TotalCMS\Support\Config;

/**
 * Turns the params an ImageWorks request asks for into the params Glide
 * renders: presets resolved, sizes snapped and capped at the source, colors
 * and crops taken from the image, and the property's watermark enforced.
 */
final readonly class ImageRenderParams
{
	public function __construct(
		private PropertyMetaResolver $metaResolver,
		private Config $config,
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
	 * @return array<string,int|string>
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
		if (!$hasDefaults && $schemaWatermarks === [] && ($params === [] || (count($params) === 1 && isset($params['fm']) && str_ends_with($imageData->name, (string)$params['fm'])))) {
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

		if (isset($params['mark']) && !isset($params['markw'])) {
			$params['markw'] = '100w';
		}

		// Don't auto-set marktextw for text watermarks - allow fixed-size text via marktextsize
		// Users can explicitly set marktextw if they want scaling behavior

		// Merge schema watermark settings (schema overrides URL parameters for maximum security)
		// Check if watermark should be applied based on limit setting
		if ($schemaWatermarks !== [] && $this->shouldApplyWatermark($params, $schemaWatermarks)) {
			// Remove limit setting before merging (not a valid imageworks parameter)
			unset($schemaWatermarks['limit']);
			$params = array_merge($params, $schemaWatermarks);
		}

		// Only filter out null and empty string values, preserve 0 and other falsy values that are valid for Glide
		return array_filter($params, fn ($value): bool => $value !== null && $value !== '');
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
		$presetName = (string)$params['p'];
		$presets    = $this->config->imageworks['presets'] ?? [];

		if (!isset($presets[$presetName])) {
			return $params;
		}

		$presetValues = $presets[$presetName];

		// Remove preset key since we're resolving it manually
		unset($params['p']);

		// Merge preset values first, then overlay explicit params
		// This means explicit params take precedence over preset values
		return array_merge($presetValues, $params);
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
