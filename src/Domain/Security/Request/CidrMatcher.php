<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Security\Request;

/**
 * Tests whether an address falls inside any of a set of CIDR ranges.
 *
 * Works on the packed binary form from inet_pton, so IPv4 and IPv6 go through
 * the same path: compare the whole bytes of the prefix, then mask the one
 * partial byte. String prefix comparison would be wrong — '10.0.0.1' and
 * '10.0.0.10' share a textual prefix and are different addresses, and
 * '2400:cb00::1' can be written a dozen ways.
 */
final class CidrMatcher
{
	/** @param list<string> $cidrs */
	public function matches(string $ip, array $cidrs): bool
	{
		$binaryIp = @inet_pton($ip);
		if ($binaryIp === false) {
			return false;
		}

		foreach ($cidrs as $cidr) {
			if ($this->matchesCidr($binaryIp, $cidr)) {
				return true;
			}
		}

		return false;
	}

	private function matchesCidr(string $binaryIp, string $cidr): bool
	{
		$parts = explode('/', $cidr);
		if (count($parts) !== 2 || !ctype_digit($parts[1])) {
			return false;
		}

		$binarySubnet = @inet_pton($parts[0]);
		if ($binarySubnet === false) {
			return false;
		}

		// 4 bytes vs 16: an IPv4 address is never inside an IPv6 range, and
		// comparing them would read past the shorter one.
		if (strlen($binaryIp) !== strlen($binarySubnet)) {
			return false;
		}

		$bits = (int)$parts[1];
		if ($bits < 0 || $bits > strlen($binaryIp) * 8) {
			return false;
		}

		$wholeBytes = intdiv($bits, 8);
		if ($wholeBytes > 0 && strncmp($binaryIp, $binarySubnet, $wholeBytes) !== 0) {
			return false;
		}

		$remainingBits = $bits % 8;
		if ($remainingBits === 0) {
			return true;
		}

		// Compare only the high bits of the byte the prefix stops inside.
		$mask = ~((1 << (8 - $remainingBits)) - 1) & 0xFF;

		return (ord($binaryIp[$wholeBytes]) & $mask) === (ord($binarySubnet[$wholeBytes]) & $mask);
	}
}
