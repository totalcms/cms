<?php

declare(strict_types=1);

namespace Tests\Unit\CLI\Command\OAuth;

use DI\Container;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;
use TotalCMS\CLI\Command\OAuth\OAuthGcCommand;
use TotalCMS\Domain\License\Service\EditionFeatureService;
use TotalCMS\Domain\OAuth\Data\OAuthGrantData;
use TotalCMS\Domain\OAuth\Repository\OAuthClientRepository;
use TotalCMS\Domain\OAuth\Repository\OAuthGrantRepository;
use TotalCMS\Domain\OAuth\Service\OAuthClientPruner;
use TotalCMS\TotalCMS;

beforeEach(function (): void {
	$this->totalcms        = $this->createMock(TotalCMS::class);
	$prefix                = sys_get_temp_dir() . '/oauth-gc-test-' . uniqid();
	$this->grantsTmpFile   = $prefix . '-grants.json';
	$this->clientsTmpFile  = $prefix . '-clients.json';
	$this->gcMarkerFile    = $prefix . '-marker';
	$this->grantRepository = new OAuthGrantRepository($this->grantsTmpFile);
	$this->clientPruner    = new OAuthClientPruner(
		new OAuthClientRepository($this->clientsTmpFile),
		$this->grantRepository,
		$this->gcMarkerFile,
	);
});

afterEach(function (): void {
	foreach ([$this->grantsTmpFile, $this->clientsTmpFile, $this->gcMarkerFile] as $file) {
		if (is_file($file)) {
			unlink($file);
		}
	}
});

function makeOAuthGcTester(TotalCMS $totalcms): CommandTester
{
	$app     = new Application();
	$command = new OAuthGcCommand($totalcms);
	$app->addCommand($command);

	return new CommandTester($command);
}

it('exits with code 1 and prints error when edition does not allow OAuth', function (): void {
	$blocked = $this->createMock(EditionFeatureService::class);
	$blocked->method('can')->willReturn(false);

	$container = $this->createMock(Container::class);
	$container->method('get')
		->with(EditionFeatureService::class)
		->willReturn($blocked);

	$this->totalcms->method('container')->willReturn($container);

	$tester = makeOAuthGcTester($this->totalcms);
	$tester->execute([]);

	expect($tester->getStatusCode())->toBe(1);
	expect($tester->getDisplay())->toContain('OAuth server requires the');
});

it('reports zero when no grants are expired', function (): void {
	$allowed = $this->createMock(EditionFeatureService::class);
	$allowed->method('can')->willReturn(true);

	// Seed a live (non-expired) grant
	$future = gmdate('c', strtotime('+1 day'));
	$this->grantRepository->save(new OAuthGrantData(
		id: 'g-live',
		clientId: 'c-1',
		userId: 'admin',
		scopes: ['cms:read'],
		refreshTokenHash: 'hash-live',
		issuedAt: gmdate('c'),
		expiresAt: $future,
	));

	$container = $this->createMock(Container::class);
	$container->method('get')->willReturnCallback(
		fn (string $class): mixed => match ($class) {
			EditionFeatureService::class => $allowed,
			OAuthGrantRepository::class  => $this->grantRepository,
			OAuthClientPruner::class     => $this->clientPruner,
			default                      => null,
		}
	);
	$this->totalcms->method('container')->willReturn($container);

	$tester = makeOAuthGcTester($this->totalcms);
	$tester->execute([]);

	expect($tester->getStatusCode())->toBe(0);
	expect($tester->getDisplay())->toContain('Pruned 0 expired OAuth grants');
	expect($tester->getDisplay())->toContain('Pruned 0 stale dynamic clients');
});

it('prunes expired grants and reports the count', function (): void {
	$allowed = $this->createMock(EditionFeatureService::class);
	$allowed->method('can')->willReturn(true);

	$past   = gmdate('c', strtotime('-2 days'));
	$future = gmdate('c', strtotime('+1 day'));
	$now    = gmdate('c');

	// Two expired grants
	$this->grantRepository->save(new OAuthGrantData(
		id: 'g-expired-1',
		clientId: 'c-1',
		userId: 'admin',
		scopes: ['cms:read'],
		refreshTokenHash: 'h1',
		issuedAt: $past,
		expiresAt: $past,
	));
	$this->grantRepository->save(new OAuthGrantData(
		id: 'g-expired-2',
		clientId: 'c-2',
		userId: 'admin',
		scopes: ['cms:read'],
		refreshTokenHash: 'h2',
		issuedAt: $past,
		expiresAt: $past,
	));
	// One live grant
	$this->grantRepository->save(new OAuthGrantData(
		id: 'g-live',
		clientId: 'c-3',
		userId: 'admin',
		scopes: ['cms:read'],
		refreshTokenHash: 'h3',
		issuedAt: $now,
		expiresAt: $future,
	));

	$container = $this->createMock(Container::class);
	$container->method('get')->willReturnCallback(
		fn (string $class): mixed => match ($class) {
			EditionFeatureService::class => $allowed,
			OAuthGrantRepository::class  => $this->grantRepository,
			OAuthClientPruner::class     => $this->clientPruner,
			default                      => null,
		}
	);
	$this->totalcms->method('container')->willReturn($container);

	$tester = makeOAuthGcTester($this->totalcms);
	$tester->execute([]);

	expect($tester->getStatusCode())->toBe(0);
	expect($tester->getDisplay())->toContain('Pruned 2 expired OAuth grants');

	// Confirm the live grant survived
	expect($this->grantRepository->find('g-live'))->not->toBeNull();
	expect($this->grantRepository->find('g-expired-1'))->toBeNull();
	expect($this->grantRepository->find('g-expired-2'))->toBeNull();
});
