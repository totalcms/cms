<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Seo\Data;

/**
 * The starting points the overlay designer offers. Each is an overlay in the
 * stored format, `{marks: [...]}`, in relative units only (`w`, `h`), so a
 * preset drawn as a small thumbnail matches the full 1200×630 card.
 *
 * Names and descriptions are translated in the browser
 * (`overlay.preset.{id}.name` / `.desc` in the js catalog).
 */
final class OverlayPresets
{
	/** The full-height bottom fade that keeps white text readable on any photo. */
	public const FADE = ['markfill' => '0b0f1a', 'markgradient' => 'bottom', 'markh' => '100h', 'markpos' => 'bottom', 'markalpha' => 85];

	/** The share title, lower left, wrapping onto up to three lines. */
	public const TITLE = ['marktext' => '${socialTitle}', 'marktextw' => '80w', 'marktextsize' => '7w', 'marktextpos' => 'bottom-left', 'marktextpad' => '5w'];

	/**
	 * Every preset, in the order the designer shows them. The logo preset
	 * needs a file in the watermarks gallery and is left out without one.
	 *
	 * @param string $logo The first file in the watermarks gallery, or ''
	 *
	 * @return list<array{id:string,overlay:array{marks:list<array<string,int|string>>}}>
	 */
	public static function all(string $logo = ''): array
	{
		$small = ['marktextsize' => '3w', 'marktextpos' => 'top-left', 'marktextpad' => '5w'];

		$presets = [
			['id' => 'title-fade', 'overlay' => ['marks' => [self::FADE, self::TITLE]]],
			['id' => 'title-site', 'overlay' => ['marks' => [self::FADE, self::TITLE, ['marktext' => '${site}'] + $small]]],
			['id' => 'byline', 'overlay' => ['marks' => [self::FADE, self::TITLE, ['marktext' => 'By ${author}'] + $small]]],
			['id' => 'band', 'overlay' => ['marks' => [
				['markfill' => '0b0f1a', 'markh' => '36h', 'markpos' => 'bottom', 'markalpha' => 90],
				// Lifted with marktexty, not padding: padding moves a centered
				// mark sideways as well as up.
				['marktext' => '${socialTitle}', 'marktextw' => '90w', 'marktextsize' => '4.5w', 'marktextlines' => 2, 'marktextalign' => 'center', 'marktextpos' => 'bottom', 'marktexty' => '5w'],
			]]],
		];

		if ($logo !== '') {
			$presets[] = ['id' => 'logo', 'overlay' => ['marks' => [
				['markalpha' => 60] + self::FADE,
				self::TITLE,
				['mark' => $logo, 'markw' => '15w', 'markpos' => 'top-right', 'markpad' => '4w'],
			]]];
		}

		return $presets;
	}
}
