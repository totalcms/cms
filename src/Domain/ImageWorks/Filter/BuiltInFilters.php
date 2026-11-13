<?php

declare(strict_types=1);

namespace TotalCMS\Domain\ImageWorks\Filter;

/**
 * The filters that ship with Total CMS (besides greyscale, which the registry
 * adds as a legacy filter). Labels are translation keys. Core names
 * never contain a hyphen; that is how extension names stay clear of them.
 */
final class BuiltInFilters
{
	/**
	 * @SuppressWarnings("PHPMD.ExcessiveMethodLength") one data table of recipes, nothing to branch on
	 *
	 * @return array<string,ImageFilter|array<string,mixed>> name => recipe definition or filter
	 */
	public static function all(): array
	{
		return [
			// sepia keeps its name and URL, but since 3.6.10 it is a recipe:
			// Glide's version washed out to near-white on Imagick.
			'sepia' => [
				'label' => 'imageworks.sepia',
				'group' => 'look',
				'steps' => [
					['sepia', 0.85],
					['contrast', 1.05],
				],
			],
			'bloom' => [
				'label' => 'imageworks.filter.bloom',
				'group' => 'look',
				'steps' => [
					['brightness', 1.12],
					['contrast', 0.85],
					['saturate', 1.05],
					['overlay', 'ffd9a8', 'screen', 0.15],
				],
			],
			'harbor' => [
				'label' => 'imageworks.filter.harbor',
				'group' => 'look',
				'steps' => [
					['contrast', 1.2],
					['saturate', 1.1],
					['overlay', '4a90d9', 'soft-light', 0.3],
					['overlay', '0b3d6b', 'screen', 0.35],
				],
			],
			'ember' => [
				'label' => 'imageworks.filter.ember',
				'group' => 'look',
				'steps' => [
					['sepia', 0.15],
					['saturate', 1.35],
					['overlay', 'ff9a3c', 'soft-light', 0.35],
					['vignette', 0.3],
				],
			],
			'meadow' => [
				'label' => 'imageworks.filter.meadow',
				'group' => 'look',
				'steps' => [
					['fade', 0.15],
					['saturate', 0.85],
					['hue-rotate', 5],
					['overlay', 'c8dc7a', 'soft-light', 0.3],
				],
			],
			'dusk' => [
				'label' => 'imageworks.filter.dusk',
				'group' => 'look',
				'steps' => [
					['brightness', 0.9],
					['contrast', 1.05],
					['overlay', '7a3b69', 'soft-light', 0.4],
				],
			],
			'attic' => [
				'label' => 'imageworks.filter.attic',
				'group' => 'look',
				'steps' => [
					['sepia', 0.45],
					['contrast', 0.85],
					['fade', 0.1],
					['saturate', 0.85],
					['vignette', 0.35],
				],
			],
			'pop' => [
				'label' => 'imageworks.filter.pop',
				'group' => 'look',
				'steps' => [
					['saturate', 1.4],
					['contrast', 1.15],
				],
			],
			'matte' => [
				'label' => 'imageworks.filter.matte',
				'group' => 'look',
				'steps' => [
					['fade', 0.15],
					['contrast', 0.9],
					['saturate', 0.75],
				],
			],
			'polar' => [
				'label' => 'imageworks.filter.polar',
				'group' => 'look',
				'steps' => [
					['brightness', 1.06],
					['saturate', 0.55],
					['overlay', '5fa0e0', 'soft-light', 0.45],
				],
			],
			'noir' => [
				'label' => 'imageworks.filter.noir',
				'group' => 'look',
				'steps' => [
					['grayscale', 1],
					['contrast', 1.3],
					['vignette', 0.4],
				],
			],
			'ash' => [
				'label' => 'imageworks.filter.ash',
				'group' => 'look',
				'steps' => [
					['grayscale', 1],
					['contrast', 0.85],
					['fade', 0.1],
				],
			],
			'lagoon' => [
				'label' => 'imageworks.filter.lagoon',
				'group' => 'duotone',
				'steps' => [
					['gradient-map', '0b1f3a', '5ce1e6'],
				],
			],
			'sunset' => [
				'label' => 'imageworks.filter.sunset',
				'group' => 'duotone',
				'steps' => [
					['gradient-map', '3a0f4f', 'ff8c42'],
				],
			],
			'fern' => [
				'label' => 'imageworks.filter.fern',
				'group' => 'duotone',
				'steps' => [
					['gradient-map', '113a26', 'cfe3c1'],
				],
			],
			'blush' => [
				'label' => 'imageworks.filter.blush',
				'group' => 'duotone',
				'steps' => [
					['gradient-map', '4a0e24', 'f6d5dc'],
				],
			],
			'vignette' => [
				'label' => 'imageworks.filter.vignette',
				'group' => 'effect',
				'steps' => [
					['vignette', 0.5],
				],
			],
			'grain' => [
				'label' => 'imageworks.filter.grain',
				'group' => 'effect',
				'steps' => [
					['grain', 0.5],
				],
			],
			'warm' => [
				'label' => 'imageworks.filter.warm',
				'group' => 'effect',
				'steps' => [
					['overlay', 'ff9a3c', 'soft-light', 0.25],
				],
			],
			'cool' => [
				'label' => 'imageworks.filter.cool',
				'group' => 'effect',
				'steps' => [
					['overlay', '3c8cff', 'soft-light', 0.25],
				],
			],
			'leak' => [
				'label' => 'imageworks.filter.leak',
				'group' => 'effect',
				'steps' => [
					['leak', 'ff7a3c', 'top-right', 0.55],
				],
			],
			'duotone' => new DuotoneFilter(),
		];
	}
}
