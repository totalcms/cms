<?php

declare(strict_types=1);

use Odan\Session\PhpSession;
use Slim\Interfaces\RouteParserInterface;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Routing\RouteContext;
use Slim\Routing\RoutingResults;
use TotalCMS\Action\Auth\ResetPasswordAction;
use TotalCMS\Action\Auth\ResetPasswordSubmitAction;
use TotalCMS\Domain\Auth\Service\PasswordResetService;
use TotalCMS\Domain\Translation\TranslationService;
use TotalCMS\Renderer\TwigRenderer;
use TotalCMS\Support\Config;
use TotalCMS\Support\OperationResult;

// The reset form's `?redirect=` is attacker-craftable: a reset link pointing
// at evil.example must not forward the user there after the reset.

beforeEach(function (): void {
	$this->resets = $this->createMock(PasswordResetService::class);
	$this->resets->method('validateToken')->willReturn(OperationResult::success('ok', ['email' => 'a@b.test', 'collection' => 'auth']));
	$this->resets->method('resetPassword')->willReturn(OperationResult::success('ok'));

	$parser = $this->createMock(RouteParserInterface::class);
	$parser->method('urlFor')->willReturnCallback(static fn (string $name): string => '/admin/' . $name);
	$this->parser  = $parser;
	$this->routing = $this->createMock(RoutingResults::class);
});

describe('ResetPasswordAction', function (): void {
	test('passes a same-origin redirect to the form and drops an off-site one', function (string $query, string $expected): void {
		$twig = $this->createMock(TwigRenderer::class);
		$twig->expects($this->once())->method('template')
			->with($this->anything(), 'admin/reset-password.twig', $this->callback(static fn (array $data): bool => $data['redirect'] === $expected))
			->willReturnArgument(0);

		$request = (new ServerRequestFactory())->createServerRequest('GET', 'https://example.test/admin/reset-password/tok?redirect=' . rawurlencode($query));

		(new ResetPasswordAction($this->resets, $twig, new PhpSession()))($request, (new ResponseFactory())->createResponse(), ['token' => 'tok']);
	})->with([
		'same-origin kept'  => ['/members', '/members'],
		'off-site dropped'  => ['https://evil.example/phish', ''],
	]);
});

describe('ResetPasswordSubmitAction', function (): void {
	test('follows a same-origin redirect and drops an off-site one', function (string $redirect, string $location): void {
		$config       = (new ReflectionClass(Config::class))->newInstanceWithoutConstructor();
		$config->auth = ['collection' => 'auth'];

		$translator = $this->createMock(TranslationService::class);
		$translator->method('trans')->willReturnArgument(0);

		$request = (new ServerRequestFactory())->createServerRequest('POST', 'https://example.test/admin/reset-password/tok')
			->withParsedBody(['password' => 'newpassword', 'password_confirm' => 'newpassword', 'redirect' => $redirect])
			->withAttribute(RouteContext::ROUTE_PARSER, $this->parser)
			->withAttribute(RouteContext::ROUTING_RESULTS, $this->routing);

		$response = (new ResetPasswordSubmitAction($this->resets, $config, new PhpSession(), $translator))(
			$request,
			(new ResponseFactory())->createResponse(),
			['token' => 'tok'],
		);

		expect($response->getHeaderLine('Location'))->toBe($location);
	})->with([
		'same-origin kept'                 => ['https://example.test/members', 'https://example.test/members'],
		'off-site falls back to the login' => ['https://evil.example/phish', '/admin/login'],
	]);
});
