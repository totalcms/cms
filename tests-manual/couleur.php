<?php

use matthieumastadenis\couleur\ColorFactory;
use matthieumastadenis\couleur\ColorSpace;

require_once '../vendor/autoload.php';

// Create a new colors\Css instance from an HSL array:
$lch = ColorFactory::newOkLch('#77ffff', ColorSpace::HexRgb);
$oklch = array_map(fn ($l) => round($l, 3), $lch->coordinates());
print_r($oklch);
