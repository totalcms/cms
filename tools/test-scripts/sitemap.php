<?php

require_once 'local.php';

$sitemap = new Dynamics\Sitemap($dynamics['settings'], $logger);
$sitemap->addCollection("products", "https://www.weavers.space/stacks/");
echo $sitemap->print();
