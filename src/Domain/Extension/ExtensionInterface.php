<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Extension;

/**
 * The public contract for Total CMS extensions.
 *
 * Every extension must implement this interface. The lifecycle is:
 *   1. register() — declare what your extension provides (services, Twig functions, etc.)
 *   2. boot()     — wire into the running application (routes, events, etc.)
 *
 * register() is called during container build. Do NOT resolve other services here.
 * boot() is called after ALL extensions have registered and the app is ready.
 */
interface ExtensionInterface
{
	/**
	 * Register services and declare capabilities.
	 *
	 * Use $context->add*() methods to declare what this extension provides.
	 * The container is not yet fully built — do not call $context->get() here.
	 */
	public function register(ExtensionContext $context): void;

	/**
	 * Boot the extension into the running application.
	 *
	 * All extensions have registered at this point. The container is fully built.
	 * Use $context->get() to resolve services, subscribe to events, etc.
	 */
	public function boot(ExtensionContext $context): void;
}
