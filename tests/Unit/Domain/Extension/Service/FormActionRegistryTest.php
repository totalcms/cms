<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Extension\Service;

use PHPUnit\Framework\TestCase;
use TotalCMS\Domain\Extension\Data\FormAction;
use TotalCMS\Domain\Extension\Service\FormActionRegistry;

final class FormActionRegistryTest extends TestCase
{
	public function testRegisterAndGet(): void
	{
		$registry = new FormActionRegistry();
		$action   = new FormAction('pushover', '/api/ext/totalcms/pushover/send', 'Pushover Notification');

		$registry->register($action);

		$this->assertSame($action, $registry->get('pushover'));
	}

	public function testGetReturnsNullForUnknown(): void
	{
		$registry = new FormActionRegistry();

		$this->assertNull($registry->get('nonexistent'));
	}

	public function testAllReturnsAllRegistered(): void
	{
		$registry = new FormActionRegistry();
		$registry->register(new FormAction('pushover', '/api/ext/totalcms/pushover/send', 'Pushover'));
		$registry->register(new FormAction('slack', '/api/ext/acme/slack/send', 'Slack'));

		$all = $registry->all();

		$this->assertCount(2, $all);
		$this->assertArrayHasKey('pushover', $all);
		$this->assertArrayHasKey('slack', $all);
	}

	public function testToJsonMapReturnsNameToRouteMapping(): void
	{
		$registry = new FormActionRegistry();
		$registry->register(new FormAction('pushover', '/api/ext/totalcms/pushover/send', 'Pushover'));
		$registry->register(new FormAction('slack', '/api/ext/acme/slack/send', 'Slack'));

		$json = $registry->toJsonMap();

		$this->assertSame(
			'{"pushover":"/api/ext/totalcms/pushover/send","slack":"/api/ext/acme/slack/send"}',
			$json,
		);
	}

	public function testToJsonMapReturnsEmptyObjectWhenEmpty(): void
	{
		$registry = new FormActionRegistry();

		$this->assertSame('{}', $registry->toJsonMap());
	}

	public function testLaterRegistrationOverridesEarlier(): void
	{
		$registry = new FormActionRegistry();
		$registry->register(new FormAction('pushover', '/old/route', 'Old'));
		$registry->register(new FormAction('pushover', '/new/route', 'New'));

		$this->assertSame('/new/route', $registry->get('pushover')?->route);
	}
}
