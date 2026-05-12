<?php

declare(strict_types=1);

use TotalCMS\Domain\Twig\Extension\TotalCMSTwigFilters;

// ===== toSeconds =====

test('toSeconds converts H:M:S format', function (): void {
	expect(TotalCMSTwigFilters::toSeconds('1:30:00'))->toBe(5400);
	expect(TotalCMSTwigFilters::toSeconds('0:00:00'))->toBe(0);
	expect(TotalCMSTwigFilters::toSeconds('2:15:30'))->toBe(8130);
});

test('toSeconds converts M:S format', function (): void {
	expect(TotalCMSTwigFilters::toSeconds('5:30'))->toBe(330);
	expect(TotalCMSTwigFilters::toSeconds('0:45'))->toBe(45);
	expect(TotalCMSTwigFilters::toSeconds('90:00'))->toBe(5400);
});

test('toSeconds converts seconds only', function (): void {
	expect(TotalCMSTwigFilters::toSeconds('45'))->toBe(45);
	expect(TotalCMSTwigFilters::toSeconds('0'))->toBe(0);
	expect(TotalCMSTwigFilters::toSeconds('3600'))->toBe(3600);
});

test('toSeconds handles empty string', function (): void {
	expect(TotalCMSTwigFilters::toSeconds(''))->toBe(0);
});
