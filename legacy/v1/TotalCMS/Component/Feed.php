<?php
namespace TotalCMS\Component;

use \FeedWriter\RSS2;

//---------------------------------------------------------------------------------
// News Feed class
//---------------------------------------------------------------------------------
class Feed extends Component
{
    protected $json_file;
    protected $rss_file;

    protected $feed_title;
    protected $feed_description;
    protected $feed_link;
    protected $feed_baseurl;

    public $gallery;
    public $timestamp;
    public $posts;

    public function __construct($slug, $options=array())
    {
        $options = array_merge(array(
            'type'             => 'feed',
            'timestamp'        => false,
            'feed_title'       => 'News Feed',
            'feed_description' => 'News Feed powered by Total CMS for RapidWeaver',
            'feed_link'        => '',
            'feed_baseurl'     => '',
            'uploadname'       => false,
            'image_options'    => array()
        ), $options);

        $options['set'] = true;

        parent::__construct($slug, $options);

        $this->feed_title       = $options['feed_title'];
        $this->feed_description = $options['feed_description'];
        $this->feed_link        = $options['feed_link'];
        $this->feed_baseurl     = $options['feed_baseurl'];

        $this->timestamp = $options['timestamp'] === false ? time() : $options['timestamp'];
        $this->set_filename($this->slug.'-'.$this->timestamp);

        $options['image_options'] = array_merge($options, $options['image_options']);
        $options['image_options']['type']  = 'gallery';
        $options['image_options']['filename'] = 'feed-'.$this->filename;
        $options['image_options']['uploadname'] = $options["uploadname"];
        $gallery_slug = 'feed-'.$this->slug;

        $this->gallery = new Gallery($gallery_slug, $options['image_options']);

        $this->json_file = "$this->target_dir/$this->slug.json";
        $this->rss_file  = "$this->target_dir/$this->slug.rss";
    }

    public function save_content_to_cms($contents, $options=array())
    {
        $options = array_merge(array(
            'strip' => false,
            'image' => false,
            'alt'   => false
        ), $options);

        // Save text
        parent::save_content_to_cms($contents, $options);

        // Save images
        if ($options['image'] !== false) {
            $this->gallery->save_content($options['image'], $options);
        }

        $this->refresh_json();
    }

    public function delete()
    {
        parent::delete();

        $this->gallery->delete();

        // delete json cache
        $this->delete_json();
        $this->process_data();
    }

    public function get_gallery()
    {
        return $this->gallery;
    }

    public function update_alt($alt)
    {
        return $this->gallery->update_alt($alt);

        // delete json cache
        $this->delete_json();
        $this->process_data();
    }

    private function refresh_json()
    {
        $this->delete_json();
        $this->process_data();
    }

    private function delete_json()
    {
        if (file_exists($this->json_file)) {
            unlink($this->json_file);
        }
    }

    private function truncate($string, $length=50, $append="...")
    {
        $string = trim($string);

        if (strlen($string) > $length) {
            $string = wordwrap($string, $length);
            $string = explode("\n", $string, 2);
            $string = $string[0] . $append;
        }
        return $string;
    }

    public function generate_rss()
    {
        if (!isset($this->posts)) {
            return $this->process_data();
        }

        $feed_path = ltrim(str_replace($this->site_root, "", $this->rss_file), "/");
        $feed_url  = $this->feed_baseurl.$feed_path;

        $feed = new RSS2;
        $feed->setTitle($this->feed_title);
        $feed->setLink($feed_url);
        $feed->setSelfLink($feed_url);
        $feed->setDescription($this->feed_description);

        foreach (array_slice($this->posts, 0, self::MAXFEED) as $post) {
            $html_content = \Michelf\MarkdownExtra::defaultTransform($post['content']);
            $title = $this->truncate(str_replace("\r\n", " ", html_entity_decode(strip_tags($html_content))));
            if (isset($post['img'])) {
                $html_content = '<img src="'.$this->feed_baseurl.$post['img'].'" alt="'.$post['alt'].'"/>'.$html_content;
            }
            $item = $feed->createNewItem();
            $item->setTitle($title);
            $item->setLink($this->feed_link);
            $item->setDescription($html_content);
            $item->setId($post['timestamp']);
            $item->setDate($post['timestamp']);
            $feed->addItem($item);
        }
        file_put_contents($this->rss_file, $feed->generateFeed(), LOCK_EX);
    }

    public function process_data($id=false)
    {
        $cms_dir = ltrim(str_replace($this->site_root, "", $this->gallery->target_dir), "/");

        if ($id !== false) {
            // only return a single post
            $date  = date('c', $this->timestamp);
            $this->posts = array();
            $post = array('timestamp'=>$this->timestamp,'content'=>$this->get_contents(),'date'=>$date);

            $gallery = $this->get_gallery();
            if (file_exists($gallery->target_path())) {
                $post['alt'] = $gallery->get_alt();
                $post['img'] = "$cms_dir/".$gallery->target_file;

                foreach ($gallery->get_thumbs() as $thumb) {
                    $post['thumb'][$thumb->suffix] = "$cms_dir/$thumb->target_file";
                }
            }
            array_push($this->posts, $post);
        } else {
            if (file_exists($this->json_file)) {
                $this->posts = json_decode(file_get_contents($this->json_file), true);
            } else {
                $this->make_dir($this->target_dir);

                $this->posts = array();

                if (file_exists($this->target_dir)) {
                    $posts = array();

                    // json cache file found to be slower than jsut using FilesystemIterator
                    $fi = new \FilesystemIterator($this->target_dir, \FilesystemIterator::SKIP_DOTS);
                    foreach ($fi as $file) {
                        if (preg_match('/\d+$/', $file->getBasename('.'.self::EXT), $matches)) {
                            $timestamp = intval($matches[0]);
                            $date      = date('c', $timestamp);

                            $post = new Feed($this->slug, array('timestamp'=>$timestamp));

                            // We need timestamp as the key so that we can sort by it later on
                            $posts[$timestamp] = array('timestamp'=>$timestamp,'content'=>$post->get_contents(),'date'=>$date);

                            if (file_exists($post->gallery->target_path())) {
                                $posts[$timestamp]['alt'] = $post->gallery->get_alt();
                                $posts[$timestamp]['img'] = "$cms_dir/".$post->gallery->target_file;

                                foreach ($post->gallery->get_thumbs() as $thumb) {
                                    $posts[$timestamp]['thumb'][$thumb->suffix] = "$cms_dir/$thumb->target_file";
                                }
                            }
                        }
                    }
                    // sort by tiemstamp but only return the values
                    krsort($posts);
                    $this->posts = array_values($posts);
                }
                file_put_contents($this->json_file, json_encode($this->posts), LOCK_EX);
                $this->generate_rss();
            }
        }
        return $this->posts;
    }
}
