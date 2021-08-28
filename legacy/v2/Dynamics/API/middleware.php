<?php
// Middleware
session_start();

$app->add($app->getContainer()->get('csrf'));
