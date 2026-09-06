<?php

declare(strict_types=1);

use TotalCMS\Domain\Admin\TotalForm;
use TotalCMS\Domain\Feed\Service\PodcastCategories;

/**
 * `propertyOptions: podcastCategories` renders Apple's taxonomy as optgroups:
 * one group per parent, the parent itself selectable as the first entry,
 * children beneath it. Values are the canonical strings PodcastTags accepts.
 */
describe('TotalForm::podcastCategoryOptions', function (): void {
	test('returns one group per parent, parent first, children after', function (): void {
		$groups = TotalForm::podcastCategoryOptions();

		expect(array_keys($groups))->toBe(array_keys(PodcastCategories::TAXONOMY));
		expect($groups['Arts'][0])->toBe(['value' => 'Arts', 'label' => 'Arts']);
		expect($groups['Arts'][1])->toBe(['value' => 'Arts > Books', 'label' => 'Books']);
		expect($groups['Technology'])->toBe([['value' => 'Technology', 'label' => 'Technology']]);
	});

	test('every value is a canonical category', function (): void {
		foreach (TotalForm::podcastCategoryOptions() as $options) {
			foreach ($options as $option) {
				expect(PodcastCategories::canonical($option['value']))->toBe($option['value']);
			}
		}
	});
});
