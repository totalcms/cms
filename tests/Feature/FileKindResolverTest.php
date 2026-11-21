<?php

declare(strict_types=1);

use TotalCMS\Domain\Backup\Service\FileKindResolver;
use TotalCMS\Domain\Collection\Service\CollectionSaver;
use TotalCMS\Domain\Schema\Service\SchemaSaver;

/*
 * The file history has to know what a file is before it can decide whether to
 * keep it: an image, a file, a gallery picture or a depot entry. The answer
 * comes from the schema and the folder the file sits in under its property.
 */

beforeEach(function (): void {
	recursiveDelete(cmsDataDir());
	restoreFixtures();
	$this->setUpApp(bootstrap());
	$c       = $this->app->getContainer();
	$schemas = $c->get(SchemaSaver::class);
	$schemas->saveSchema(['id' => 'member', 'type' => 'object', 'properties' => [
		'id'    => ['$ref' => 'https://www.totalcms.co/schemas/properties/slug.json', 'field' => 'id'],
		'name'  => ['type' => 'string', 'field' => 'text'],
		'photo' => ['$ref' => 'https://www.totalcms.co/schemas/properties/image.json', 'field' => 'image'],
		'cv'    => ['$ref' => 'https://www.totalcms.co/schemas/properties/file.json', 'field' => 'file'],
		'reel'  => ['type' => 'video', 'field' => 'video'],
	]]);
	$schemas->saveSchema(['id' => 'kinds', 'type' => 'object', 'properties' => [
		'id'        => ['$ref' => 'https://www.totalcms.co/schemas/properties/slug.json', 'field' => 'id'],
		'title'     => ['type' => 'string', 'field' => 'text'],
		'cover'     => ['$ref' => 'https://www.totalcms.co/schemas/properties/image.json', 'field' => 'image'],
		'pdf'       => ['$ref' => 'https://www.totalcms.co/schemas/properties/file.json', 'field' => 'file'],
		'photos'    => ['$ref' => 'https://www.totalcms.co/schemas/properties/gallery.json', 'field' => 'gallery'],
		'downloads' => ['$ref' => 'https://www.totalcms.co/schemas/properties/depot.json', 'field' => 'depot'],
		'promo'     => ['type' => 'video', 'field' => 'video'],
		'lead'      => ['$ref' => 'https://www.totalcms.co/schemas/properties/card.json', 'field' => 'card', 'schemaref' => 'https://www.totalcms.co/schemas/custom/member.json'],
		'team'      => ['$ref' => 'https://www.totalcms.co/schemas/properties/deck.json', 'field' => 'deck', 'schemaref' => 'https://www.totalcms.co/schemas/custom/member.json'],
	], 'index' => ['id', 'title']]);
	$c->get(CollectionSaver::class)->saveCollection(['id' => 'kinds', 'name' => 'Kinds', 'schema' => 'kinds']);

	$this->kinds = $c->get(FileKindResolver::class);
});

test('says what the files in a folder of a property are', function (string $property, string $dir, ?string $kind): void {
	expect($this->kinds->kind('kinds', $property, $dir))->toBe($kind);
})->with([
	'an image field'                       => ['cover', '', 'image'],
	'a file field'                         => ['pdf', '', 'file'],
	'a gallery'                            => ['photos', '', 'gallery'],
	'a depot'                              => ['downloads', '', 'depot'],
	'a folder in a depot'                  => ['downloads', 'Price Lists/2026', 'depot'],
	'a video poster'                       => ['promo', 'poster', 'image'],
	'an image in a card'                   => ['lead', 'photo', 'image'],
	'a file in a card'                     => ['lead', 'cv', 'file'],
	'a video poster in a card'             => ['lead', 'reel/poster', 'image'],
	'an image in a deck item'              => ['team', 'alice/photo', 'image'],
	'a file in a deck item'                => ['team', 'alice/cv', 'file'],
	'a video poster in a deck item'        => ['team', 'alice/reel/poster', 'image'],
	// Nothing keeps files here, so nothing is kept from here.
	'a folder under an image field'        => ['cover', 'stray', null],
	'the top of a card'                    => ['lead', '', null],
	'a text child of a card'               => ['lead', 'name', null],
	'a child the card does not have'       => ['lead', 'nope', null],
	'a deck item with no child named'      => ['team', 'alice', null],
	'the top of a deck'                    => ['team', '', null],
	'a video folder that is not the poster' => ['promo', 'other', null],
	'a text field'                         => ['title', '', null],
	'a property the schema does not have'  => ['ghost', '', null],
]);

test('a collection that does not exist has no kinds, and no error', function (): void {
	expect($this->kinds->kind('nowhere', 'cover', ''))->toBeNull();
});
