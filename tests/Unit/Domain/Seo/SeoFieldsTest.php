<?php

declare(strict_types=1);

use TotalCMS\Domain\Seo\Data\SeoFields;

test('SeoFields reads the card and defaults the rest', function (): void {
	$f = SeoFields::fromArray(['title' => ' Custom ', 'socialTitle' => ' Short ', 'noindex' => '1', 'image' => ['name' => 'a.jpg', 'size' => 12]]);
	expect($f->title)->toBe('Custom')->and($f->socialTitle)->toBe('Short')->and($f->noindex)->toBeTrue()->and($f->nofollow)->toBeFalse()
		->and($f->image)->toBe(['name' => 'a.jpg', 'size' => 12])->and($f->canonical)->toBe('')->and($f->jsonldType)->toBe('');
	expect(SeoFields::fromArray([])->socialTitle)->toBe('');
	expect(SeoFields::fromArray([])->hasImage())->toBeFalse();
	expect(SeoFields::fromArray(['image' => ['name' => '', 'size' => 0]])->hasImage())->toBeFalse();
});
