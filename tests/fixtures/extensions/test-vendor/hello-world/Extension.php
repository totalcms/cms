<?php

declare(strict_types=1);

namespace TestVendor\HelloWorld;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use TotalCMS\Domain\Extension\Data\AdminNavItem;
use TotalCMS\Domain\Extension\Data\DashboardWidget;
use TotalCMS\Domain\Extension\ExtensionContext;
use TotalCMS\Domain\Extension\ExtensionInterface;
use Twig\TwigFilter;
use Twig\TwigFunction;

class Extension implements ExtensionInterface
{
	public bool $registered = false;
	public bool $booted = false;

	/** @var list<array<string,mixed>> */
	public array $receivedEvents = [];

	public function register(ExtensionContext $context): void
	{
		$this->registered = true;

		$context->addTwigFunction(new TwigFunction('hello_world', fn (): string => 'Hello from extension!'));
		$context->addTwigFilter(new TwigFilter('shout', fn (string $value): string => strtoupper($value) . '!'));
		$context->addTwigGlobal('helloMessage', 'Hello from global');

		$context->addAdminNavItem(new AdminNavItem(
			label: 'Hello World',
			icon: 'hello',
			url: '/ext/test-vendor/hello-world',
		));

		$context->addDashboardWidget(new DashboardWidget(
			id: 'hello-widget',
			label: 'Hello Widget',
			template: 'hello-widget.twig',
		));

		$context->addFieldType('hellofield', self::class);

		$context->addEventListener('object.created', function (array $payload): void {
			$this->receivedEvents[] = ['event' => 'object.created', 'payload' => $payload];
		});

		$helloCmd = new class () extends Command {
			protected function configure(): void
			{
				$this->setName('test-vendor:hello');
				$this->setDescription('Hello from extension CLI');
			}

			protected function execute(InputInterface $input, OutputInterface $output): int
			{
				$output->writeln('Hello from extension CLI!');

				return Command::SUCCESS;
			}
		};
		$context->addCommand($helloCmd);
	}

	public function boot(ExtensionContext $context): void
	{
		$this->booted = true;
	}
}
