<?php

declare(strict_types=1);

use TotalCMS\Domain\Auth\Data\Actor;
use TotalCMS\Domain\Auth\Service\ActorContext;
use TotalCMS\Domain\Collection\Service\CollectionSaver;
use TotalCMS\Domain\Object\Service\ObjectFetcher;
use TotalCMS\Domain\Object\Service\ObjectPropertyIncrementer;
use TotalCMS\Domain\Object\Service\ObjectSaver;
use TotalCMS\Domain\Schema\Service\SchemaSaver;

use function TotalCMS\Slim\Pest\post;

/**
 * The counter routes write as the system (a tick is not a content edit), so
 * they would step around a server-only field's guard. They refuse one; the
 * site changes it from its own code, as the system.
 */
beforeEach(function (): void {
	recursiveDelete(cmsDataDir());
	restoreFixtures();
	$this->setUpApp(bootstrap());

	$c = $this->app->getContainer();
	$c->get(SchemaSaver::class)->saveSchema([
		'id'          => 'tallies',
		'description' => 'Tallies',
		'properties'  => [
			'id'    => ['type' => 'string', 'field' => 'id', 'help' => 'Id'],
			'likes' => ['type' => 'integer', 'field' => 'number', 'help' => 'Anyone may like'],
			'count' => ['type' => 'integer', 'field' => 'number', 'help' => 'Set by the site', 'serverOnly' => true],
		],
		'required' => ['id'],
		'index'    => ['id'],
	]);
	$c->get(CollectionSaver::class)->saveCollection(['id' => 'tallies', 'name' => 'Tallies', 'schema' => 'tallies']);
	$c->get(ActorContext::class)->runAs(Actor::system(), fn () => $c->get(ObjectSaver::class)->saveObject('tallies', ['id' => 't1', 'likes' => 0, 'count' => 5]));
});

it('refuses to increment a server-only field over the API', function (): void {
	$response = post('/api/collections/tallies/t1/count/increment');

	expect($response->getStatusCode())->toBe(403)
		->and($this->app->getContainer()->get(ObjectFetcher::class)->fetchObject('tallies', 't1')->toArray()['count'])->toBe(5);
});

it('still increments an ordinary counter', function (): void {
	post('/api/collections/tallies/t1/likes/increment')->assertOk();
});

it('lets the system adjust a server-only counter', function (): void {
	$c      = $this->app->getContainer();
	$result = $c->get(ActorContext::class)->runAs(Actor::system(), fn (): array => $c->get(ObjectPropertyIncrementer::class)->incrementProperty('tallies', 't1', 'count', 2));

	expect($result['value'])->toBe(7);
});
