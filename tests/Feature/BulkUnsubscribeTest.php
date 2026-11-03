<?php

declare(strict_types=1);

use TotalCMS\Domain\Collection\Service\CollectionSaver;
use TotalCMS\Domain\Mailer\Service\BulkUnsubscribe;
use TotalCMS\Domain\Object\Service\ObjectFetcher;
use TotalCMS\Domain\Object\Service\ObjectSaver;
use TotalCMS\Domain\Schema\Service\SchemaSaver;
use TotalCMS\Domain\Security\SignedToken;
use TotalCMS\Support\Config;

use function TotalCMS\Slim\Pest\get;
use function TotalCMS\Slim\Pest\post;

/**
 * One-click unsubscribe for Bulk Send. A recipient collection opts in by
 * having the property named in `mailer.unsubscribeProperty` (default
 * `unsubscribed`). The link opens a confirmation page (mail scanners fetch
 * links, so GET changes nothing); a POST, from the page's button or from
 * the mail client's one-click button, sets the property.
 */
beforeEach(function (): void {
	recursiveDelete(cmsDataDir());
	restoreFixtures();
	$this->setUpApp(bootstrap());

	$c = $this->app->getContainer();
	$c->get(SchemaSaver::class)->saveSchema([
		'id'          => 'subscribers',
		'description' => 'Subscribers',
		'properties'  => [
			'id'           => ['type' => 'string', 'field' => 'id', 'help' => 'Id'],
			'email'        => ['type' => 'string', 'field' => 'email', 'help' => 'Email'],
			'unsubscribed' => ['type' => 'boolean', 'field' => 'toggle', 'help' => 'Opted out of bulk mail'],
		],
		'required' => ['id'],
		'index'    => ['id', 'email', 'unsubscribed'],
	]);
	$c->get(CollectionSaver::class)->saveCollection(['id' => 'subscribers', 'name' => 'Subscribers', 'schema' => 'subscribers']);
	$c->get(CollectionSaver::class)->saveCollection(['id' => 'posts', 'name' => 'Posts', 'schema' => 'blog']);
	$c->get(ObjectSaver::class)->saveObject('subscribers', ['id' => 'ann', 'email' => 'ann@example.com', 'unsubscribed' => false]);

	$this->unsub   = $c->get(BulkUnsubscribe::class);
	$this->fetcher = $c->get(ObjectFetcher::class);
});

function tokenOf(string $url): string
{
	return substr($url, strrpos($url, '/') + 1);
}

function isUnsubscribedNow(object $test): bool
{
	return (bool)($test->fetcher->fetchObject('subscribers', 'ann')->toArray()['unsubscribed'] ?? false);
}

it('offers headers only for a collection with the property', function (): void {
	$headers = $this->unsub->headers('subscribers', 'ann');

	expect($headers['List-Unsubscribe-Post'])->toBe('List-Unsubscribe=One-Click')
		->and($headers['List-Unsubscribe'])->toStartWith('<')->toContain(BulkUnsubscribe::PATH)->toEndWith('>')
		->and($this->unsub->headers('posts', 'p1'))->toBe([]);
});

it('honors a renamed property, and an empty setting turns it off', function (): void {
	$config = $this->app->getContainer()->get(Config::class);

	$config->mailer = ['unsubscribeProperty' => 'email'];
	expect($this->unsub->property('subscribers'))->toBe('email');

	$config->mailer = ['unsubscribeProperty' => ''];
	expect($this->unsub->property('subscribers'))->toBeNull()
		->and($this->unsub->headers('subscribers', 'ann'))->toBe([]);
});

it('shows a confirmation on GET and changes nothing', function (): void {
	$response = get(BulkUnsubscribe::PATH . tokenOf($this->unsub->url('subscribers', 'ann')));

	expect($response->getStatusCode())->toBe(200)
		->and((string)$response->getBody())->toContain('<form')->toContain('method="post"')
		->and(isUnsubscribedNow($this))->toBeFalse();
});

it('unsubscribes on a one-click POST', function (): void {
	$response = post(BulkUnsubscribe::PATH . tokenOf($this->unsub->url('subscribers', 'ann')), ['List-Unsubscribe' => 'One-Click']);

	expect($response->getStatusCode())->toBe(200)
		->and(isUnsubscribedNow($this))->toBeTrue()
		->and($this->unsub->isUnsubscribed('subscribers', ['unsubscribed' => true]))->toBeTrue();
});

it('refuses a forged or foreign-purpose token', function (): void {
	$foreign = $this->app->getContainer()->get(SignedToken::class)->sign('download', ['c' => 'subscribers', 'o' => 'ann']);

	foreach (['nonsense', $foreign] as $token) {
		expect(get(BulkUnsubscribe::PATH . $token)->getStatusCode())->toBe(400)
			->and(post(BulkUnsubscribe::PATH . $token)->getStatusCode())->toBe(400);
	}
	expect(isUnsubscribedNow($this))->toBeFalse();
});

it('answers a deleted recipient as done without saying it is gone', function (): void {
	expect(post(BulkUnsubscribe::PATH . tokenOf($this->unsub->url('subscribers', 'nobody')))->getStatusCode())->toBe(200);
});

it('a test send link changes nothing', function (): void {
	$response = post(BulkUnsubscribe::PATH . BulkUnsubscribe::PREVIEW);

	expect($response->getStatusCode())->toBe(200)
		->and(isUnsubscribedNow($this))->toBeFalse();
});

it('works for a signed-out visitor with authentication on', function (): void {
	$config         = $this->app->getContainer()->get(Config::class);
	$auth           = $config->auth;
	$auth['enable'] = true;
	$config->auth   = $auth;

	$token = tokenOf($this->unsub->url('subscribers', 'ann'));

	expect(get(BulkUnsubscribe::PATH . $token)->getStatusCode())->toBe(200)
		->and(post(BulkUnsubscribe::PATH . $token, ['List-Unsubscribe' => 'One-Click'])->getStatusCode())->toBe(200)
		->and(isUnsubscribedNow($this))->toBeTrue();
});
