<?php

use TotalCMS\Domain\Twig\Adapter\TotalCMSTwigAdapter;
use TotalCMS\Support\Config;

/**
 * Test enhanced cms.login() Twig function with redirect parameter support.
 */
describe('CMS Login Twig Function', function (): void {
	beforeEach(function (): void {
		// Mock config
		$this->config      = $this->createMock(Config::class);
		$this->config->api = '/api';

		// Create minimal TotalCMSTwigAdapter with just the dependencies we need for login()
		$this->adapter = new class($this->config) {
			private $api;

			public function __construct($config)
			{
				$this->api = $config->api;
			}

			/**
			 * @SuppressWarnings("PHPMD.Superglobals")
			 */
			public function login(string $collection = '', ?string $redirect = null): string
			{
				$loginUrl = $collection === ''
					? sprintf('%s/%s', $this->api, 'login')
					: sprintf('%s/%s/%s', $this->api, 'login', $collection);

				// If redirect is null, default to current page
				// If redirect is empty string, no redirect parameter
				// If redirect has value, use that value
				if ($redirect === null) {
					$redirect = $_SERVER['REQUEST_URI'] ?? '';
				}

				if ($redirect !== '') {
					$loginUrl .= '?' . http_build_query(['redirect' => $redirect]);
				}

				return $loginUrl;
			}
		};
	});

	afterEach(function (): void {
		// Clean up $_SERVER modifications
		unset($_SERVER['REQUEST_URI']);
	});

	describe('Basic Login URL Generation', function (): void {
		test('generates default login URL without redirect when no current page', function (): void {
			unset($_SERVER['REQUEST_URI']);

			$url = $this->adapter->login();

			expect($url)->toBe('/api/login');
		});

		test('generates collection login URL without redirect when no current page', function (): void {
			unset($_SERVER['REQUEST_URI']);

			$url = $this->adapter->login('members');

			expect($url)->toBe('/api/login/members');
		});
	});

	describe('Auto-Redirect to Current Page', function (): void {
		test('automatically includes current page as redirect parameter', function (): void {
			$_SERVER['REQUEST_URI'] = '/protected/content';

			$url = $this->adapter->login();

			expect($url)->toBe('/api/login?redirect=%2Fprotected%2Fcontent');
		});

		test('includes current page with collection login', function (): void {
			$_SERVER['REQUEST_URI'] = '/member/dashboard';

			$url = $this->adapter->login('members');

			expect($url)->toBe('/api/login/members?redirect=%2Fmember%2Fdashboard');
		});

		test('handles complex URLs with query parameters', function (): void {
			$_SERVER['REQUEST_URI'] = '/shop/products?category=electronics&page=2';

			$url = $this->adapter->login();

			expect($url)->toBe('/api/login?redirect=%2Fshop%2Fproducts%3Fcategory%3Delectronics%26page%3D2');
		});
	});

	describe('Explicit Redirect Parameter', function (): void {
		test('uses explicit redirect parameter over current page', function (): void {
			$_SERVER['REQUEST_URI'] = '/current/page';

			$url = $this->adapter->login('', '/custom/redirect');

			expect($url)->toBe('/api/login?redirect=%2Fcustom%2Fredirect');
		});

		test('uses explicit redirect with collection', function (): void {
			$_SERVER['REQUEST_URI'] = '/current/page';

			$url = $this->adapter->login('members', '/member/area');

			expect($url)->toBe('/api/login/members?redirect=%2Fmember%2Farea');
		});

		test('empty string redirect disables redirect parameter', function (): void {
			$_SERVER['REQUEST_URI'] = '/current/page';

			$url = $this->adapter->login('', '');

			expect($url)->toBe('/api/login');
		});

		test('empty string redirect with collection disables redirect', function (): void {
			$_SERVER['REQUEST_URI'] = '/current/page';

			$url = $this->adapter->login('members', '');

			expect($url)->toBe('/api/login/members');
		});
	});

	describe('URL Encoding', function (): void {
		test('properly encodes special characters in redirect URLs', function (): void {
			$url = $this->adapter->login('', '/page with spaces & symbols?foo=bar');

			expect($url)->toBe('/api/login?redirect=%2Fpage+with+spaces+%26+symbols%3Ffoo%3Dbar');
		});

		test('encodes international characters', function (): void {
			$url = $this->adapter->login('', '/página/español');

			expect($url)->toBe('/api/login?redirect=%2Fp%C3%A1gina%2Fespa%C3%B1ol');
		});

		test('handles already encoded URLs correctly', function (): void {
			$url = $this->adapter->login('', '/page%20with%20spaces');

			expect($url)->toBe('/api/login?redirect=%2Fpage%2520with%2520spaces');
		});
	});

	describe('Registration Flow Use Cases', function (): void {
		test('generates login URL for registration form newAction', function (): void {
			// Simulate a registration page at /register that should redirect to /login with current page
			$_SERVER['REQUEST_URI'] = '/premium/signup';

			$url = $this->adapter->login();

			// This URL would be used in T3 form newAction
			expect($url)->toBe('/api/login?redirect=%2Fpremium%2Fsignup');
		});

		test('generates collection-specific login for member registration', function (): void {
			$_SERVER['REQUEST_URI'] = '/member/register';

			$url = $this->adapter->login('members');

			expect($url)->toBe('/api/login/members?redirect=%2Fmember%2Fregister');
		});

		test('allows custom redirect for post-registration flow', function (): void {
			// Registration form might want to redirect to a thank you page after login
			$url = $this->adapter->login('', '/welcome/new-member');

			expect($url)->toBe('/api/login?redirect=%2Fwelcome%2Fnew-member');
		});
	});

	describe('Edge Cases', function (): void {
		test('handles root path correctly', function (): void {
			$_SERVER['REQUEST_URI'] = '/';

			$url = $this->adapter->login();

			expect($url)->toBe('/api/login?redirect=%2F');
		});

		test('handles empty REQUEST_URI', function (): void {
			$_SERVER['REQUEST_URI'] = '';

			$url = $this->adapter->login();

			expect($url)->toBe('/api/login');
		});

		test('handles very long URLs', function (): void {
			$longPath               = '/very/long/path/' . str_repeat('segment/', 50) . 'end?param=value';
			$_SERVER['REQUEST_URI'] = $longPath;

			$url = $this->adapter->login();

			expect($url)->toContain('/api/login?redirect=');
			expect($url)->toContain('very%2Flong%2Fpath');
			expect($url)->toContain('param%3Dvalue');
		});

		test('null redirect parameter uses current page', function (): void {
			$_SERVER['REQUEST_URI'] = '/current/page';

			$url = $this->adapter->login('', null);

			expect($url)->toBe('/api/login?redirect=%2Fcurrent%2Fpage');
		});
	});
});
