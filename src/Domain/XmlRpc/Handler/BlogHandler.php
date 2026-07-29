<?php

declare(strict_types=1);

namespace TotalCMS\Domain\XmlRpc\Handler;

use TotalCMS\Domain\XmlRpc\Data\XmlRpcIdentity;
use TotalCMS\Domain\XmlRpc\Service\BlogRegistry;
use TotalCMS\Domain\XmlRpc\Service\XmlRpcAuth;
use TotalCMS\Support\Config;
use TotalCMS\Support\Version;

/**
 * Blog enumeration and site options — the handshake a client performs before it
 * will show anything to the user.
 */
readonly class BlogHandler implements MethodHandler
{
	public function __construct(
		private XmlRpcAuth $auth,
		private BlogRegistry $registry,
		private Config $config,
	) {
	}

	/** @return array<string,callable(array<int,mixed>,?string):mixed> */
	public function methods(): array
	{
		return [
			'blogger.getUsersBlogs' => $this->bloggerGetUsersBlogs(...),
			'wp.getUsersBlogs'      => $this->wpGetUsersBlogs(...),
			'blogger.getUserInfo'   => $this->getUserInfo(...),
			'wp.getOptions'         => $this->getOptions(...),
			'wp.getProfile'         => $this->getProfile(...),
		];
	}

	/**
	 * blogger.getUsersBlogs(appkey, username, password)
	 *
	 * @param array<int,mixed> $params
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function bloggerGetUsersBlogs(array $params, ?string $collection): array
	{
		return $this->blogList($this->auth->authenticate($params, 1, 2), $collection);
	}

	/**
	 * wp.getUsersBlogs(username, password) — note the shifted positions.
	 *
	 * @param array<int,mixed> $params
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function wpGetUsersBlogs(array $params, ?string $collection): array
	{
		return $this->blogList($this->auth->authenticate($params, 0, 1), $collection);
	}

	/**
	 * @return array<int,array<string,mixed>>
	 */
	private function blogList(XmlRpcIdentity $identity, ?string $collection): array
	{
		$this->auth->assertOperation($identity, 'GET');

		$base  = rtrim($this->config->api, '/');
		$blogs = $collection !== null
			? [$collection => $this->registry->assertBlog($identity, $collection)]
			: $this->registry->blogsFor($identity);

		$list = [];
		foreach ($blogs as $id => $blogCollection) {
			$list[] = [
				'blogid'   => (string)$id,
				'blogName' => $blogCollection->name !== '' ? $blogCollection->name : (string)$id,
				'url'      => $blogCollection->url !== '' ? $blogCollection->url : $base,
				'xmlrpc'   => $base . '/xmlrpc.php',
				'isAdmin'  => true,
			];
		}

		return $list;
	}

	/**
	 * blogger.getUserInfo(appkey, username, password)
	 *
	 * @param array<int,mixed> $params
	 *
	 * @return array<string,mixed>
	 */
	public function getUserInfo(array $params, ?string $collection): array
	{
		$identity = $this->auth->authenticate($params, 1, 2);
		$this->auth->assertOperation($identity, 'GET');

		$name  = $identity->authorName;
		$parts = explode(' ', $name, 2);

		return [
			'userid'    => $name,
			'nickname'  => $name,
			'url'       => rtrim($this->config->api, '/'),
			'email'     => '',
			'lastname'  => $parts[1] ?? '',
			'firstname' => $parts[0],
		];
	}

	/**
	 * wp.getProfile(blog_id, username, password)
	 *
	 * A single struct describing the authenticated caller. T3 has no user
	 * records behind a post's author string — the same gap getUserInfo()
	 * above already accepts — so `bio`/`email` are honestly left blank rather
	 * than invented, and `roles` is the one fixed role every XML-RPC caller
	 * effectively has: they can publish.
	 *
	 * @param array<int,mixed> $params
	 *
	 * @return array<string,mixed>
	 */
	public function getProfile(array $params, ?string $collection): array
	{
		$identity = $this->auth->authenticate($params, 1, 2);
		$this->auth->assertOperation($identity, 'GET');

		$name  = $identity->authorName;
		$parts = explode(' ', $name, 2);

		return [
			'user_id'      => $name,
			'username'     => $name,
			'display_name' => $name,
			'nickname'     => $name,
			'first_name'   => $parts[0],
			'last_name'    => $parts[1] ?? '',
			'bio'          => '',
			'email'        => '',
			'roles'        => ['author'],
		];
	}

	/**
	 * wp.getOptions(blogid, username, password, options[])
	 *
	 * `post_thumbnail: false` is the standard signal telling a client not to
	 * offer featured-image UI — which suppresses attempts at the media upload
	 * v1 cannot serve.
	 *
	 * @param array<int,mixed> $params
	 *
	 * @return array<string,array<string,mixed>>
	 */
	public function getOptions(array $params, ?string $collection): array
	{
		$identity = $this->auth->authenticate($params, 1, 2);
		$this->auth->assertOperation($identity, 'GET');

		$base = rtrim($this->config->api, '/');

		$options = [
			'software_name'    => ['desc' => 'Software Name',    'readonly' => true, 'value' => 'Total CMS'],
			'software_version' => ['desc' => 'Software Version', 'readonly' => true, 'value' => Version::number()],
			'blog_url'         => ['desc' => 'Site URL',         'readonly' => true, 'value' => $base],
			'blog_title'       => ['desc' => 'Site Title',       'readonly' => true, 'value' => $this->config->displayName()],
			'time_zone'        => ['desc' => 'Time Zone',        'readonly' => true, 'value' => $this->config->timezone],
			'post_thumbnail'   => ['desc' => 'Post Thumbnail',   'readonly' => true, 'value' => false],
		];

		$requested = $params[3] ?? null;
		if (is_array($requested) && $requested !== []) {
			$filtered = [];
			foreach ($requested as $name) {
				$key = (string)$name;
				if (isset($options[$key])) {
					$filtered[$key] = $options[$key];
				}
			}

			return $filtered;
		}

		return $options;
	}
}
