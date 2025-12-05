<?php

namespace TotalCMS\Domain\Security\Encryption;

/**
 * Cipher utility for obfuscation and encryption in Total CMS.
 *
 * IMPORTANT: This class provides two different types of data protection:
 *
 * 1. OBFUSCATION (obfuscate/deobfuscate):
 *    - Purpose: Hide data from casual viewing (config values, DSNs, etc.)
 *    - Security: NOT cryptographically secure - designed for obscurity, not security
 *    - Use cases: Configuration files, Twig templates, hiding sensitive strings
 *    - Deterministic: Same input always produces same output (required for consistency)
 *
 * 2. ENCRYPTION (encrypt/decrypt):
 *    - Purpose: Secure protection of sensitive data (passwords, tokens)
 *    - Security: Cryptographically secure using AES-256-CBC
 *    - Use cases: File download passwords, secure data transmission
 *    - Random: Same input produces different output each time (more secure)
 */
class Cipher
{
	// Default salt for obfuscation - maintains backward compatibility
	public const SALT = 'YTFiMmMzZDRlNWY2ZzdoOGk5ajA=';

	/**
	 * Obfuscate string data for hiding from casual viewing.
	 *
	 * NOTE: This is NOT encryption! Use encrypt() for security-sensitive data.
	 * This method provides deterministic obfuscation suitable for:
	 * - Configuration values (Sentry DSN, API endpoints)
	 * - Template data transformation
	 * - URL-safe data encoding
	 *
	 * @param string $string Data to obfuscate
	 * @param string $key Obfuscation key (defaults to class SALT)
	 *
	 * @return string URL-safe base64 encoded obfuscated data
	 */
	public static function obfuscate(string $string, string $key = self::SALT): string
	{
		// Derive a better key from the salt
		$derivedKey = self::deriveObfuscationKey($key, strlen($string));

		// Apply character-position-dependent obfuscation
		$output = '';
		for ($i = 0; $i < strlen($string); $i++) {
			$char    = ord($string[$i]);
			$keyByte = ord($derivedKey[$i % strlen($derivedKey)]);

			// Apply position-dependent transformation
			$transformed = ($char ^ $keyByte ^ ($i % 256)) & 0xFF;
			$output .= chr($transformed);
		}

		// Apply simple character scrambling based on key
		$scrambled = self::scrambleString($output, $key);

		// Use URL-safe base64 encoding
		return rtrim(strtr(base64_encode($scrambled), '+/', '-_'), '=');
	}

	/**
	 * Deobfuscate string data previously obfuscated with obfuscate().
	 *
	 * @param string $string Base64 encoded obfuscated data
	 * @param string $key Obfuscation key (must match key used for obfuscation)
	 *
	 * @return string Original data
	 */
	public static function deobfuscate(string $string, string $key = self::SALT): string
	{
		// Restore padding and decode from URL-safe base64
		$padded  = str_pad(strtr($string, '-_', '+/'), strlen($string) % 4, '=', STR_PAD_RIGHT);
		$decoded = base64_decode($padded, true);

		if ($decoded === false) {
			throw new \Exception('Invalid obfuscated data');
		}

		// Reverse character scrambling
		$unscrambled = self::unscrambleString($decoded, $key);

		// Derive the same key used for obfuscation
		$derivedKey = self::deriveObfuscationKey($key, strlen($unscrambled));

		// Reverse character-position-dependent obfuscation
		$output = '';
		for ($i = 0; $i < strlen($unscrambled); $i++) {
			$char    = ord($unscrambled[$i]);
			$keyByte = ord($derivedKey[$i % strlen($derivedKey)]);

			// Reverse position-dependent transformation
			$original = ($char ^ $keyByte ^ ($i % 256)) & 0xFF;
			$output .= chr($original);
		}

		return $output;
	}

	/**
	 * Derive a better obfuscation key from the salt.
	 */
	private static function deriveObfuscationKey(string $salt, int $length): string
	{
		// Use a simple but effective key derivation
		$decoded = base64_decode($salt) ?: $salt;
		$key     = hash('sha256', $decoded . 'TotalCMS_Obfuscation_v2', true);

		// Extend key to required length
		while (strlen($key) < $length) {
			$key .= hash('sha256', $key . $decoded, true);
		}

		return substr($key, 0, max($length, 32));
	}

	/**
	 * Simple deterministic string scrambling based on key.
	 */
	private static function scrambleString(string $input, string $key): string
	{
		$keyHash = crc32($key) & 0xFF;
		$output  = '';

		for ($i = 0; $i < strlen($input); $i++) {
			$scrambleIndex = ($i + $keyHash) % strlen($input);
			$output .= $input[$scrambleIndex];
		}

		return $output;
	}

	/**
	 * Reverse the string scrambling.
	 */
	private static function unscrambleString(string $input, string $key): string
	{
		$keyHash = crc32($key) & 0xFF;
		$output  = str_repeat("\0", strlen($input));

		for ($i = 0; $i < strlen($input); $i++) {
			$originalIndex          = ($i + $keyHash) % strlen($input);
			$output[$originalIndex] = $input[$i];
		}

		return $output;
	}

	/**
	 * Create context-specific obfuscation key
	 * Useful for different subsystems that need separate obfuscation contexts.
	 *
	 * @param string $context Context identifier (e.g., 'sentry', 'downloads', 'config')
	 * @param string $baseSalt Base salt to derive from (defaults to class SALT)
	 *
	 * @return string Context-specific salt for use with obfuscate/deobfuscate
	 */
	public static function contextKey(string $context, string $baseSalt = self::SALT): string
	{
		$contextHash = hash('sha256', $baseSalt . $context . 'TotalCMS_Context', true);

		return base64_encode($contextHash);
	}

	public static function encrypt(string $data, string $key = self::SALT): string
	{
		$cipher = 'aes-256-cbc';  // Cipher algorithm
		$ivlen  = openssl_cipher_iv_length($cipher);
		// @phpstan-ignore function.alreadyNarrowedType (openssl_cipher_iv_length can return false at runtime)
		if (!is_int($ivlen)) {
			throw new \Exception('Failed to get IV length');
		}
		$iv = openssl_random_pseudo_bytes($ivlen);  // Generate a random IV

		$encrypted = openssl_encrypt($data, $cipher, $key, 0, $iv);

		return base64_encode($iv . $encrypted);  // Encode the IV with the ciphertext
	}

	public static function decrypt(string $data, string $key = self::SALT): string
	{
		$cipher = 'aes-256-cbc';
		$data   = base64_decode($data);

		$ivlen = openssl_cipher_iv_length($cipher);
		// @phpstan-ignore function.alreadyNarrowedType (openssl_cipher_iv_length can return false at runtime)
		if (!is_int($ivlen)) {
			throw new \Exception('Failed to get IV length');
		}

		// Validate that we have enough data for IV + at least some ciphertext
		if (strlen($data) < $ivlen + 1) {
			throw new \Exception('Invalid encrypted data: insufficient length');
		}

		$iv         = substr($data, 0, $ivlen);  // Extract the IV from the encoded string
		$ciphertext = substr($data, $ivlen);

		$decrypted = openssl_decrypt($ciphertext, $cipher, $key, 0, $iv);
		if ($decrypted === false) {
			throw new \Exception('Decryption failed');
		}

		return $decrypted;
	}
}
