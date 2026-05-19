<?php

namespace TotalCMS\Utils\Color\Couleur\colors;

use       TotalCMS\Utils\Color\Couleur\Color;
use       TotalCMS\Utils\Color\Couleur\ColorFactory;
use       TotalCMS\Utils\Color\Couleur\ColorInterface;
use       TotalCMS\Utils\Color\Couleur\CssColor;
use       TotalCMS\Utils\Color\Couleur\utils;

class      Css
extends    Color
implements ColorInterface {

    /* #region Constructor */
    
    public function __construct(
        public readonly CssColor|\Stringable|string $color,
    ) {
        
    }

    /* #endregion */
    
    /* #region Public Static Methods */
    
    public static function aliases(

    ) :array {
        return [
            'css',
            'html',
            'web',
        ];
    }

    /* #endregion */

    /* #region Public Methods */

    public function change(
        CssColor|\Stringable|string|null $color     = null,
        Css|null                         $fallback  = null,
        bool|null                        $throw     = null,
    ) :self {
        return ColorFactory::newCss(
            value    : $color ?? $this->color,
            from     : $this::space(),
            fallback : $fallback,
            throw    : $throw,
        );
    }

    public function stringify(

    ) :string {
        return utils\css\stringify($this->color);
    } 

    /* #endregion */
        
} 