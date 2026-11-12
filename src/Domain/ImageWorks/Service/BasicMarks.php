<?php

declare(strict_types=1);

namespace TotalCMS\Domain\ImageWorks\Service;

/**
 * The marks every edition draws: one logo (tiled or not) and one line of
 * text. The layered marks are a Pro feature (EditionFeature::IMAGE_LAYERS):
 * the `marks` list, an image from another record, color fills and wrapped
 * text. Below Pro they are taken out of the params, so an image still
 * renders, without them, rather than failing.
 */
final class BasicMarks
{
	/** Keys only the layered marks use. */
	private const LAYER_KEYS = ['marks', 'markimage', 'markshape', 'markfill', 'markgradient', 'marktextlines'];

	/**
	 * The params with the layered marks taken out, the schema's watermark
	 * settings (`marksettings`) included.
	 *
	 * @param array<string,mixed> $params
	 *
	 * @return array<string,mixed>
	 */
	public static function only(array $params): array
	{
		foreach (self::LAYER_KEYS as $key) {
			unset($params[$key]);
		}

		// A width and a size together wrap the text. Without wrapping, the
		// size alone draws one line, as it did before.
		if (isset($params['marktextw'], $params['marktextsize'])) {
			unset($params['marktextw']);
		}

		if (is_array($params['marksettings'] ?? null)) {
			/** @var array<string,mixed> $settings */
			$settings               = $params['marksettings'];
			$params['marksettings'] = self::only($settings);
		}

		return $params;
	}
}
