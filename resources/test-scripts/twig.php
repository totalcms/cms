<?php

require_once '../../vendor/autoload.php';

$loader = new \Twig\Loader\FilesystemLoader('../../templates/');
$twig   = new \Twig\Environment($loader);

echo $twig->render('form.twig', ["collection" => "myobjects"]);
