<?php

use function TotalCMS\Slim\Pest\get;
use function TotalCMS\Slim\Pest\post;

beforeAll(function (): void {
	recursiveDelete(cmsDataDir());
});

beforeEach(function (): void {
	if (session_status() === PHP_SESSION_ACTIVE) {
		session_destroy();
	}
	$this->setUpApp(bootstrap());
});

describe('ForgotPasswordAction', function (): void {
	it('displays forgot password form', function (): void {
		$response = get('/admin/forgot-password');
		expect($response->getStatusCode())->toBe(200);
	});

	it('displays forgot password form for specific collection', function (): void {
		$response = get('/admin/forgot-password/users');
		expect($response->getStatusCode())->toBe(200);
	});

	it('keeps where the visitor started in the form and the back link', function (): void {
		$body = (string)get('/admin/forgot-password/users?redirect=%2Fes%2Fprivate')->getBody();

		expect($body)->toContain('<input type="hidden" name="redirect" value="/es/private">')
			->and($body)->toContain('/login/users?redirect=%2Fes%2Fprivate');
	});

	it('drops a redirect to another site', function (): void {
		$body = (string)get('/admin/forgot-password?redirect=https%3A%2F%2Fevil.example%2F')->getBody();

		expect($body)->not->toContain('name="redirect"')
			->and($body)->not->toContain('evil.example');
	});
});

describe('ForgotPasswordSubmitAction', function (): void {
	it('redirects when email is empty', function (): void {
		$response = post('/admin/forgot-password', ['email' => '']);
		expect($response->getStatusCode())->toBe(302);
	});

	it('redirects when email is invalid', function (): void {
		$response = post('/admin/forgot-password', ['email' => 'not-an-email']);
		expect($response->getStatusCode())->toBe(302);
	});

	it('redirects with success for valid email format', function (): void {
		// Even if user does not exist, should return success to prevent enumeration
		$response = post('/admin/forgot-password', ['email' => 'unknown@example.com']);
		expect($response->getStatusCode())->toBe(302);
	});

	it('handles collection-specific forgot password', function (): void {
		$response = post('/admin/forgot-password/users', ['email' => 'test@example.com']);
		expect($response->getStatusCode())->toBe(302);
	});

	it('handles missing email field', function (): void {
		$response = post('/admin/forgot-password', []);
		expect($response->getStatusCode())->toBe(302);
	});

	it('sends the visitor back to the form with where they started', function (): void {
		$response = post('/admin/forgot-password/users', ['email' => 'nobody@example.com', 'redirect' => '/es/private']);

		expect($response->getStatusCode())->toBe(302)
			->and($response->getHeaderLine('Location'))->toEndWith('/forgot-password/users?redirect=%2Fes%2Fprivate');
	});

	it('trims whitespace from email', function (): void {
		$response = post('/admin/forgot-password', ['email' => '  test@example.com  ']);
		expect($response->getStatusCode())->toBe(302);
	});
});
