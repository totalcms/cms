<?php

namespace TotalCMS\Utils\Color\Couleur\exceptions;

class   UnknownColorSpace 
extends \Exception {

    public function __construct(
        string|null     $space    = null,
        int             $code     = 0, 
        \Throwable|null $previous = null,
    ) {
        parent::__construct(
            message  : "Unknown color space",
            code     : $code,
            previous : $previous,
        );
    }
}