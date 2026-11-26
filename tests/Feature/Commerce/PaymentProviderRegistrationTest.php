<?php

declare(strict_types=1);

use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;
use Tests\Fakes\FakeDownloadSource;
use Tests\Fakes\FakePaymentProvider;
use Tests\Fakes\OpenCommerceGate;
use TotalCMS\Domain\Admin\Form\FormOptionSources;
use TotalCMS\Domain\Commerce\Contract\CheckoutValidator;
use TotalCMS\Domain\Commerce\Order\OrderDraft;
use TotalCMS\Domain\Commerce\Payment\PaymentProviderRegistry;
use TotalCMS\Domain\Commerce\Pricing\PricingContext;
use TotalCMS\Domain\Commerce\Service\CommerceSettings;
use TotalCMS\Domain\Extension\Data\ExtensionManifest;
use TotalCMS\Domain\Extension\ExtensionContext;
use TotalCMS\Domain\Extension\Service\ExtensionSettingsManager;
use TotalCMS\Domain\Storage\StorageFilesystemAdapter;
use TotalCMS\Support\Config;

/*
 * An extension adds a way to pay with addPaymentProvider(). That is a
 * capability an admin can switch off, and what is registered is what the
 * store's owner picks from in Settings → Commerce.
 */

function paymentProviderContext(): ExtensionContext
{
	$storage = test()->createMock(StorageFilesystemAdapter::class);
	$storage->method('fileExists')->willReturn(false);

	return new ExtensionContext(
		ExtensionManifest::fromArray(['id' => 'test-vendor/pay', 'name' => 'Pay', 'version' => '1.0.0']),
		'/path/to/extension',
		test()->createMock(ContainerInterface::class),
		new ExtensionSettingsManager($storage),
		new NullLogger(),
	);
}

test('an extension with no payment provider has no payments capability', function (): void {
	expect(paymentProviderContext()->getCapabilities())->not->toHaveKey('commerce:payments')
		->and(paymentProviderContext()->getRegisteredPaymentProviders())->toBe([]);
});

test('addPaymentProvider() gives it the commerce:payments capability', function (): void {
	$context  = paymentProviderContext();
	$provider = new FakePaymentProvider();
	$context->addPaymentProvider($provider);

	expect($context->getCapabilities())->toHaveKey('commerce:payments')
		->and($context->getRegisteredPaymentProviders())->toBe([$provider])
		->and(ExtensionContext::capabilityLabels()['commerce:payments'])->toBe('Payment Provider');
});

test('addDownloadSource() gives it the commerce:downloads capability', function (): void {
	$context = paymentProviderContext();
	$source  = new FakeDownloadSource();
	$context->addDownloadSource($source);

	expect($context->getCapabilities())->toBe(['commerce:downloads' => true])
		->and($context->getRegisteredDownloadSources())->toBe([$source])
		->and(ExtensionContext::capabilityLabels()['commerce:downloads'])->toBe('Download Source');
});

test('addCheckoutValidator() gives it the commerce:checkout capability', function (): void {
	$context   = paymentProviderContext();
	$validator = new class implements CheckoutValidator {
		public function validate(OrderDraft $draft, PricingContext $context): ?string
		{
			return null;
		}
	};
	$context->addCheckoutValidator($validator);

	expect($context->getCapabilities())->toBe(['commerce:checkout' => true])
		->and($context->getRegisteredCheckoutValidators())->toBe([$validator])
		->and(ExtensionContext::capabilityLabels()['commerce:checkout'])->toBe('Checkout Validator');
});

test('Settings → Commerce offers every installed provider, by its name', function (): void {
	$config           = (new ReflectionClass(Config::class))->newInstanceWithoutConstructor();
	$config->commerce = [];
	$registry         = new PaymentProviderRegistry(new CommerceSettings($config), new OpenCommerceGate());
	$registry->register(new FakePaymentProvider());
	$unready             = new FakePaymentProvider(id: 'not-set-up');
	$unready->configured = false;
	$registry->register($unready);

	// One without its keys is offered too: switching it on is how the owner says they mean to set it up.
	expect((new FormOptionSources(formServices(['paymentProviders' => $registry])))->paymentProviderOptions())->toBe([
		['value' => 'fake', 'label' => 'Fake Pay'],
		['value' => 'not-set-up', 'label' => 'Fake Pay'],
	])->and((new FormOptionSources(formServices()))->paymentProviderOptions())->toBe([]);
});

test('the providers setting is a checklist that takes its choices from the registry', function (): void {
	$schema = json_decode((string)file_get_contents(dirname(__DIR__, 3) . '/resources/schemas/settings/commerce.json'), true);

	// A box to tick per provider: with two or three to choose from, each one
	// and whether it is on can be seen without opening a list.
	expect($schema['properties']['providers']['field'] ?? null)->toBe('checklist')
		->and($schema['properties']['providers']['settings']['propertyOptions'] ?? null)->toBe('paymentProviders');
});
