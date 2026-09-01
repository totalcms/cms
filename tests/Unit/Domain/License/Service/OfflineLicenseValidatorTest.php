<?php

declare(strict_types=1);

use Firebase\JWT\JWT;
use Psr\Log\NullLogger;
use TotalCMS\Domain\License\Repository\OfflineLicenseRepository;
use TotalCMS\Domain\License\Service\OfflineLicenseValidator;
use TotalCMS\Factory\LoggerFactory;
use TotalCMS\Support\Config;

// Offline licences are the air-gapped path: a signed JWT dropped on disk that
// grants Pro/Enterprise without the licence server ever being contacted. The
// only thing standing between that and a free licence is the RS256 signature
// check, so the test that matters most here is that a token we sign ourselves
// is refused.
//
// Coverage of this class is bounded by design: the verifying key is a private
// const with no seam, so no test can mint a token it will accept. Everything
// below therefore exercises the rejection, delegation and caching paths. See
// the note at the end of this file.

/**
 * Sign a token with a freshly generated key — deliberately NOT the key the
 * validator trusts.
 *
 * @param array<string,mixed> $claims
 */
function offlineLicenseForgedToken(array $claims): string
{
	$key = openssl_pkey_new([
		'private_key_type' => OPENSSL_KEYTYPE_RSA,
		'private_key_bits' => 2048,
	]);
	openssl_pkey_export($key, $pem);

	return JWT::encode($claims, $pem, 'RS256');
}

/**
 * @param string|null $token what the repository hands back (null = no file)
 */
function offlineLicenseValidator(
	?string $token,
	string $domain = 'example.test',
	bool $exists = true,
	?OfflineLicenseRepository &$repo = null,
): OfflineLicenseValidator {
	$repo = test()->createMock(OfflineLicenseRepository::class);
	$repo->method('read')->willReturn($token);
	$repo->method('exists')->willReturn($exists);
	$repo->method('getExpectedFilename')->willReturn('example.test-offline-license.key');
	$repo->method('getUploadDirectory')->willReturn('/data/.system');

	$config         = (new ReflectionClass(Config::class))->newInstanceWithoutConstructor();
	$config->domain = $domain;

	$loggerFactory = test()->createMock(LoggerFactory::class);
	$loggerFactory->method('channelLogger')->willReturn(new NullLogger());

	return new OfflineLicenseValidator($repo, $config, $loggerFactory);
}

describe('OfflineLicenseValidator signature enforcement', function (): void {
	it('refuses a licence signed with a key it does not trust', function (): void {
		// The whole security model in one assertion: anyone can produce a
		// well-formed token with perfect claims, and it must still be worthless.
		$forged = offlineLicenseForgedToken([
			'type'              => 'offline',
			'domain'            => 'example.test',
			'edition'           => 'enterprise',
			'updatesValidUntil' => '2099-01-01',
			'licenseKey'        => 'FORGED-KEY',
		]);

		expect(offlineLicenseValidator($forged)->validate())->toBeNull();
	});

	it('throws rather than returning a licence when validateToken gets a forged token', function (): void {
		$forged = offlineLicenseForgedToken([
			'type'    => 'offline',
			'domain'  => 'example.test',
			'edition' => 'pro',
		]);

		expect(fn () => offlineLicenseValidator(null)->validateToken($forged))
			->toThrow(Exception::class);
	});

	it('refuses a token that is not a JWT at all', function (): void {
		expect(offlineLicenseValidator('this-is-not-a-token')->validate())->toBeNull();
	});

	it('refuses an unsigned "none" algorithm token', function (): void {
		// alg=none is the classic JWT bypass; Firebase\JWT rejects it, but this
		// pins the behaviour so a library swap cannot quietly reintroduce it.
		$payload = rtrim(strtr(base64_encode((string)json_encode([
			'type' => 'offline', 'domain' => 'example.test', 'edition' => 'enterprise',
		])), '+/', '-_'), '=');
		$header  = rtrim(strtr(base64_encode((string)json_encode(['alg' => 'none', 'typ' => 'JWT'])), '+/', '-_'), '=');

		expect(offlineLicenseValidator("$header.$payload.")->validate())->toBeNull();
	});
});

describe('OfflineLicenseValidator when no licence is present', function (): void {
	it('reports no offline licence and validates to null', function (): void {
		$validator = offlineLicenseValidator(null, exists: false);

		expect($validator->hasOfflineLicense())->toBeFalse();
		expect($validator->validate())->toBeNull();
	});

	it('reports an offline licence exists when the repository says so', function (): void {
		expect(offlineLicenseValidator(null, exists: true)->hasOfflineLicense())->toBeTrue();
	});

	it('returns null details when there is no file', function (): void {
		expect(offlineLicenseValidator(null)->getDetails())->toBeNull();
	});
});

describe('OfflineLicenseValidator::getDetails', function (): void {
	it('reports the failure instead of throwing when the token will not decode', function (): void {
		// getDetails() is display-only — the admin screen still needs to render
		// something when the file is corrupt.
		$details = offlineLicenseValidator('garbage')->getDetails();

		expect($details)->toBeArray();
		expect($details['valid'])->toBeFalse();
		expect($details['error'] ?? '')->not->toBe('');
	});
});

describe('OfflineLicenseValidator caching', function (): void {
	it('reads the licence file once however many times validate() is called', function (): void {
		// validate() runs on license-checked requests; re-reading and
		// re-verifying RSA on every call would be wasted work.
		$repo = test()->createMock(OfflineLicenseRepository::class);
		$repo->expects(test()->once())->method('read')->willReturn(null);

		$config         = (new ReflectionClass(Config::class))->newInstanceWithoutConstructor();
		$config->domain = 'example.test';
		$loggerFactory  = test()->createMock(LoggerFactory::class);
		$loggerFactory->method('channelLogger')->willReturn(new NullLogger());

		$validator = new OfflineLicenseValidator($repo, $config, $loggerFactory);

		expect($validator->validate())->toBeNull();
		expect($validator->validate())->toBeNull();
		expect($validator->validate())->toBeNull();
	});

	it('caches a rejected token too, so a bad file is not re-verified per call', function (): void {
		$repo = test()->createMock(OfflineLicenseRepository::class);
		$repo->expects(test()->once())->method('read')->willReturn('garbage');

		$config         = (new ReflectionClass(Config::class))->newInstanceWithoutConstructor();
		$config->domain = 'example.test';
		$loggerFactory  = test()->createMock(LoggerFactory::class);
		$loggerFactory->method('channelLogger')->willReturn(new NullLogger());

		$validator = new OfflineLicenseValidator($repo, $config, $loggerFactory);

		expect($validator->validate())->toBeNull();
		expect($validator->validate())->toBeNull();
	});

	it('caches details lookups as well', function (): void {
		$repo = test()->createMock(OfflineLicenseRepository::class);
		$repo->expects(test()->once())->method('read')->willReturn(null);

		$config         = (new ReflectionClass(Config::class))->newInstanceWithoutConstructor();
		$config->domain = 'example.test';
		$loggerFactory  = test()->createMock(LoggerFactory::class);
		$loggerFactory->method('channelLogger')->willReturn(new NullLogger());

		$validator = new OfflineLicenseValidator($repo, $config, $loggerFactory);

		expect($validator->getDetails())->toBeNull();
		expect($validator->getDetails())->toBeNull();
	});
});

describe('OfflineLicenseValidator path helpers', function (): void {
	it('surfaces where the operator should put the licence file', function (): void {
		$validator = offlineLicenseValidator(null);

		expect($validator->getExpectedFilename())->toBe('example.test-offline-license.key');
		expect($validator->getExpectedDirectory())->toBe('/data/.system');
	});
});
