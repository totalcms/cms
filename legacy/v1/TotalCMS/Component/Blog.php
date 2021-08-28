<?php
namespace TotalCMS\Component;

use \FeedWriter\RSS2;

//---------------------------------------------------------------------------------
// Blog class
//---------------------------------------------------------------------------------
class Blog extends Component
{
	protected $json_file;
	protected $rss_file;
	protected $gallery;

	protected $rss_title;
	protected $rss_description;
	protected $baseurl;
	public    $posturl;
	protected $baseurl_file;
	protected $posturl_file;
	protected $sitemap_file;
	protected $db;
	protected $dbversion;

	public    $categories;
	public    $tags;
	public    $draft;
	public    $author;
	public    $genre;
	public    $extra;
	public    $permalink;
	public    $title;
	public    $summary;
	public    $content;
	public    $words;
	public    $timestamp;
	public    $posts;

	const DBVERSION = '20170924';

	public function __construct($slug,$options=array())
	{
		$options = array_merge(array(
			'type'            => 'blog',
			"categories"      => "",
			"tags"            => "",
			"featured"        => "false",
			"draft"           => "false",
			"author"          => "",
			"genre"           => "default",
			"title"           => "",
			"permalink"       => "",
			"dateformat"      => "m/d/Y",
			"timestamp"       => false,
			"summary"         => "",
			"content"         => "",
			"extra"           => "",
			'rss_title'       => '',
			'rss_description' => '',
			'baseurl'     => '',
			'posturl'     	  => '',
			'image_options'   => array()
		), $options);

		$options['set'] = true;

		parent::__construct($slug,$options);

		$this->categories = array_filter(array_map(function($category){return trim($category);}, explode(",",$options['categories'])));
		$this->tags       = array_filter(array_map(function($tag){return trim($tag);}, explode(",",$options['tags'])));
		$this->draft      = ($options['draft'] === 'true');
		$this->featured   = ($options['featured'] === 'true');
		$this->author     = $options['author'];
		$this->permalink  = $this->urlify_string($options['permalink']);
		$this->title      = $options['title'];
		$this->summary    = $options['summary'];
		$this->content    = $options['content'];
		$this->extra      = $options['extra'];
		$this->genre      = $options['genre'];
		$this->words      = str_word_count($options['content']);

		$this->rss_title       = $options['rss_title'];
		$this->rss_description = $options['rss_description'];
		$this->baseurl     	   = $options['baseurl'];
		$this->posturl         = $options['posturl'];

		$this->baseurl_file    = "$this->target_dir/$this->slug.baseurl";
		$this->posturl_file    = "$this->target_dir/$this->slug.posturl";

		if (empty($this->baseurl)) {
			if (file_exists($this->baseurl_file)) $this->baseurl = file_get_contents($this->baseurl_file);
		}
		else {
			$this->make_dir(dirname($this->baseurl_file)); // make the dir just in case
			file_put_contents($this->baseurl_file,$this->baseurl);
		}

		if (empty($this->posturl)) {
			if (file_exists($this->posturl_file)) $this->posturl = file_get_contents($this->posturl_file);
		}
		else {
			if (strpos($this->posturl,'http') === 0){
				if (!preg_match("#/$#",$this->posturl)) $this->posturl .= '/';
			}
			else {
				$posturl = new \URL\Normalizer($this->baseurl.$this->posturl);
				$this->posturl = $posturl->normalize().'?permalink=';
			}
			$this->make_dir(dirname($this->posturl_file)); // make the dir just in case
			file_put_contents($this->posturl_file,$this->posturl);
		}

		$this->timestamp = empty($options['timestamp']) ? time() : intval($options['timestamp']);

		$this->set_filename($this->permalink);

		$options['image_options'] = array_merge($options,$options['image_options']);
		$options['image_options']['type'] = 'gallery';
		$options['image_options']['target_dir'] = "/gallery/$this->type/$slug/$this->permalink";

	    $this->gallery = new Gallery($this->permalink,$options['image_options']);

		$this->posts = array();
		$this->db = false;
		$this->dbversion = self::DBVERSION;

		$this->json_file = "$this->target_dir/_blog.json";
		$this->rss_file  = "$this->target_dir/$this->slug.rss";
		$this->sitemap_file  = "$this->target_dir/$this->slug-sitemap.xml";

		$this->bkp_dir = $this->cms_dir."/backups/$this->type/$slug/$this->permalink";
	}

	public function featured_image()
	{
		$image = false;
		if (file_exists($this->gallery->json_file)) {
			$gallery = json_decode(file_get_contents($this->gallery->json_file));
			$image = $gallery[0];
		}
		return $image;
	}

	public function get_contents($format=false)
	{
		return file_exists($this->target_path()) ? json_decode(file_get_contents($this->target_path())) : false;
	}

	public function post_summary($post=false)
	{
		$obj = $post === false ? $this : $post;
		return array(
			"categories" => array_values($obj->categories),
			"tags"       => array_values($obj->tags),
			"gallery"    => array_values($this->gallery_images($obj->permalink)),
			"genre"      => property_exists($obj,'genre') ? $obj->genre : 'default',
			"draft"      => $obj->draft,
			"featured"   => $obj->featured,
			"author"     => $obj->author,
			"title"      => $obj->title,
			"permalink"  => $obj->permalink,
			"timestamp"  => $obj->timestamp,
			"summary"    => $obj->summary,
			"words"      => $obj->words
		);
	}

	public function meta_description($post)
	{
		$summary = str_replace('"','',strip_tags($post->summary));
		return "<meta name=\"description\" content=\"$summary\">";
	}

	public function set_permalink($permalink)
	{
		$this->permalink = $permalink;
		$this->set_filename($this->permalink);
	}

	public function meta_twitter($post,$twitter)
	{
		$summary = str_replace('"','',strip_tags($post->summary));
		$tags = "<meta name=\"twitter:card\" content=\"summary\">
				<meta name=\"twitter:site\" content=\"$twitter\">
				<meta name=\"twitter:url\" content=\"$this->posturl$post->permalink\">
				<meta name=\"twitter:title\" content=\"$post->title\">
				<meta name=\"twitter:description\" content=\"$summary\">";

		if (is_array($post->gallery) && count($post->gallery) > 0) {
			$image = $post->gallery[0];
			$tags .= "<meta name=\"twitter:image\" content=\"$this->baseurl$image->img\">";
		}
		return $tags;
	}

	public function meta_facebook($post)
	{
		$summary = str_replace('"','',strip_tags($post->summary));
		$tags = "<meta property=\"og:url\" content=\"$this->posturl$post->permalink\">
				<meta property=\"og:type\" content=\"article\">
				<meta property=\"og:title\" content=\"$post->title\">
				<meta property=\"og:description\" content=\"$summary\">";

		if (is_array($post->gallery) && count($post->gallery) > 0) {
			$image = $post->gallery[0];
			$tags .= "<meta property=\"og:image\" content=\"$this->baseurl$image->img\">";
		}
		return $tags;
	}

	public function meta_google($post)
	{
		$summary = str_replace('"','',strip_tags($post->summary));
		$tags = "<meta itemprop=\"name\" content=\"$post->title\">
				<meta itemprop=\"description\" content=\"$summary\">";

		if (is_array($post->gallery) && count($post->gallery) > 0) {
			$image = $post->gallery[0];
			$tags .= "<meta itemprop=\"image\" content=\"$this->baseurl$image->img\">";
		}
		return $tags;
	}

	public function post_to_json()
	{
		$post = $this->post_summary();
		$post["content"] = $this->content;
		$post["extra"] = $this->extra;
		return json_encode($post);
	}

	public function post_summary_to_json()
	{
		return json_encode(post_summary());
	}

	public function save_content_to_cms($contents,$options=array())
	{
		if (empty($this->permalink)) {
			$this->log_error("Must define a permalink in order to save a blog post");
			return false;
		}

    	// Save images
		if ($options['image'] !== false) {
			$this->gallery->save_content($options['image'],$options);
		}

		$json = $this->post_to_json();

		if (strpos($json,'permalink') === false) {
			// verify that the blog post contains expected data
			$this->log_error("Malformed blog post data. Not saving...");
			return false;
		}

    	// Save post JSON
    	$rc = file_put_contents($this->target_path(),$json);

		$this->refresh_json();
		$this->generate_rss();
		$this->generate_sitemap();
		return $rc;
	}

	public function delete()
	{
		parent::delete();
		$this->gallery->deleteAll();
		$this->refresh_json();
		$this->generate_rss();
		$this->generate_sitemap();
	}

	public function deleteImage()
	{
		$this->gallery->delete();
		$this->update_gallery();
	}

	public function get_gallery()
	{
		return $this->gallery;
	}

	private function update_gallery()
	{
		// Not so nice hack to redo the gallery images in post file
		$post = json_decode(file_get_contents($this->target_path()));
		$post->gallery = $this->gallery_images();
		file_put_contents($this->target_path(), json_encode($post));
		$this->refresh_json();
	}

	public function reorder_images($old,$new)
	{
		$rc = $this->gallery->reorder_images($old,$new);
		$this->update_gallery();
		return $rc;
	}

	public function blog_featured_image($featured)
	{
		$rc = $this->gallery->update_featured($featured);
		$this->update_gallery();
		return $rc;
	}

	public function update_alt($alt)
	{
		$rc = $this->gallery->update_alt($alt);
		$this->update_gallery();
		return $rc;
	}

	private function refresh_json()
	{
		$this->delete_json();
		$this->process_data();
		$this->generate_rss();
	}

	private function delete_json()
	{
		if (file_exists($this->json_file)) unlink($this->json_file);
	}

	public function toggle_featured()
	{
		$post = $this->get_contents();
		if ($post) {
			$post->featured = !$post->featured;
			if (file_put_contents($this->target_path(),json_encode($post))) {
				$this->delete_json();
				return $post;
			}
		}
		return false;
	}

	public function toggle_draft()
	{
		$post = $this->get_contents();
		if ($post) {
			$post->draft = !$post->draft;
			if (file_put_contents($this->target_path(),json_encode($post))) {
				$this->refresh_json();
				return $post;
			}
		}
		return false;
	}

	public function generate_sitemap()
	{
		if (!isset($this->posts)) $this->process_data();

		$xml = "<?xml version=\"1.0\" encoding=\"UTF-8\"?><urlset xmlns=\"http://www.sitemaps.org/schemas/sitemap/0.9\">\n";

		foreach ($this->filter_posts() as $post) {
  			$xml .= "<url><loc>$this->posturl$post->permalink</loc></url>\n";
		}

		$xml .= "</urlset>";
		file_put_contents($this->sitemap_file,$xml);
	}

	private function rss_default_value($field,$value) {
		if (!empty($value)) { return $value; }
		if (file_exists($this->rss_file)) {
			$xml = simplexml_load_string(file_get_contents($this->rss_file));
			return $xml->channel->$field;
		}
		return '';
	}

	public function generate_rss()
	{
		if (!isset($this->posts)) $this->process_data();

		$feed_path = ltrim(str_replace($this->site_root,"",$this->rss_file),"/");
		$feed_url  = $this->baseurl.$feed_path;

		$feed = new RSS2;
		$feed->setLink($feed_url);
		$feed->setSelfLink($feed_url);

		$feed->setTitle($this->rss_default_value('title',$this->rss_title));
		$feed->setDescription($this->rss_default_value('description',$this->rss_description));

		foreach (array_slice($this->filter_posts(),0,self::MAXFEED) as $post) {
			$content = \Michelf\Markdown::defaultTransform($post->summary);

			// Need to add featured image to RSS feed
			if (isset($post->gallery) && is_array($post->gallery) && count($post->gallery) > 0) {
				$image = $post->gallery[0];
				$content .= "<img src=\"$this->baseurl$image->img\" alt=\"$image->alt\"/>";
			}

			$item = $feed->createNewItem();
			$item->setTitle($post->title);
			$item->setLink("$this->posturl$post->permalink");
			// $item->setAuthor($post->author);
			$item->setDescription($content);
			$item->setId($post->permalink);
			$item->setDate(date(DATE_RSS,$post->timestamp));
			$feed->addItem($item);
		}
		file_put_contents($this->rss_file,$feed->generateFeed());
	}

	private function slim_post($post)
	{
		// Remove EXIF
		for ($i=0; $i < count($post->gallery); $i++) {
			unset($post->gallery[$i]->exif);
		}
		// Remove content and extra content
		unset($post->content);
		unset($post->extra);

		return $post;
	}

	private function build_db()
	{
		$db = array(
			"author"   => array(),
			"category" => array(),
			"history"  => array(),
			"post"     => array(),
			"tag"      => array(),
			"draft"    => array(),
			"featured" => array(),
			"version"  => $this->dbversion
		);

		foreach ($this->posts as $post) {
			// Stopped using this. It seemed to be much slower. Now slim_post()
			// $summary = $this->post_summary($post);
			// $db["post"][$post->permalink] = $summary;

			$db["post"][$post->permalink] = $this->slim_post($post);

			$year = date("Y",$post->timestamp);
			$month = date("m",$post->timestamp);
			$db["history"][$year][$month][] = $post->permalink;

			if ($post->draft) $db["draft"][] = $post->permalink;
			if ($post->featured) $db["featured"][] = $post->permalink;

			$authorId = $this->urlify_string($post->author);
			if (!empty($authorId)) $db["author"][$authorId][] = $post->permalink;

			foreach ($post->categories as $category) {
				$key = $this->urlify_string($category);
				if (!empty($key)) $db["category"][$key][] = $post->permalink;
			}
			foreach ($post->tags as $tag) {
				$key = $this->urlify_string($tag);
				if (!empty($key)) $db["tag"][$key][] = $post->permalink;
			}
		}
		return file_put_contents($this->json_file, json_encode($db));
	}

	public function process_data($id=false)
	{
		if (!file_exists($this->target_dir)) {
			$this->posts = array();
			return false;
		}

		$this->posts = array();

		foreach (new \DirectoryIterator($this->target_dir) as $fileInfo) {
			if ($fileInfo->isDot()) continue;

			$filename = $fileInfo->getFilename();
			if (strpos($filename,'.'.self::EXT) === false) continue;
			if ($filename === '.'.self::EXT) continue;

			$post = json_decode(file_get_contents("$this->target_dir/$filename"));
			if (gettype($post) !== 'object' || empty($post->permalink)) {
				$this->log_message("Warning: Ignoring malformed data in $this->target_dir/$filename");
				continue;
			}

			// remove exif data to save space
			if (is_array($post->gallery)) {
				for ($i=0; $i < count($post->gallery); $i++) {
					unset($post->gallery[$i]->exif);
				}
			}

			$this->posts[] = $post;
		}

		$this->build_db();

		return $this->posts;
	}

	private function filter_attributes($permalinks,$filter)
	{
		$keys = array("author","category","tag");

		foreach ($keys as $key) {
			if (empty($filter[$key])) continue;
			// split search terms by , or |
			$search_terms = preg_split('/(\||,)/',$filter[$key]);;
			$results = array();

			foreach ($search_terms as $term) {
				if (empty($term)) continue;
				$term = $this->urlify_string($term);
				if (isset($this->db->{$key}->{$term})) {
					$results = array_merge($results,$this->db->{$key}->{$term});
				}
			}

			$permalinks = array_filter($permalinks,function($var) use ($results){
				return in_array($var,$results);
			});
		}

		return $permalinks;
	}

	public function list_filter($posts,$filter=array())
	{
		$filter = array_merge(array(
			'featured' => 'with',
			'draft'    => 'hide',
			'date'     => 'all'
		), $filter);

		switch ($filter["draft"]) {
			case 'hide':
				$posts = array_filter($posts, function($permalink){
					return !in_array($permalink,$this->db->draft);
				});
				break;

			case 'only':
				$posts = array_filter($posts, function($permalink){
					return in_array($permalink,$this->db->draft);
				});
				break;
		}
		switch ($filter["featured"]) {
			case 'hide':
				$posts = array_filter($posts, function($permalink){
					return !in_array($permalink,$this->db->featured);
				});
				break;

			case 'only':
				$posts = array_filter($posts, function($permalink){
					return in_array($permalink,$this->db->featured);
				});
				break;
		}
		switch ($filter["date"]) {
			case 'past':
				$posts = array_filter($posts, function($permalink){
					return ($this->db->post->{$permalink}->timestamp <= strtotime('tomorrow midnight'));
				});
				break;

			case 'future':
				$posts = array_filter($posts, function($permalink){
					return ($this->db->post->{$permalink}->timestamp >= strtotime('today midnight'));
				});
				break;
		}

		return $posts;
	}

	public function list_attributes($type,$filter=array(),$acronyms=0,$wordMode=MB_CASE_TITLE)
	{
		$list = [];
		$allowTypes = array("author","category","tag");
		if (!in_array($type,$allowTypes)) {
			$this->log_error("Unable to list attributes for unknown type $type");
			return $list;
		}
		$this->get_post_db();
		if (!is_object($this->db)) return array();

		$tags = array_keys((array) $this->db->{$type});
		sort($tags);
		foreach($tags as $tag) { // each tag, category, author

			$posts = $this->list_filter($this->db->{$type}->{$tag},$filter);
			$count = count($posts);

			if ($count === 0) continue; // ignore items with no posts after filter

			$label = mb_convert_case(str_replace("-"," ",$tag), $wordMode, "UTF-8");

			$length = mb_strlen($label,'UTF-8');
			if ($acronyms > 0 && $length > 1 && $length <= $acronyms) {
				$label = mb_strtoupper($label,'UTF-8');
			}
			$list[$tag]['params'] = "$type=$tag";
			$list[$tag]['label'] = $label;
			$list[$tag]['count'] = $count;
		}
		return $list;
	}

	public function list_history($filter=array())
	{
		$list = [];
		$years = array_keys((array) $this->db->history);
		rsort($years);
		foreach($years as $year) {
			$months = array_keys((array) $this->db->history->{$year});
			rsort($months);
			foreach($months as $month) {
				$posts = $this->list_filter($this->db->history->{$year}->{$month},$filter);
				$count = count($posts);

				if ($count === 0) continue; // ignore items with no posts after filter

				$date      = \DateTime::createFromFormat('!m',sprintf("%02d",$month));
				$monthName = $date->format('F');

				$key = $year.$month;
				$list[$key]['params'] = "year=$year&month=$month";
				$list[$key]['label'] = "$monthName $year";
				$list[$key]['count'] = $count;
			}
		}
		return $list;
	}

	public function list_history_years($filter=array())
	{
		$list = [];
		$years = array_keys((array) $this->db->history);
		rsort($years);
		foreach($years as $year) {
			$history = new \RecursiveIteratorIterator(new \RecursiveArrayIterator($this->db->history->{$year}));

			$posts = $this->list_filter(iterator_to_array($history,false),$filter);
			$count = count($posts);

			if ($count === 0) continue; // ignore items with no posts after filter

			$list[$year]['params'] = "year=$year";
			$list[$year]['label'] = $year;
			$list[$year]['count'] = $count;
		}
		return $list;
	}

	public function search_posts($query,$filter)
	{
		$this->get_post_db();
		if (!is_object($this->db)) return array();

		$query = $this->urlify_string($query);

		$byPermalink = array();
		$byTitle = array();
		$byAuthor = array();
		$byCategory = array();
		$byTag = array();
		$bySummary = array();

		foreach ($this->db->post as $post) {
			if (is_array($filter)) {
				// Would be nice if this entire filter logic block used above list_filter() function
				if (!empty($filter['draft'])) {
					// Draft Filter
					if ($filter["draft"] === "hide" && $post->draft) continue;
					if ($filter["draft"] === "only" && !$post->draft) continue;
				}
				if (!empty($filter['featured'])) {
					// Featured Filter
					if ($filter["featured"] === "hide" && $post->featured) continue;
					if ($filter["featured"] === "only" && !$post->featured) continue;
				}
				// Hide Filter
				if (isset($filter["date"])) {
					if ($filter["date"] === "past") {
						// Always include all posts from today
						if ($post->timestamp > strtotime('tomorrow midnight')) continue;
					}
					elseif ($filter["date"] === "future") {
						// Always include all posts from today
						if ($post->timestamp < strtotime('today midnight')) continue;
					}
				}
				if (!empty($filter['category'])) {
					if (strpos($this->urlify_string(implode(',',$post->categories)), $this->urlify_string($filter['category'])) === false) continue;
				}
				if (!empty($filter['tag'])) {
					if (strpos($this->urlify_string(implode(',',$post->tags)), $this->urlify_string($filter['tag'])) === false) continue;
				}
				if (!empty($filter['author'])) {
					if (strpos($this->urlify_string($post->author), $this->urlify_string($filter['author'])) === false) continue;
				}
			}
		    if     (strpos($this->urlify_string($post->permalink),$query) !== false){ $byPermalink[] = $post; }
		    elseif (strpos($this->urlify_string($post->title),$query) !== false){ $byTitle[] = $post; }
		    elseif (strpos($this->urlify_string($post->author),$query) !== false){ $byAuthor[] = $post; }
		    elseif (strpos($this->urlify_string(implode(',',$post->categories)),$query) !== false){ $byCategory[] = $post; }
		    elseif (strpos($this->urlify_string(implode(',',$post->tags)),$query) !== false){ $byTag[] = $post; }
		    elseif (strpos($this->urlify_string($post->summary),$query) !== false){ $bySummary[] = $post; }
		}
		return array_merge($byPermalink,$byTitle,$byAuthor,$byCategory,$byTag,$bySummary);
	}

	public function get_all_posts()
	{
		$this->get_post_db();
		if (!is_object($this->db)) return array();
		return array_values((array) $this->db->post);
	}

	public function check_db_schema() {
		// compare the json schema version. Delete if its not current
		if (!empty($this->db)){
			if ($this->db->version === $this->dbversion) {
				return true;
			}
			else {
				$this->delete_json();
			}
		}
		return false;
	}

	public function get_post_db()
	{
		if ($this->check_db_schema()) return $this->db;

		# Process the data and create json if it does not exist
		if (!file_exists($this->json_file)) $this->process_data();

		if (!file_exists($this->json_file)) {
			$this->log_message('No blog posts found at '.$this->target_dir);
			$this->db = array();
			return array();
		}
		// Assign the db just in case it did not get assigned
		if (!$this->db) $this->db = json_decode(file_get_contents($this->json_file));

		if ($this->dbversion !== $this->db->version) $this->migrate_db();

		return $this->db;
	}

	public function filter_posts($filter=array())
	{
		if (gettype($filter) === 'string') $filter = json_decode($filter,true);
		// $this->log_message(json_encode($filter));
		$filter = array_merge(array(
			'all'      => false,
			'featured' => 'with',
			'draft'    => 'hide',
			'sort'     => 'new'
		), $filter);

		# Need to implement post return limits and pages

		if (isset($filter['permalink'])) {
			# return just that post details
		}

		$this->get_post_db();
		if (!is_object($this->db)) return array();

		$posts = array();

		if ($filter["all"]) {
			$posts = array_values((array) $this->db->post);
		}
		else {
			// Limit posts by date first
			if (isset($filter["year"])) {
				$history = [];
				if (property_exists($this->db->history,$filter["year"])) {
					if (isset($filter["month"])) {
						$month = sprintf("%02d",$filter["month"]);
						if (property_exists($this->db->history->{$filter["year"]},$month)) {
							$history = $this->db->history->{$filter["year"]}->{$month};
						}
					}
					else {
						$history = $this->db->history->{$filter["year"]};
					}
				}
			}
			else {
				$history = $this->db->history;
			}
			if (empty($history)) { $history = array(); }
			// Flatten the array
			$it = new \RecursiveIteratorIterator(new \RecursiveArrayIterator($history));
			$history = iterator_to_array($it,false);

			// Filter the results
			$permalinks = $this->filter_attributes($history,$filter);

			foreach ($permalinks as $permalink) {
				// Exclude a post
				if (!empty($filter['exclude']) && $filter['exclude'] == $permalink) continue;
				// Draft Filter
				if ($filter["draft"] === "hide" && $this->db->post->$permalink->draft) continue;
				if ($filter["draft"] === "only" && !$this->db->post->$permalink->draft) continue;
				// Featured Filter
				if ($filter["featured"] === "hide" && $this->db->post->$permalink->featured) continue;
				if ($filter["featured"] === "only" && !$this->db->post->$permalink->featured) continue;

				// Hide Filter
				if (isset($filter["date"])) {
					if ($filter["date"] === "past") {
						// Always include all posts from today
						if ($this->db->post->$permalink->timestamp > strtotime('tomorrow midnight')) continue;
					}
					elseif ($filter["date"] === "future") {
						// Always include all posts from today
						if ($this->db->post->$permalink->timestamp < strtotime('today midnight')) continue;
					}
				}

				# Not Filtered... Add Post
				$posts[] = $this->db->post->$permalink;
			}
		}

		// Sort posts
		if(!empty($posts)) {
			// Shuffle Posts
			if ($filter["sort"] === "shuffle") shuffle($posts);
			// Custom Sort Function
			usort($posts, function($a,$b) use (&$filter) {
				// Draft is highest priority
				if ($filter["draft"] === "top" && ($b->draft || $a->draft)) {
					return $b->draft - $a->draft;
				}
				// Featured is next
				if ($filter["featured"] === "top" && ($b->featured || $a->featured)) {
					return $b->featured - $a->featured;
				}
				// Sort by defined field
				switch ($filter["sort"]) {
				    case "abc":
				    	return strcmp($a->title,$b->title);
				    case "zyx":
				    	return strcmp($b->title,$a->title);
				    case "old":
						return $a->timestamp - $b->timestamp;
				    case "shuffle":
						return 0;
				}
				return $b->timestamp - $a->timestamp;
			});
		}

		return $posts;
	}

	public function to_date($timestamp)
	{
		return date('c',$timestamp);
	}

	public function to_data($filter=false)
	{
		return $this->filter_posts($filter);
	}

	public function gallery_images($permalink=false)
	{
		if ($permalink) {
			$options = array("target_dir" => "/gallery/blog/$this->slug/$permalink");
		    $gallery = new Gallery($this->permalink,$options);
		    return $gallery->process_data();
		}
	    return $this->gallery->process_data();
	}
	private function migrate_db()
	{
		$this->log_message("Migrating blog schema for $this->slug");

		foreach (new \DirectoryIterator($this->target_dir) as $fileInfo) {
			if ($fileInfo->isDot()) continue;

			$filename = $fileInfo->getFilename();
			if (strpos($filename,'.'.self::EXT) === false) continue;
			if ($filename === '.'.self::EXT) continue;

			$post_file = "$this->target_dir/$filename";
			$post = json_decode(file_get_contents($post_file));
			if (gettype($post) !== 'object' || empty($post->permalink)) {
				$this->log_message("Warning: Ignoring malformed data in $this->target_dir/$filename");
				continue;
			}

			// Backup file
			$backup_file = "$this->bkp_dir/migrate-v".self::DBVERSION."/$filename";
			$this->make_dir(dirname($backup_file));
	    	if (!copy($post_file,$backup_file)) {
	    		$this->log_error("Could not backup to cms. ".$backup_file);
	    		return false;
	    	}

			// Save new words attribute - v1.4.0
			$post->words = str_word_count($post->content);

			// Save updated post
			file_put_contents($post_file,json_encode($post));
		}
		$this->delete_json();
	}
}