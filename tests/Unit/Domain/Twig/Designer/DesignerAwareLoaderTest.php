<?php

declare(strict_types=1);

use TotalCMS\Domain\Twig\Designer\DesignerAwareLoader;
use TotalCMS\Domain\Twig\Designer\TemplateDesignerPreprocessor;
use TotalCMS\Domain\Twig\Designer\TemplateDesignerRegistry;
use Twig\Loader\ArrayLoader;

/**
 * A designer block syncs whenever its source passes through the preprocessor.
 * In development that is meant to happen on every render. With the compiled
 * template cached it must happen only when the template changes, or a warm
 * page would save itself and PUT to production on every request.
 */
function designerLoader(TemplateDesignerRegistry $registry, bool $syncOnEveryRender): DesignerAwareLoader
{
	$source = '{% templatedesigner for "templates/card" on "https://example.com" token "t" %}<p>card</p>{% endtemplatedesigner %}';

	return new DesignerAwareLoader(
		new ArrayLoader(['page.twig' => $source]),
		new TemplateDesignerPreprocessor($registry),
		$syncOnEveryRender,
	);
}

test('a fresh template registers its designer blocks in development', function (): void {
	$registry = new TemplateDesignerRegistry();

	expect(designerLoader($registry, true)->isFresh('page.twig', time()))->toBeTrue()
		->and($registry->get('_designer_page_twig_0'))->not->toBeNull();
});

test('a fresh template leaves the registry alone with the compiled cache on', function (): void {
	$registry = new TemplateDesignerRegistry();

	expect(designerLoader($registry, false)->isFresh('page.twig', time()))->toBeTrue()
		->and($registry->get('_designer_page_twig_0'))->toBeNull();
});
