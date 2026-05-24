<?php

declare(strict_types=1);

namespace Tests\Unit\CLI\Command\OAuth;

use DI\Container;
use ReflectionClass;
use ReflectionProperty;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;
use TotalCMS\CLI\Command\OAuth\OAuthSetupCommand;
use TotalCMS\Domain\License\Data\EditionFeature;
use TotalCMS\Domain\License\Service\EditionFeatureService;
use TotalCMS\Support\Config;
use TotalCMS\TotalCMS;

beforeEach(function (): void {
	$this->totalcms = $this->createMock(TotalCMS::class);
});

function makeOAuthSetupTester(TotalCMS $totalcms): CommandTester
{
	$app     = new Application();
	$command = new OAuthSetupCommand($totalcms);
	$app->addCommand($command);

	return new CommandTester($command);
}

function makeConfigWithOAuth(array $oauth): Config
{
	$config = (new ReflectionClass(Config::class))->newInstanceWithoutConstructor();
	(new ReflectionProperty($config, 'oauth'))->setValue($config, $oauth);
	return $config;
}

it('exits with code 1 and prints error when edition does not allow OAuth', function (): void {
	$blocked = $this->createMock(EditionFeatureService::class);
	$blocked->method('can')->willReturn(false);

	$container = $this->createMock(Container::class);
	$container->method('get')
		->with(EditionFeatureService::class)
		->willReturn($blocked);

	$this->totalcms->method('container')->willReturn($container);

	$tester = makeOAuthSetupTester($this->totalcms);
	$tester->execute([]);

	expect($tester->getStatusCode())->toBe(1);
	expect($tester->getDisplay())->toContain('OAuth server requires the');
});

it('exits with code 1 when edition allows but key paths are not configured', function (): void {
	$allowed = $this->createMock(EditionFeatureService::class);
	$allowed->method('can')->willReturn(true);

	$config = makeConfigWithOAuth([]);   // No signingKeyPath or publicKeyPath

	$container = $this->createMock(Container::class);
	$container->method('get')->willReturnCallback(
		fn (string $class): mixed => match ($class) {
			EditionFeatureService::class    => $allowed,
			Config::class                   => $config,
			default                         => null,
		}
	);

	$this->totalcms->method('container')->willReturn($container);

	$tester = makeOAuthSetupTester($this->totalcms);
	$tester->execute([]);

	expect($tester->getStatusCode())->toBe(1);
	expect($tester->getDisplay())->toContain('OAuth key paths not configured');
});
