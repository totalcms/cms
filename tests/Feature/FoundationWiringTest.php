<?php

declare(strict_types=1);

use TotalCMS\Domain\Storage\Sequence;
use TotalCMS\Domain\Webhook\WebhookEventStore;

/*
 * The sequence counter and the webhook event store are built by the container
 * with nothing said about them. This proves that works, and that both write
 * under the site's data folder.
 */

beforeEach(function (): void {
	recursiveDelete(cmsDataDir());
	restoreFixtures();
	$this->setUpApp(bootstrap());
	$this->c = $this->app->getContainer();
});

test('the container builds a Sequence that counts in the data folder', function (): void {
	expect($this->c->get(Sequence::class)->next('wiring-test', 500))->toBe(500)
		->and($this->c->get(Sequence::class)->next('wiring-test', 500))->toBe(501)
		->and(json_decode((string)file_get_contents(cmsDataDir() . '.system/sequences.json'), true))->toBe(['wiring-test' => 501]);
});

test('the container builds a WebhookEventStore that remembers in the data folder', function (): void {
	$store = $this->c->get(WebhookEventStore::class);

	expect($store->firstSeen('wiring', 'evt_1'))->toBeTrue()
		->and($store->firstSeen('wiring', 'evt_1'))->toBeFalse()
		->and(file_exists(cmsDataDir() . '.system/webhook-events.json'))->toBeTrue();
});
