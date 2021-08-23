<?php

require_once '../dynamics/autoload.php';

use League\Glide\Responses\SlimResponseFactory;
use League\Glide\ServerFactory;

$image = 'goldengate.jpg';
//$image = 'icon.png';

// Setup Glide server
$server = ServerFactory::create([
    'source' => '.',
    'cache' => 'cache',
    'response' => new SlimResponseFactory(),
]);

// You could manually pass in the image path and manipulations options
$response = $server->getImageResponse($image, ['h' => 1000]);

var_dump($response);
