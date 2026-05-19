<?php

namespace TotalCMS\Utils\Color\Couleur\Exceptions;

class   UnknownColorSpace 
extends \Exception {

    public function __construct(
        string|null     $space    = null,
        int             $code     = 0,
        \Throwable|null $previous = null,
    ) {
        parent::__construct(
            message  : $space !== null ? "Unknown color space: $space" : "Unknown color space",
            code     : $code,
            previous : $previous,
        );
    }
}