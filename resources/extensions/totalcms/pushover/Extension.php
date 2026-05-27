<?php

declare(strict_types=1);

namespace TotalCMS\Bundled\Pushover;

use TotalCMS\Domain\Auth\Service\AccessManager;
use TotalCMS\Domain\Cache\CacheManager;
use TotalCMS\Domain\Extension\Data\FormAction;
use TotalCMS\Domain\Extension\ExtensionContext;
use TotalCMS\Domain\Extension\ExtensionInterface;
use TotalCMS\Domain\ImageWorks\Service\ImageGenerator;
use TotalCMS\Domain\Twig\Service\TwigEngine;
use TotalCMS\Factory\LoggerFactory;
use TotalCMS\Renderer\JsonRenderer;
use TotalCMS\Renderer\TwigRenderer;

require_once __DIR__ . '/PushoverService.php';
require_once __DIR__ . '/SendPushoverAction.php';
require_once __DIR__ . '/PushoverTestAction.php';

class Extension implements ExtensionInterface
{
	public function register(ExtensionContext $context): void
	{
		$appToken = (string)$context->setting('appToken', '');
		$userKey  = (string)$context->setting('userKey', '');
		$groupKey = (string)$context->setting('groupKey', '');

		$context->addContainerDefinition(
			PushoverService::class,
			fn ($c) => new PushoverService(
				$c->get(TwigEngine::class),
				$appToken,
				$userKey,
				$groupKey,
				$c->get(ImageGenerator::class),
				$c->get(LoggerFactory::class),
			),
		);

		$rateLimitPerMinute = (int)$context->setting('rateLimitPerMinute', 10);

		$context->addContainerDefinition(
			SendPushoverAction::class,
			fn ($c) => new SendPushoverAction(
				$c->get(PushoverService::class),
				$c->get(JsonRenderer::class),
				$c->get(AccessManager::class),
				$c->get(CacheManager::class),
				$c->get(LoggerFactory::class),
				$rateLimitPerMinute,
			),
		);

		$context->addContainerDefinition(
			PushoverTestAction::class,
			fn ($c) => new PushoverTestAction(
				$c->get(PushoverService::class),
				$c->get(TwigRenderer::class),
			),
		);

		$context->addFormAction('pushover', new FormAction(
			name: 'pushover',
			route: '/ext/totalcms/pushover/send',
			label: 'Pushover Notification',
		));

		$context->addRoutes(function ($group): void {
			$group->post('/send', SendPushoverAction::class);
		});

		$context->addAdminRoutes(function ($group): void {
			$group->get('/test', PushoverTestAction::class);
			$group->post('/test', PushoverTestAction::class);
		});
	}

	public function boot(ExtensionContext $context): void
	{
	}
}
