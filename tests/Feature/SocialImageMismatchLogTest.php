<?php

declare(strict_types=1);

use Monolog\Handler\TestHandler;
use Monolog\Logger;
use TotalCMS\Domain\Cache\Service\CacheBackends;
use TotalCMS\Domain\Seo\Service\SocialImageMismatchLog;
use TotalCMS\Factory\LoggerFactory;

// A share URL whose `v` no longer matches its record leaves one line in the
// log a day, however often crawlers fetch it (ImageWorksSocialFetchAction).

beforeEach(function (): void {
	recursiveDelete(cmsDataDir());
	restoreFixtures();
	$this->setUpApp(bootstrap());

	$this->handler = new TestHandler();
	$factory       = $this->createMock(LoggerFactory::class);
	$factory->method('channelLogger')->willReturn(new Logger('test', [$this->handler]));

	$this->log = new SocialImageMismatchLog($factory, $this->app->getContainer()->get(CacheBackends::class));
});

it('logs a mismatched share URL once a day', function (): void {
	$this->log->note('blog', 'hello', '0000000000000000');
	$this->log->note('blog', 'hello', '0000000000000000');

	expect($this->handler->getRecords())->toHaveCount(1)
		->and($this->handler->hasInfoThatContains('Share image hash mismatch: blog/hello v=0000000000000000'))->toBeTrue();
});

it('logs each stale hash on its own', function (): void {
	$this->log->note('blog', 'hello', '0000000000000000');
	$this->log->note('blog', 'hello', '1111111111111111');

	expect($this->handler->getRecords())->toHaveCount(2);
});
