<?php

declare(strict_types=1);

use Odan\Session\PhpSession;
use Psr\Http\Message\ResponseInterface;
use Slim\Interfaces\RouteParserInterface;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Routing\RouteContext;
use Slim\Routing\RoutingResults;
use TotalCMS\Action\Auth\ResendVerificationAction;
use TotalCMS\Action\Auth\ResendVerificationSubmitAction;
use TotalCMS\Domain\Auth\Service\AuthMailer;
use TotalCMS\Domain\Auth\Service\EmailVerificationService;
use TotalCMS\Domain\Translation\TranslationService;
use TotalCMS\Renderer\TwigRenderer;
use TotalCMS\Support\Config;
use TotalCMS\Support\OperationResult;

// An expired link's redirect survives the resend: the form carries it as a
// hidden field and the submit stores it on the fresh token.

beforeEach(function (): void {
	$this->config       = (new ReflectionClass(Config::class))->newInstanceWithoutConstructor();
	$this->config->auth = ['collection' => 'auth'];

	$parser = $this->createMock(RouteParserInterface::class);
	$parser->method('urlFor')->willReturnCallback(
		static fn (string $name, array $data = []): string => '/admin/' . $name . (isset($data['collection']) ? '/' . $data['collection'] : ''),
	);
	$this->parser  = $parser;
	$this->routing = $this->createMock(RoutingResults::class);
});

describe('ResendVerificationAction', function (): void {
	test('hands a same-origin redirect query to the form', function (): void {
		$twig = $this->createMock(TwigRenderer::class);
		$twig->expects($this->once())->method('template')
			->with($this->anything(), 'admin/resend-verification.twig', ['collection' => 'members', 'redirect' => '/welcome'])
			->willReturnArgument(0);

		$request = (new ServerRequestFactory())->createServerRequest('GET', 'https://example.test/admin/resend-verification/members?redirect=%2Fwelcome');

		(new ResendVerificationAction($twig, $this->config))($request, (new ResponseFactory())->createResponse(), ['collection' => 'members']);
	});

	test('drops an off-site redirect query', function (): void {
		$twig = $this->createMock(TwigRenderer::class);
		$twig->expects($this->once())->method('template')
			->with($this->anything(), 'admin/resend-verification.twig', ['collection' => 'auth', 'redirect' => ''])
			->willReturnArgument(0);

		$request = (new ServerRequestFactory())->createServerRequest('GET', 'https://example.test/admin/resend-verification?redirect=https%3A%2F%2Fevil.example');

		(new ResendVerificationAction($twig, $this->config))($request, (new ResponseFactory())->createResponse(), []);
	});
});

describe('ResendVerificationSubmitAction', function (): void {
	/** @param array<string,mixed> $body */
	$submit = function (array $body, array $args, EmailVerificationService $verification): ResponseInterface {
		$translator = $this->createMock(TranslationService::class);
		$translator->method('trans')->willReturnArgument(0);

		$action = new ResendVerificationSubmitAction($verification, $this->createMock(AuthMailer::class), $this->config, new PhpSession(), $translator);

		$request = (new ServerRequestFactory())->createServerRequest('POST', 'https://example.test/admin/resend-verification')
			->withParsedBody($body)
			->withAttribute(RouteContext::ROUTE_PARSER, $this->parser)
			->withAttribute(RouteContext::ROUTING_RESULTS, $this->routing);

		return $action($request, (new ResponseFactory())->createResponse(), $args);
	};

	test('stores the redirect on the new token and keeps it on the way back', function () use ($submit): void {
		$verification = $this->createMock(EmailVerificationService::class);
		$verification->expects($this->once())->method('resendVerificationToken')
			->with('a@b.test', 'members', '/welcome')
			->willReturn(OperationResult::success('sent'));

		$response = $submit->call($this, ['email' => 'a@b.test', 'redirect' => '/welcome'], ['collection' => 'members'], $verification);

		expect($response->getHeaderLine('Location'))->toBe('/admin/resend-verification/members?redirect=%2Fwelcome');
	});

	test('drops an off-site redirect', function () use ($submit): void {
		$verification = $this->createMock(EmailVerificationService::class);
		$verification->expects($this->once())->method('resendVerificationToken')
			->with('a@b.test', 'auth', '')
			->willReturn(OperationResult::success('sent'));

		$response = $submit->call($this, ['email' => 'a@b.test', 'redirect' => '//evil.example'], [], $verification);

		expect($response->getHeaderLine('Location'))->toBe('/admin/resend-verification');
	});

	test('without a redirect behaves as before', function () use ($submit): void {
		$verification = $this->createMock(EmailVerificationService::class);
		$verification->expects($this->once())->method('resendVerificationToken')
			->with('a@b.test', 'auth', '')
			->willReturn(OperationResult::success('sent'));

		$response = $submit->call($this, ['email' => 'a@b.test'], [], $verification);

		expect($response->getHeaderLine('Location'))->toBe('/admin/resend-verification');
	});
});
