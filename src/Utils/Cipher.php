<?php

namespace TotalCMS\Utils;

class Cipher
{
	const SALT = 'YTFiMmMzZDRlNWY2ZzdoOGk5ajA=';

	public static function obfuscate(string $string, string $key = self::SALT): string
	{
		$keyLength = strlen($key);
		$output = '';

		for ($i = 0; $i < strlen($string); $i++) {
			$output .= $string[$i] ^ $key[$i % $keyLength];
		}

		return base64_encode($output);  // Encode the result to make it printable
	}

	public static function deobfuscate(string $string, string $key = self::SALT): string
	{
		$string = base64_decode($string);
		$keyLength = strlen($key);
		$output = '';

		for ($i = 0; $i < strlen($string); $i++) {
			$output .= $string[$i] ^ $key[$i % $keyLength];
		}

		return $output;
	}

	public static function encrypt(string $data, string $key = self::SALT): string
	{
		$cipher = "aes-256-cbc";  // Cipher algorithm
		$ivlen = openssl_cipher_iv_length($cipher);
		if ($ivlen === false) {
			throw new \Exception("Failed to get IV length");
		}
		$iv = openssl_random_pseudo_bytes($ivlen);  // Generate a random IV

		$encrypted = openssl_encrypt($data, $cipher, $key, 0, $iv);

		return base64_encode($iv . $encrypted);  // Encode the IV with the ciphertext
	}

	public static function decrypt(string $data, string $key = self::SALT): string
	{
		$cipher = "aes-256-cbc";
		$data = base64_decode($data);

		$ivlen = openssl_cipher_iv_length($cipher);
		if ($ivlen === false) {
			throw new \Exception("Failed to get IV length");
		}
		$iv = substr($data, 0, $ivlen);  // Extract the IV from the encoded string
		$ciphertext = substr($data, $ivlen);

		$decrypted = openssl_decrypt($ciphertext, $cipher, $key, 0, $iv);
		if ($decrypted === false) {
			throw new \Exception("Decryption failed");
		}
		return $decrypted;
	}
}
