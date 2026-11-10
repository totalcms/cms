<?php

declare(strict_types=1);

use Nyholm\Psr7\Factory\Psr17Factory;
use TotalCMS\Domain\Builder\Exception\PageResponseException;
use TotalCMS\Domain\Twig\Service\TwigEngine;
use TotalCMS\Support\ContainerFactory;
use Twig\TwigFunction;

/**
 * A template that answers with its own response (`protect_page()`) must escape
 * both render paths, unwrapped from Twig's RuntimeError: render() for Site
 * Builder, renderString() for a Stacks page.
 */
function responseTwig(): TwigEngine
{
	static $twig = null;
	if ($twig instanceof TwigEngine) {
		return $twig;
	}

	$dir = sys_get_temp_dir() . '/tcms-response-' . uniqid();
	mkdir($dir);
	file_put_contents($dir . '/page.twig', 'BEFORE {{ answer_with_prompt() }} AFTER');
	file_put_contents($dir . '/outer.twig', '{% include "@response/page.twig" %}');

	$twig = ContainerFactory::build()->get(TwigEngine::class);
	$twig->addExtensionTemplatePath($dir, 'response');
	$twig->registerExtensionItems([new TwigFunction('answer_with_prompt', static function (): string {
		throw new PageResponseException((new Psr17Factory())->createResponse(403));
	})], [], []);

	return $twig;
}

test('a response escapes render()', function (string $template): void {
	expect(fn () => responseTwig()->render($template))->toThrow(PageResponseException::class);
})->with(['@response/page.twig', '@response/outer.twig']);

test('a response is findable through renderString()', function (): void {
	try {
		responseTwig()->renderString('BEFORE {{ answer_with_prompt() }} AFTER');
		$this->fail('renderString() returned a page');
	} catch (\Throwable $thrown) {
		expect(PageResponseException::find($thrown))->toBeInstanceOf(PageResponseException::class);
	}
});
