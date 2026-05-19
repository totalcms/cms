<?php

namespace TotalCMS\Utils\Color\Couleur\utils\okLch;

use TotalCMS\Utils\Color\Couleur\ColorFactory;
use TotalCMS\Utils\Color\Couleur\ColorSpace;
use TotalCMS\Utils\Color\Couleur\utils;

/**
 * Enhanced OKLCH utilities with improved hex conversion and hue handling.
 * These functions address ColorFactory issues identified in production use.
 */

/**
 * Convert OKLCH coordinates to hex with enhanced error handling and boundary checking.
 * This function avoids ColorFactory stringify issues by manually formatting the hex output.
 *
 * @param array<string,float> $oklch OKLCH coordinates as ['l' => lightness, 'c' => chroma, 'h' => hue]
 * @return string Hex color string (e.g., '#ff0000')
 */
function oklchToHex(array $oklch): string
{
    // Convert OKLCH to RGB first to avoid ColorFactory hex issues
    $rgb = ColorFactory::newRgb([$oklch['l'], $oklch['c'], $oklch['h']], ColorSpace::OkLch);
    if ($rgb === null) {
        return '#000000'; // black fallback
    }
    
    $coordinates = $rgb->coordinates();

    // Manually format hex to avoid ColorFactory stringify issues
    // Apply proper boundary checking and rounding
    $r = max(0, min(255, round($coordinates[0])));
    $g = max(0, min(255, round($coordinates[1])));
    $b = max(0, min(255, round($coordinates[2])));

    return sprintf('#%02x%02x%02x', $r, $g, $b);
}

/**
 * Change OKLCH color with enhanced hue wraparound handling.
 * The ColorFactory library doesn't handle hue wrapping properly for 360° operations.
 *
 * @param array<string,float> $oklch Original OKLCH coordinates
 * @param array<string,mixed> $change Changes to apply (e.g., ['h' => '+10', 'l' => 0.1])
 * @return array<string,float> Modified OKLCH coordinates
 */
function oklchChange(array $oklch, array $change): array
{
    $oklchColor = ColorFactory::newOkLch([$oklch['l'], $oklch['c'], $oklch['h']], ColorSpace::OkLch);
    if ($oklchColor === null) {
        return ['l' => 0, 'c' => 0, 'h' => 0]; // black fallback
    }

    // ColorFactory library doesn't handle hue wrapping - only change hue if specified
    $updatedHue = $oklch['h'];
    if (isset($change['h'])) {
        $updatedHue = changeHue($oklch['h'], $change['h']);
    }

    $oklchColor = $oklchColor->change(
        lightness: $change['l'] ?? null,
        chroma: $change['c'] ?? null,
        hue: null // Don't let ColorFactory handle hue changes
    );
    
    $coordinates = array_map(fn ($c) => round($c, 3), $oklchColor->coordinates());

    return [
        'l' => $coordinates[0],
        'c' => $coordinates[1], 
        'h' => $updatedHue,
    ];
}

/**
 * Apply hue changes with proper 360° wraparound handling.
 * Supports formula-based changes like "+10", "-20", "*2", "/3".
 *
 * @param int|float $hue Current hue value (0-360)
 * @param string $formula Change formula (e.g., "+10", "-20", "*2", "/3")
 * @return float Updated hue value with proper wraparound
 */
function changeHue(int|float $hue, string $formula): float
{
    $formula = trim($formula);
    $operation = substr($formula, 0, 1);
    $value = floatval(substr($formula, 1));

    $hue = match ($operation) {
        '+' => $hue + $value,
        '-' => $hue - $value,
        '*' => $hue * $value,
        '/' => $value > 0 ? $hue / $value : $hue,
        default => $hue,
    };

    // Apply proper 360° wraparound for hue values
    if ($hue < 0 || $hue >= 360) {
        $hue = fmod($hue, 360);
        if ($hue < 0) {
            $hue += 360;
        }
    }

    return round($hue, 3);
}

/**
 * Enhanced OKLCH cleaning with better boundary checking.
 * This extends the base clean function with improved coordinate validation.
 *
 * @param mixed $value Input color value
 * @param bool|null $throw Whether to throw exceptions on error
 * @return array Cleaned OKLCH coordinates
 */
function cleanEnhanced(mixed $value, bool|null $throw = null): array
{
    $cleaned = clean($value, $throw);

    // Additional boundary checking for better color accuracy
    // Ensure lightness is properly bounded (0-100%)
    $cleaned[0] = max(0, min(100, $cleaned[0]));

    // Ensure chroma is non-negative (can be > 1 in OKLCH)
    $cleaned[1] = max(0, $cleaned[1]);

    // Ensure hue is properly wrapped (0-360°)
    $cleaned[2] = fmod($cleaned[2], 360);
    if ($cleaned[2] < 0) {
        $cleaned[2] += 360;
    }

    // Ensure opacity is properly bounded (0-100%)
    $cleaned[3] = max(0, min(100, $cleaned[3]));

    return $cleaned;
}