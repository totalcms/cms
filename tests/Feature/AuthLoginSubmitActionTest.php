<?php

use function Nekofar\Slim\Pest\post;

beforeAll(function (): void {
	recursiveDelete(cmsDataDir());
});

beforeEach(function (): void {
	if (session_status() === PHP_SESSION_ACTIVE) {
		session_destroy();
	}
	$this->setUpApp(bootstrap());
});

describe('AuthLoginSubmitAction', function (): void {
	it('redirects when email and password are missing', function (): void {
		$response = post('/login', []);
		expect($response->getStatusCode())->toBe(302);
	});

	it('redirects when only email is provided', function (): void {
		$response = post('/login', ['email' => 'test@example.com']);
		expect($response->getStatusCode())->toBe(302);
	});

	it('redirects when only password is provided', function (): void {
		$response = post('/login', ['password' => 'secret']);
		expect($response->getStatusCode())->toBe(302);
	});

	it('redirects on invalid credentials', function (): void {
		$response = post('/login', [
			'email'    => 'nonexistent@example.com',
			'password' => 'wrongpassword',
		]);
		expect($response->getStatusCode())->toBe(302);
	});

	it('handles collection-specific login', function (): void {
		$response = post('/login/users', [
			'email'    => 'test@example.com',
			'password' => 'password',
		]);
		expect($response->getStatusCode())->toBe(302);
	});

	it('handles persistent login checkbox', function (): void {
		$response = post('/login', [
			'email'            => 'test@example.com',
			'password'         => 'password',
			'persistent_login' => '1',
		]);
		expect($response->getStatusCode())->toBe(302);
	});

	it('handles redirect parameter in POST data', function (): void {
		$response = post('/login', [
			'email'    => 'test@example.com',
			'password' => 'password',
			'redirect' => '/admin/collections',
		]);
		expect($response->getStatusCode())->toBe(302);
	});

	it('handles redirect parameter in query string', function (): void {
		$response = post('/login?redirect=/admin/settings', [
			'email'    => 'test@example.com',
			'password' => 'password',
		]);
		expect($response->getStatusCode())->toBe(302);
	});
});
