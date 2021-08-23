<?php

require_once 'vendor/autoload.php';

use ColorThief\ColorThief;
use Mexitek\PHPColors\Color;

$image = 'goldengate.jpg';
$image = 'icon.png';
$palette = ColorThief::getPalette($image, 15);
$palette = array_slice($palette, 0, 6);
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