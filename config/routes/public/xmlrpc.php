<?php

declare(strict_types=1);

use Slim\Interfaces\RouteCollectorProxyInterface;
use TotalCMS\Action\XmlRpc\XmlRpcAction;
use TotalCMS\Action\XmlRpc\XmlRpcDiscoveryAction;
use TotalCMS\Middleware\Security\XmlRpcRateLimitMiddleware;

return function (RouteCollectorProxyInterface $app): void {
	// WordPress-compatible publishing endpoint. Root-level (not under /api)
	// because clients expect WordPress-shaped paths, and the shipped
	// public/.htaccess already routes non-existent files through the front
	// controller — no new rewrite rules, and subfolder installs work as-is.
	//
	// Two shapes on purpose:
	//   /xmlrpc.php        — blogid selects the collection; what clients probe for
	//   /xmlrpc/{coll}     — collection pinned by URL, blogid ignored. Immune to
	//                        clients that hardcode blogid=1 (single-site WordPress
	//                        always reports 1), and dodges host WAF rules that 403
	//                        the literal xmlrpc.php path.
	// NOT /xmlrpc.php/{coll} — `.php/` mid-path hits cgi.fix_pathinfo differences.
	$app->post('/xmlrpc.php', XmlRpcAction::class)
		->add(XmlRpcRateLimitMiddleware::class)
		->setName('xmlrpc');
	$app->get('/xmlrpc.php', XmlRpcDiscoveryAction::class)->setName('xmlrpc-discovery');
	$app->post('/xmlrpc/{collection}', XmlRpcAction::class)
		->add(XmlRpcRateLimitMiddleware::class)
		->setName('xmlrpc-collection');
};
