<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use PHPUnit\Framework\TestCase;
use TotalCMS\Support\Config;

/**
 * Where PHP writes session files.
 *
 * Left to itself PHP uses one directory shared by every site on the server, and
 * garbage collection there runs with whichever vhost happens to trigger it —
 * commonly a 24-minute gc_maxlifetime — so a neighbour's sweep deletes our
 * session files no matter what we configure. That is the usual cause of
 * "Total CMS keeps logging me out" on shared hosting.
 */
final class ConfigSessionPathTest extends TestCase
{
	/**
	 * A real Config, built through the real constructor — the resolution under
	 * test lives in there, and a test that reimplements it would pass whatever
	 * the constructor actually did.
	 *
	 * @param array<string,mixed> $session
	 */
	private function config(string $datadir, array $session): Config
	{
		return new Config([
			'env'        => 'test',
			'template'   => sys_get_temp_dir(),
			'dashboard'  => [],
			'datadir'    => $datadir,
			'tmpdir'     => sys_get_temp_dir(),
			'cachedir'   => sys_get_temp_dir() . '/cache',
			'cache'      => [],
			'logger'     => [],
			'error'      => [],
			'domain'     => 'test.com',
			'url'        => 'http://test.com',
			'api'        => 'http://test.com',
			'locale'     => 'en_US',
			'session'    => $session,
			'auth'       => [],
			'debug'      => false,
			'notfound'   => '/404',
			'imageworks' => [],
			'smtp'       => [],
			'mailer'     => [],
		]);
	}

	public function testResolvesToTheDataDirectory(): void
	{
		// Under .system, alongside logs and persistent-login tokens: the
		// directory a zip update leaves alone and the shipped Apache/nginx
		// rules already deny.
		$config = $this->config('/srv/site/tcms-data', ['save_path' => '']);

		$this->assertSame('/srv/site/tcms-data/.system/sessions', $config->session['save_path']);
	}

	public function testAnExplicitPathWins(): void
	{
		$config = $this->config('/srv/site/tcms-data', ['save_path' => '/var/sessions']);

		$this->assertSame('/var/sessions', $config->session['save_path']);
	}

	public function testFollowsADataDirectoryOverride(): void
	{
		// Resolved after the tcms.php merge, so a relocated datadir takes the
		// sessions with it rather than stranding them at the default.
		$config = $this->config('/elsewhere/data', ['save_path' => '']);

		$this->assertSame('/elsewhere/data/.system/sessions', $config->session['save_path']);
	}

	public function testDoesNotVaryWithTheRequestHostname(): void
	{
		// The reason this is not scoped by domain. It was, in 7cb08704b, and was
		// reverted five days later: $domain comes from HTTP_HOST, so
		// example.com and www.example.com hashed to different directories and a
		// redirect between them logged the user straight out.
		$bare         = $this->config('/srv/site/tcms-data', ['save_path' => '']);
		$www          = $this->config('/srv/site/tcms-data', ['save_path' => '']);
		$bare->domain = 'example.com';
		$www->domain  = 'www.example.com';

		$this->assertSame($bare->session['save_path'], $www->session['save_path']);
	}
}
