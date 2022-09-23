<?php

chdir(__DIR__);

require_once '../../vendor/autoload.php';

use ColorThief\ColorThief;
use Mexitek\PHPColors\Color;

$image   = 'images/5D.jpg';
$palette = ColorThief::getPalette($image, 15);
// getting the top 15 colors from the image
// reducing to top 5 colors
$palette = array_slice($palette, 0, 5);

print_r($palette);

$palette = array_map(function ($color) {
    $hex = Color::rgbToHex(['R' => $color[0], 'G' => $color[1], 'B' => $color[2]]);

    return new Color($hex);
}, $palette);

$border = $palette[0];
?>

<img style="max-width:500px;border:10px solid #<?php echo $border->complementary(); ?>;background-color:#<?php echo $border->getHex(); ?>" src="<?php echo $image; ?>"/>
<br>

<?php

$swatch = '<div style="display:inline-block;width:100px;height:100px;background-color:#%s"></div>' . PHP_EOL;
foreach ($palette as $color) {
    echo sprintf($swatch, $color->getHex());
}
echo '<br>';
foreach ($palette as $color) {
    echo sprintf($swatch, $color->complementary());
}

?>