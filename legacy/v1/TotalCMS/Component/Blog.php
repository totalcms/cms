<?php

namespace TotalCMS\Component;

use FeedWriter\RSS2;

//---------------------------------------------------------------------------------
// Blog class
//---------------------------------------------------------------------------------
class Blog extends Component
{
    const DBVERSION = '20200806';
    public $posturl;

    public $categories;
    public $labels;
    public $tags;
    public $draft;
    public $archived;
    public $author;
    public $genre;
    public $extra;
    public $extra2;
    public $media;
    public $permalink;
    public $title;
    public $summary;
    public $content;
    public $words;
    public $timestamp;
    public $posts;
    protected $json_file;
    protected $rss_file;
    protected $gallery;
    protected $image;

    protected $rss_title;
    protected $rss_description;
    protected $baseurl;
    protected $baseurl_file;
    protected $posturl_file;
    protected $sitemap_file;
    protected $db;
    protected $dbversion;

    public function __construct($slug, $options = [])
    {
        $options = array_merge([
            'type'            => 'blog',
            'labels'          => '',
            'categories'      => '',
            'tags'            => '',
            'featured'        => 'false',
            'draft'           => 'false',
            'archived'        => 'false',
            'author'          => '',
            'genre'           => '',
            'title'           => '',
            'permalink'       => '',
            'dateformat'      => 'm/d/Y',
            'timestamp'       => false,
            'summary'         => '',
            'content'         => '',
            'extra'           => '',
            'extra2'          => '',
            'media'           => '',
            'rss_title'       => '',
            'rss_description' => '',
            'baseurl'         => '',
            'posturl'         => '',
            'image'           => [],
            'gallery'         => [],
        ], $options);

        $options['set'] = true;

        parent::__construct($slug, $options);

        $this->categories = array_filter(array_map(function ($category) {
            return trim($category);
        }, explode(',', $options['categories'])));
        $this->tags = array_filter(array_map(function ($tag) {
            return trim($tag);
        }, explode(',', $options['tags'])));
        $this->labels = array_filter(array_map(function ($label) {
            return trim($label);
        }, explode(',', $options['labels'])));
        $this->archived = ('true' === $options['archived']);
        $this->draft = ('true' === $options['draft']);
        $this->featured = ('true' === $options['featured']);
        $this->author = $options['author'];
        $this->permalink = $this->urlify_string($options['permalink']);
        $this->title = $options['title'];
        $this->summary = $options['summary'];
        $this->content = $options['content'];
        $this->extra = $options['extra'];
        $this->extra2 = $options['extra2'];
        $this->media = $options['media'];
        $this->genre = $options['genre'];
        $this->words = str_word_count($options['content']);

        $this->rss_title = $options['rss_title'];
        $this->rss_description = $options['rss_description'];
        $this->baseurl = $options['baseurl'];
        $this->posturl = $options['posturl'];

        $this->baseurl_file = "{$this->target_dir}/{$this->slug}.baseurl";
        $this->posturl_file = "{$this->target_dir}/{$this->slug}.posturl";

        if (empty($this->baseurl)) {
            if (file_exists($this->baseurl_file)) {
                $this->baseurl = file_get_contents($this->baseurl_file);
            }
        } else {
            $this->make_dir(dirname($this->baseurl_file)); // make the dir just in case
            file_put_contents($this->baseurl_file, $this->baseurl, LOCK_EX);
        }

        if (empty($this->posturl)) {
            if (file_exists($this->posturl_file)) {
                $this->posturl = file_get_contents($this->posturl_file);
            }
        } else {
            if (0 === $this->find_string($this->posturl, 'http')) {
                if (!preg_match('#/$#', $this->posturl)) {
                    $this->posturl .= '/';
                }
            } else {
                $posturl = new \URL\Normalizer($this->baseurl.$this->posturl);
                $this->posturl = $posturl->normalize().'?permalink=';
            }
            $this->make_dir(dirname($this->posturl_file)); // make the dir just in case
            file_put_contents($this->posturl_file, $this->posturl, LOCK_EX);
        }

        $this->timestamp = empty($options['timestamp']) ? time() : intval($options['timestamp']);

        $this->set_filename($this->permalink);

        $options['gallery']['target_dir'] = "/gallery/{$this->type}/{$slug}/{$this->permalink}";
        if (isset($options["uploadname"])) {
            $options['gallery']['uploadname'] = $options["uploadname"];
        }
        if (isset($options["filename"])) {
            $options['gallery']['filename'] = $options["filename"];
        }
        $this->gallery = new Gallery($this->permalink, $options['gallery']);

        $options['image']['target_dir'] = "{$this->target_dir}/{$this->permalink}/image";
        $this->image = new Image($this->permalink, $options['image']);
        $this->image->add_thumb($this->image->thumb($options['image']));
        $this->image->add_thumb($this->image->square($options['image']));

        $this->posts = [];
        $this->db = false;
        $this->dbversion = self::DBVERSION;

        $this->json_file = "{$this->target_dir}/_blog.json";
        $this->rss_file = "{$this->target_dir}/{$this->slug}.rss";
        $this->sitemap_file = "{$this->target_dir}/{$this->slug}-sitemap.xml";

        $this->bkp_dir = $this->cms_dir."/backups/{$this->type}/{$slug}/{$this->permalink}";
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

    public function get_contents($format = false)
    {
        return file_exists($this->target_path()) ? json_decode(file_get_contents($this->target_path())) : false;
    }

    public function post_summary($post = false)
    {
        $obj = false === $post ? $this : $post;

        return [
            'categories' => array_values($obj->categories),
            'labels'     => array_values($obj->labels),
            'tags'       => array_values($obj->tags),
            'gallery'    => array_values($this->gallery_images($obj->permalink)),
            'image'      => $this->image_data(),
            'genre'      => property_exists($obj, 'genre') ? $obj->genre : '',
            'media'      => property_exists($obj, 'media') ? $obj->media : '',
            'archived'   => $obj->archived,
            'draft'      => $obj->draft,
            'featured'   => $obj->featured,
            'author'     => $obj->author,
            'title'      => $obj->title,
            'permalink'  => $obj->permalink,
            'timestamp'  => $obj->timestamp,
            'summary'    => $obj->summary,
            'words'      => $obj->words,
        ];
    }

    public function meta_description($post)
    {
        $summary = str_replace('"', '', strip_tags($post->summary));

        return "<meta name=\"description\" content=\"{$summary}\">";
    }

    public function set_permalink($permalink)
    {
        $this->permalink = $permalink;
        $this->set_filename($this->permalink);
    }

    public function meta_twitter($post, $twitter)
    {
        $summary = str_replace('"', '', strip_tags($post->summary));
        $tags = "<meta name=\"twitter:card\" content=\"summary\">
				<meta name=\"twitter:site\" content=\"{$twitter}\">
				<meta name=\"twitter:url\" content=\"{$this->posturl}{$post->permalink}\">
				<meta name=\"twitter:title\" content=\"{$post->title}\">
				<meta name=\"twitter:description\" content=\"{$summary}\">";


        if (isset($post->image) && is_object($post->image)) {
            $image = $post->image;
            $tags .= "<meta name=\"twitter:image\" content=\"{$this->baseurl}{$image->img}\">";
        } elseif (is_array($post->gallery) && count($post->gallery) > 0) {
            $image = $post->gallery[0];
            $tags .= "<meta name=\"twitter:image\" content=\"{$this->baseurl}{$image->img}\">";
        }

        return $tags;
    }

    public function meta_facebook($post)
    {
        $summary = str_replace('"', '', strip_tags($post->summary));
        $tags = "<meta property=\"og:url\" content=\"{$this->posturl}{$post->permalink}\">
				<meta property=\"og:type\" content=\"article\">
				<meta property=\"og:title\" content=\"{$post->title}\">
				<meta property=\"og:description\" content=\"{$summary}\">";

        if (isset($post->image) && is_object($post->image)) {
            $image = $post->image;
            $tags .= "<meta property=\"og:image\" content=\"{$this->baseurl}{$image->img}\">";
        } elseif (is_array($post->gallery) && count($post->gallery) > 0) {
            $image = $post->gallery[0];
            $tags .= "<meta property=\"og:image\" content=\"{$this->baseurl}{$image->img}\">";
        }

        return $tags;
    }

    public function meta_google($post)
    {
        $summary = str_replace('"', '', strip_tags($post->summary));
        $tags = "<meta itemprop=\"name\" content=\"{$post->title}\">
				<meta itemprop=\"description\" content=\"{$summary}\">";

        if (isset($post->image) && is_object($post->image)) {
            $image = $post->image;
            $tags .= "<meta itemprop=\"image\" content=\"{$this->baseurl}{$image->img}\">";
        } elseif (is_array($post->gallery) && count($post->gallery) > 0) {
            $image = $post->gallery[0];
            $tags .= "<meta itemprop=\"image\" content=\"{$this->baseurl}{$image->img}\">";
        }

        return $tags;
    }

    public function post_to_json()
    {
        $post = $this->post_summary();
        $post['content'] = $this->content;
        $post['extra'] = $this->extra;
        $post['extra2'] = $this->extra2;

        return json_encode($post);
    }

    public function post_summary_to_json()
    {
        return json_encode($this->post_summary());
    }

    public function save_content_to_cms($contents, $options = [])
    {
        if (empty($this->permalink)) {
            $this->log_error('Must define a permalink in order to save a blog post');

            return false;
        }

        // Save images
        if (isset($options['imageType']) && false !== $options['file']) {
            if ($options['imageType'] == 'gallery') {
                $this->gallery->save_content($options['file'], $options['gallery']);
                return $this->update_gallery();
            }
            if ($options['imageType'] == 'image') {
                $this->image->save_content($options['file'], $options['image']);
                return $this->update_image();
            }
        }

        $json = $this->post_to_json();

        if (false === $this->find_string($json, 'permalink')) {
            // verify that the blog post contains expected data
            $this->log_error('Malformed blog post data. Not saving...');

            return false;
        }

        // Save post JSON
        $rc = file_put_contents($this->target_path(), $json, LOCK_EX);

        $this->refresh_json();
        $this->generate_rss();
        $this->generate_sitemap();

        return $rc;
    }

    public function delete()
    {
        parent::delete();
        $this->gallery->deleteAll();
        $this->image->delete();
        $this->refresh_json();
        $this->generate_rss();
        $this->generate_sitemap();
    }

    public function deleteImage()
    {
        $this->image->delete();
        $this->update_image();
    }

    public function deleteGalleryImage()
    {
        $this->gallery->delete();
        $this->update_gallery();
    }

    public function get_gallery()
    {
        return $this->gallery;
    }

    public function reorder_images($old, $new)
    {
        $rc = $this->gallery->reorder_images($old, $new);
        $this->update_gallery();

        return $rc;
    }

    public function blog_featured_image($featured)
    {
        $rc = $this->gallery->update_featured($featured);
        $this->update_gallery();

        return $rc;
    }

    public function update_imageAlt($alt)
    {
        $rc = $this->image->update_alt($alt);
        $this->update_image();

        return $rc;
    }

    public function update_galleryAlt($alt)
    {
        $rc = $this->gallery->update_alt($alt);
        $this->update_gallery();

        return $rc;
    }

    public function toggle_featured()
    {
        $post = $this->get_contents();
        if ($post) {
            $post->featured = !$post->featured;
            if (file_put_contents($this->target_path(), json_encode($post), LOCK_EX)) {
                $this->delete_json();

                return $post;
            }
        }

        return false;
    }

    public function toggle_archived()
    {
        $post = $this->get_contents();
        if ($post) {
            $post->archived = !$post->archived;
            if (file_put_contents($this->target_path(), json_encode($post), LOCK_EX)) {
                $this->refresh_json();

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
            if (file_put_contents($this->target_path(), json_encode($post), LOCK_EX)) {
                $this->refresh_json();

                return $post;
            }
        }

        return false;
    }

    public function generate_sitemap()
    {
        if (!isset($this->posts)) {
            $this->process_data();
        }

        $xml = "<?xml version=\"1.0\" encoding=\"UTF-8\"?><urlset xmlns=\"http://www.sitemaps.org/schemas/sitemap/0.9\">\n";

        foreach ($this->filter_posts() as $post) {
            $xml .= "<url><loc>{$this->posturl}{$post->permalink}</loc></url>\n";
        }

        $xml .= '</urlset>';
        file_put_contents($this->sitemap_file, $xml, LOCK_EX);
    }

    public function generate_rss()
    {
        if (!isset($this->posts)) {
            $this->process_data();
        }

        $feed_path = ltrim(str_replace($this->site_root, '', $this->rss_file), '/');
        $feed_url = $this->baseurl.$feed_path;

        $feed = new RSS2();
        $feed->setLink($feed_url);
        $feed->setSelfLink($feed_url);

        $feed->setTitle($this->rss_default_value('title', $this->rss_title));
        $feed->setDescription($this->rss_default_value('description', $this->rss_description));

        foreach (array_slice($this->filter_posts(), 0, self::MAXFEED) as $post) {
            if ($post->draft || $post->archived) {
                continue;
            }
            $content = \Michelf\MarkdownExtra::defaultTransform($post->summary);

            // Need to add featured image to RSS feed
            if (isset($post->gallery) && is_array($post->gallery) && count($post->gallery) > 0) {
                $image = $post->gallery[0];
                $content .= "<img src=\"{$this->baseurl}{$image->img}\" alt=\"{$image->alt}\"/>";
            }

            $item = $feed->createNewItem();
            $item->setTitle($post->title);
            $item->setLink("{$this->posturl}{$post->permalink}");
            // $item->setAuthor($post->author);
            $item->setDescription($content);
            $item->setId($post->permalink);
            $item->setDate(date(DATE_RSS, $post->timestamp));
            $feed->addItem($item);
        }
        file_put_contents($this->rss_file, $feed->generateFeed(), LOCK_EX);
    }

    public function process_data($id = false)
    {
        if (!file_exists($this->target_dir)) {
            $this->posts = [];

            return false;
        }

        $this->posts = [];

        foreach (new \DirectoryIterator($this->target_dir) as $fileInfo) {
            if ($fileInfo->isDot()) {
                continue;
            }
            $filename = $fileInfo->getFilename();
            if (false === $this->find_string($filename, '.'.self::EXT)) {
                continue;
            }
            if ($filename === '.'.self::EXT) {
                continue;
            }
            $post = json_decode(file_get_contents("{$this->target_dir}/{$filename}"));
            if ('object' !== gettype($post) || empty($post->permalink)) {
                $this->log_message("Warning: Ignoring malformed data in {$this->target_dir}/{$filename}");

                continue;
            }

            // remove exif data to save space
            if (is_array($post->gallery)) {
                for ($i = 0; $i < count($post->gallery); ++$i) {
                    unset($post->gallery[$i]->exif);
                }
            }

            $this->posts[] = $post;
        }

        $this->build_db();

        return $this->posts;
    }

    public function list_filter($posts, $filter = [])
    {
        $filter = array_merge([
            'featured' => 'with',
            'archived' => 'hide',
            'draft' => 'hide',
            'date' => 'all',
        ], $filter);

        switch ($filter['archived']) {
            case 'hide':
                $posts = array_filter($posts, function ($permalink) {
                    return !in_array($permalink, $this->db->archived);
                });

                break;
            case 'only':
                $posts = array_filter($posts, function ($permalink) {
                    return in_array($permalink, $this->db->archived);
                });

                break;
        }
        switch ($filter['draft']) {
            case 'hide':
                $posts = array_filter($posts, function ($permalink) {
                    return !in_array($permalink, $this->db->draft);
                });

                break;
            case 'only':
                $posts = array_filter($posts, function ($permalink) {
                    return in_array($permalink, $this->db->draft);
                });

                break;
        }
        switch ($filter['featured']) {
            case 'hide':
                $posts = array_filter($posts, function ($permalink) {
                    return !in_array($permalink, $this->db->featured);
                });

                break;
            case 'only':
                $posts = array_filter($posts, function ($permalink) {
                    return in_array($permalink, $this->db->featured);
                });

                break;
        }
        switch ($filter['date']) {
            case 'past':
                $posts = array_filter($posts, function ($permalink) {
                    return $this->db->post->{$permalink}->timestamp <= time();
                    // return $this->db->post->{$permalink}->timestamp <= strtotime('tomorrow midnight');
                });

                break;
            case 'future':
                $posts = array_filter($posts, function ($permalink) {
                    return $this->db->post->{$permalink}->timestamp >= time();
                    // return $this->db->post->{$permalink}->timestamp >= strtotime('today midnight');
                });

                break;
        }
        if (isset($filter['category'])) {
            $category = $filter['category'];
            $posts = array_filter($posts, function ($permalink) use ($category) {
                return in_array($permalink, $this->db->category->{$category} ?? []);
            });
        }
        if (isset($filter['tag'])) {
            $tag = $filter['tag'];
            $posts = array_filter($posts, function ($permalink) use ($tag) {
                return in_array($permalink, $this->db->tag->{$tag} ?? []);
            });
        }
        if (isset($filter['label'])) {
            $label = $filter['label'];
            $posts = array_filter($posts, function ($permalink) use ($label) {
                return in_array($permalink, $this->db->label->{$label} ?? []);
            });
        }
        if (isset($filter['author'])) {
            $author = $filter['author'];
            $posts = array_filter($posts, function ($permalink) use ($author) {
                return in_array($permalink, $this->db->author->{$author} ?? []);
            });
        }
        if (isset($filter['genre'])) {
            $genre = $filter['genre'];
            $posts = array_filter($posts, function ($permalink) use ($genre) {
                return in_array($permalink, $this->db->genre->{$genre} ?? []);
            });
        }

        return $posts;
    }

    public function list_attributes($type, $filter = [], $options = [])
    {
        $options = array_merge([
            'acronyms' => 0,
            'wordMode' => MB_CASE_TITLE,
            'listurl' => '',
        ], $options);

        $this->get_post_db();

        if ('post' == $type) {
            return $this->list_posts($filter);
        }
        if ('history' == $type) {
            return $this->list_history($filter, $options);
        }
        if ('history-year' == $type) {
            return $this->list_history_years($filter, $options);
        }

        $this->get_post_db();
        if (!is_object($this->db)) {
            return [];
        }

        $acronyms = $options['acronyms'];
        $wordMode = $options['wordMode'];
        $listurl = $options['listurl'];

        $types = explode(',', $type.',');
        $type = $types[0];
        $subtype = $types[1];

        $list = [];
        $allowTypes = ['author', 'genre', 'category', 'tag', 'label', 'post'];
        if (!in_array($type, $allowTypes)) {
            $this->log_error("Unable to list attributes for unknown type {$type}");

            return $list;
        }
        if (!empty($subtype) && !in_array($type, $allowTypes)) {
            $this->log_error("Unable to list attributes for unknown subtype {$subtype}");

            return $list;
        }

        $attrs = array_keys((array) $this->db->{$type});
        natsort($attrs);

        // Common words that are defintely not an acronym
        $wordExceptions = ["and", "of", "the", "or", "for", "in", "de", "der", "di", "la", "das", "por", "per", "en", "an", "is", "es", "est"];

        foreach ($attrs as $attr) { // each tag, label, category, author, genre
            $posts = $this->list_filter($this->db->{$type}->{$attr}, $filter);
            $count = count($posts);

            if (0 === $count) {
                continue;
            } // ignore items with no posts after filter
            $label = mb_convert_case(str_replace('-', ' ', $attr), $wordMode, 'UTF-8');

            $length = mb_strlen($label, 'UTF-8');
            if ($acronyms > 0 && $length > 1) {
                if ($length <= $acronyms) {
                    $label = mb_strtoupper($label, 'UTF-8');
                } else {
                    $words = explode(" ", $label);
                    if (count($words) > 1) {
                        $words = array_map(function($word) use ($acronyms) {
                            return mb_strlen($word, 'UTF-8') <= $acronyms ? mb_strtoupper($word, 'UTF-8') : $word;
                        }, $words);
                        $label = implode(" ", $words);
                    }
                }
            }

            // Word exceptions. I do not like this but it’s a bandaid
            foreach ($wordExceptions as $except) {
                $match = " $except ";
                if (strpos(mb_convert_case($label, MB_CASE_LOWER, 'UTF-8'), $match) !== false) {
                    $label = preg_replace("/$match/i", $match, $label);
                }
            }

            $list[$attr]['url'] = "{$listurl}?{$type}={$attr}";
            $list[$attr]['slug'] = $attr;
            $list[$attr]['label'] = $label;
            $list[$attr]['count'] = $count;

            // Process subtype query
            if ($subtype) {
                // duplicate the filter
                $subfilter = $filter;
                $subfilter[$type] = $attr;

                // Get the new list with a new subfilter
                $sublist = $this->list_attributes($subtype, $subfilter, $options);

                if ('post' != $subtype) {
                    // Loop through and append parent type to params URL
                    $sublist = array_map(function ($item) use ($type,$attr) {
                        $item['url'] = $item['url']."&{$type}={$attr}";

                        return $item;
                    }, $sublist);
                }
                // Save it
                $list[$attr]['sub'] = $sublist;
            }
        }

        return $list;
    }

    public function list_posts($filter = [])
    {
        $list = [];
        $posts = $this->filter_posts($filter);
        $maxposts = isset($filter['maxposts']) ? $filter['maxposts'] : 15;
        $posts = array_slice($posts, 0, $maxposts);
        foreach ($posts as $post) {
            $list[$post->permalink]['url'] = $this->posturl.$post->permalink;
            $list[$post->permalink]['label'] = $post->title;
            $list[$post->permalink]['slug'] = $post->permalink;
        }

        return $list;
    }

    public function list_history($filter = [], $options = [])
    {
        $list = [];
        $years = array_keys((array) $this->db->history);
        rsort($years);
        foreach ($years as $year) {
            $months = array_keys((array) $this->db->history->{$year});
            rsort($months);
            foreach ($months as $month) {
                $posts = $this->list_filter($this->db->history->{$year}->{$month}, $filter);
                $count = count($posts);

                if (0 === $count) {
                    continue;
                } // ignore items with no posts after filter
                $date = \DateTime::createFromFormat('!m', sprintf('%02d', $month));
                $monthName = $date->format('F');

                $key = $year.$month;
                $list[$key]['url'] = $options['listurl']."?year={$year}&month={$month}";
                $list[$key]['label'] = "{$monthName} {$year}";
                $list[$key]['count'] = $count;
            }
        }

        return $list;
    }

    public function list_history_years($filter = [], $options = [])
    {
        $list = [];
        $years = array_keys((array) $this->db->history);
        rsort($years);
        foreach ($years as $year) {
            $history = new \RecursiveIteratorIterator(new \RecursiveArrayIterator($this->db->history->{$year}));

            $posts = $this->list_filter(iterator_to_array($history, false), $filter);
            $count = count($posts);

            if (0 === $count) {
                continue;
            } // ignore items with no posts after filter
            $list[$year]['url'] = $options['listurl']."?year={$year}";
            $list[$year]['label'] = $year;
            $list[$year]['count'] = $count;
        }

        return $list;
    }

    public function list_links($list, $options = [])
    {
        $options = array_merge([
            'counter' => false,
            'nolink' => false,
            'wrapper' => true,
            'class' => 'blog-links',
            'subclass' => 'blog-sublinks',
        ], $options);

        $html = $options['wrapper'] ? '<ul class="'.$options['class'].'">' : '';

        foreach ($list as $item) {
            // Ignore attrs that begin with - (dash)
            if (isset($item['slug']) && false !== strpos($item['slug'], '=-')) {
                continue;
            }
            $label = $item['label'];
            $url = $options['nolink'] ? 'javascript:void(0)' : $item['url'];
            // fix issue with adding filters to already filtered url
            $url = str_replace("&?", "&", $url);
            if ($options['counter'] && isset($item['count'])) {
                $count = $item['count'];
                $countLabel = " ({$count})";
                $html .= "<li data-count=\"{$count}\"><a href=\"{$url}\">{$label}{$countLabel}</a>";
            } else {
                $html .= "<li><a href=\"{$url}\">{$label}</a>";
            }

            if (isset($item['sub'])) {
                $suboptions = $options;
                $suboptions['class'] = $options['subclass'];
                $suboptions['nolink'] = false;
                $suboptions['wrapper'] = true;
                $html .= $this->list_links($item['sub'], $suboptions);
            }
            $html .= '</li>';
        }
        $html .= $options['wrapper'] ? '</ul>' : '';

        return $html;
    }

    public function search_posts($query, $filter)
    {
        $posts = $this->get_all_posts();
        $query = $this->urlify_string($query);

        $byPermalink = [];
        $byTitle = [];
        $byGenre = [];
        $byAuthor = [];
        $byCategory = [];
        $byTag = [];
        $byLabel = [];
        $bySummary = [];

        // Sort posts so that the newest posts are first
        usort($posts, function ($a, $b) {
            return $b->timestamp - $a->timestamp;
        });

        foreach ($posts as $post) {
            if (is_array($filter)) {
                // Would be nice if this entire filter logic block used above list_filter() function
                if (!empty($filter['archived'])) {
                    // archived Filter
                    if ('hide' === $filter['archived'] && $post->archived) {
                        continue;
                    }
                    if ('only' === $filter['archived'] && !$post->archived) {
                        continue;
                    }
                }
                if (!empty($filter['draft'])) {
                    // Draft Filter
                    if ('hide' === $filter['draft'] && $post->draft) {
                        continue;
                    }
                    if ('only' === $filter['draft'] && !$post->draft) {
                        continue;
                    }
                }
                if (!empty($filter['featured'])) {
                    // Featured Filter
                    if ('hide' === $filter['featured'] && $post->featured) {
                        continue;
                    }
                    if ('only' === $filter['featured'] && !$post->featured) {
                        continue;
                    }
                }
                // Hide Filter
                if (isset($filter['date'])) {
                    if ('past' === $filter['date']) {
                        // Always include all posts from today
                        // if ($post->timestamp > strtotime('tomorrow midnight')) {
                        if ($post->timestamp > time()) {
                            continue;
                        }
                    } elseif ('future' === $filter['date']) {
                        // Always include all posts from today
                        // if ($post->timestamp < strtotime('today midnight')) {
                        if ($post->timestamp < time()) {
                            continue;
                        }
                    }
                }
                if (!empty($filter['category'])) {
                    if (false === $this->find_string($this->urlify_string(implode(',', $post->categories)), $this->urlify_string($filter['category']))) {
                        continue;
                    }
                }
                if (!empty($filter['tag'])) {
                    if (false === $this->find_string($this->urlify_string(implode(',', $post->tags)), $this->urlify_string($filter['tag']))) {
                        continue;
                    }
                }
                if (!empty($filter['label'])) {
                    if (false === $this->find_string($this->urlify_string(implode(',', $post->labels)), $this->urlify_string($filter['label']))) {
                        continue;
                    }
                }
                if (!empty($filter['author'])) {
                    if (false === $this->find_string($this->urlify_string($post->author), $this->urlify_string($filter['author']))) {
                        continue;
                    }
                }
                if (!empty($filter['genre'])) {
                    if (false === $this->find_string($this->urlify_string($post->genre), $this->urlify_string($filter['genre']))) {
                        continue;
                    }
                }
            }
            if (false !== $this->find_string($this->urlify_string($post->permalink), $query)) {
                $byPermalink[] = $post;
            } elseif (false !== $this->find_string($this->urlify_string($post->title), $query)) {
                $byTitle[] = $post;
            } elseif (false !== $this->find_string($this->urlify_string($post->author), $query)) {
                $byAuthor[] = $post;
            } elseif (false !== $this->find_string($this->urlify_string($post->genre), $query)) {
                $byGenre[] = $post;
            } elseif (false !== $this->find_string($this->urlify_string(implode(',', $post->categories)), $query)) {
                $byCategory[] = $post;
            } elseif (false !== $this->find_string($this->urlify_string(implode(',', $post->tags)), $query)) {
                $byTag[] = $post;
            } elseif (false !== $this->find_string($this->urlify_string(implode(',', $post->labels)), $query)) {
                $byLabel[] = $post;
            } elseif (false !== $this->find_string($this->urlify_string($post->summary), $query)) {
                $bySummary[] = $post;
            }
        }

        return array_merge($byPermalink, $byTitle, $byAuthor, $byGenre, $byCategory, $byTag, $byLabel, $bySummary);
    }

    public function get_all_posts()
    {
        $this->get_post_db();
        if (!is_object($this->db)) {
            return [];
        }

        return array_values((array) $this->db->post);
    }

    public function check_db_schema()
    {
        // compare the json schema version. Delete if its not current
        if (!empty($this->db)) {
            if ($this->db->version === $this->dbversion) {
                return true;
            }

            $this->delete_json();
        }

        return false;
    }

    public function get_post_db()
    {
        if ($this->check_db_schema()) {
            return $this->db;
        }
        // Process the data and create json if it does not exist
        if (!file_exists($this->json_file)) {
            $this->process_data();
        }

        if (!file_exists($this->json_file)) {
            $this->log_message('No blog posts found at '.$this->target_dir);
            $this->db = [];

            return [];
        }
        // Assign the db just in case it did not get assigned
        if (!$this->db) {
            $this->db = json_decode(file_get_contents($this->json_file));
        }

        if ($this->dbversion !== $this->db->version) {
            $this->migrate_db();
        }

        return $this->db;
    }

    public function filter_posts($filter = [])
    {
        if ('string' === gettype($filter)) {
            $filter = json_decode($filter, true);
        }
        // $this->log_message(json_encode($filter));
        $filter = array_merge([
            'all' => false,
            'featured' => 'with',
            'archived' => 'hide',
            'draft' => 'hide',
            'sort' => 'new',
        ], $filter);

        // Need to implement post return limits and pages

        if (isset($filter['permalink'])) {
            // return just that post details
        }

        $this->get_post_db();
        if (!is_object($this->db)) {
            return [];
        }
        $posts = [];

        if ($filter['all']) {
            $posts = array_values((array) $this->db->post);
        } else {
            // Limit posts by date first
            if (isset($filter['year'])) {
                $history = [];
                if (property_exists($this->db->history, $filter['year'])) {
                    if (isset($filter['month'])) {
                        $month = sprintf('%02d', $filter['month']);
                        if (property_exists($this->db->history->{$filter['year']}, $month)) {
                            $history = $this->db->history->{$filter['year']}->{$month};
                        }
                    } else {
                        $history = $this->db->history->{$filter['year']};
                    }
                }
            } else {
                $history = $this->db->history;
            }
            if (empty($history)) {
                $history = [];
            }
            // Flatten the array
            $it = new \RecursiveIteratorIterator(new \RecursiveArrayIterator($history));
            $history = iterator_to_array($it, false);

            // Filter the results
            $permalinks = $this->filter_attributes($history, $filter);

            foreach ($permalinks as $permalink) {
                // Exclude a post
                if (!empty($filter['exclude']) && $filter['exclude'] == $permalink) {
                    continue;
                }
                // archived Filter
                if ('hide' === $filter['archived'] && $this->db->post->{$permalink}->archived) {
                    continue;
                }
                if ('only' === $filter['archived'] && !$this->db->post->{$permalink}->archived) {
                    continue;
                }
                // Draft Filter
                if ('hide' === $filter['draft'] && $this->db->post->{$permalink}->draft) {
                    continue;
                }
                if ('only' === $filter['draft'] && !$this->db->post->{$permalink}->draft) {
                    continue;
                }
                // Featured Filter
                if ('hide' === $filter['featured'] && $this->db->post->{$permalink}->featured) {
                    continue;
                }
                if ('only' === $filter['featured'] && !$this->db->post->{$permalink}->featured) {
                    continue;
                }
                // Hide Filter
                if (isset($filter['date'])) {
                    if ('past' === $filter['date']) {
                        // Always include all posts from today
                        // if ($this->db->post->{$permalink}->timestamp > strtotime('tomorrow midnight')) {
                        if ($this->db->post->{$permalink}->timestamp > time()) {
                            continue;
                        }
                    } elseif ('future' === $filter['date']) {
                        // Always include all posts from today
                        // if ($this->db->post->{$permalink}->timestamp < strtotime('today midnight')) {
                        if ($this->db->post->{$permalink}->timestamp < time()) {
                            continue;
                        }
                    }
                }

                // Not Filtered... Add Post
                $posts[] = $this->db->post->{$permalink};
            }
        }

        // Sort posts
        if (!empty($posts)) {
            // Shuffle Posts
            if ('shuffle' === $filter['sort']) {
                shuffle($posts);
            }
            // Custom Sort Function
            usort($posts, function ($a, $b) use (&$filter) {
                // Draft is highest priority
                if ('top' === $filter['draft'] && ($b->draft || $a->draft)) {
                    return $b->draft - $a->draft;
                }
                // archived is next
                if ('top' === $filter['archived'] && ($b->archived || $a->archived)) {
                    return $b->archived - $a->archived;
                }
                // Featured is next
                if ('top' === $filter['featured'] && ($b->featured || $a->featured)) {
                    return $b->featured - $a->featured;
                }
                // Sort by defined field
                switch ($filter['sort']) {
                    case 'abc':
                        return strcmp($a->title, $b->title);
                    case 'zyx':
                        return strcmp($b->title, $a->title);
                    case 'natural':
                        return strnatcmp($a->title, $b->title);
                    case 'natural-reverse':
                        return strnatcmp($b->title, $a->title);
                    case 'old':
                        return $a->timestamp - $b->timestamp;
                    case 'shuffle':
                        return 0;
                }

                return $b->timestamp - $a->timestamp;
            });
        }

        return $posts;
    }

    public function to_date($timestamp)
    {
        return date('c', $timestamp);
    }

    public function to_data($filter = false)
    {
        return $this->filter_posts($filter);
    }

    public function image_data()
    {
        if (file_exists($this->image->target_path())) {
            return $this->image->process_data()[0];
        }
        return null;
    }

    public function gallery_images($permalink = false)
    {
        if ($permalink) {
            $options = ['target_dir' => "/gallery/blog/{$this->slug}/{$permalink}"];
            $gallery = new Gallery($this->permalink, $options);
            $images = $gallery->process_data();
        } else {
            $images = $this->gallery->process_data();
        }

        return is_array($images) ? $images : [];
    }

    private function update_gallery()
    {
        // Not so nice hack to redo the gallery images in post file
        $post = json_decode(file_get_contents($this->target_path()));
        $post->gallery = $this->gallery_images();
        $rc = file_put_contents($this->target_path(), json_encode($post), LOCK_EX);
        $this->refresh_json();

        return $rc;
    }

    private function update_image()
    {
        // Not so nice hack to redo the gallery images in post file
        $post = json_decode(file_get_contents($this->target_path()));
        $post->image = $this->image_data();
        $rc = file_put_contents($this->target_path(), json_encode($post), LOCK_EX);
        $this->refresh_json();
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
        if (file_exists($this->json_file)) {
            unlink($this->json_file);
        }
    }

    private function rss_default_value($field, $value)
    {
        if (!empty($value)) {
            return $value;
        }
        if (file_exists($this->rss_file)) {
            $xml = simplexml_load_string(file_get_contents($this->rss_file));

            return $xml->channel->{$field};
        }

        return '';
    }

    private function slim_post($post)
    {
        // Remove EXIF
        for ($i = 0; $i < count($post->gallery); ++$i) {
            unset($post->gallery[$i]->exif);
        }
        // Remove content and extra content
        unset($post->content, $post->extra, $post->extra2);

        return $post;
    }

    private function build_db()
    {
        $db = [
            'author' => [],
            'genre' => [],
            'category' => [],
            'history' => [],
            'post' => [],
            'tag' => [],
            'label' => [],
            'draft' => [],
            'archived' => [],
            'featured' => [],
            'version' => $this->dbversion,
        ];

        foreach ($this->posts as $post) {
            // Stopped using this. It seemed to be much slower. Now slim_post()
            // $summary = $this->post_summary($post);
            // $db["post"][$post->permalink] = $summary;

            $db['post'][$post->permalink] = $this->slim_post($post);

            $year = date('Y', $post->timestamp);
            $month = date('m', $post->timestamp);
            $db['history'][$year][$month][] = $post->permalink;

            // This is here for pre-1.7 posts that do not have labels
            if (!isset($post->labels)) {
                $post->labels = [];
            }
            if (!isset($post->archived)) {
                $post->archived = false;
            }
            if (!isset($post->genre)) {
                $post->genre = '';
            }

            if ($post->draft) {
                $db['draft'][] = $post->permalink;
            }
            if ($post->archived) {
                $db['archived'][] = $post->permalink;
            }
            if ($post->featured) {
                $db['featured'][] = $post->permalink;
            }

            $genreId = $this->urlify_string($post->genre);
            if (!empty($genreId)) {
                $db['genre'][$genreId][] = $post->permalink;
            }

            $authorId = $this->urlify_string($post->author);
            if (!empty($authorId)) {
                $db['author'][$authorId][] = $post->permalink;
            }

            foreach ($post->categories as $category) {
                $key = $this->urlify_string($category);
                if (!empty($key)) {
                    $db['category'][$key][] = $post->permalink;
                }
            }
            foreach ($post->tags as $tag) {
                $key = $this->urlify_string($tag);
                if (!empty($key)) {
                    $db['tag'][$key][] = $post->permalink;
                }
            }
            foreach ($post->labels as $label) {
                $key = $this->urlify_string($label);
                if (!empty($key)) {
                    $db['label'][$key][] = $post->permalink;
                }
            }
        }

        return file_put_contents($this->json_file, json_encode($db), LOCK_EX);
    }

    private function filter_attributes($permalinks, $filter)
    {
        $keys = ['author', 'genre', 'category', 'tag', 'label'];

        foreach ($keys as $key) {
            if (empty($filter[$key])) {
                continue;
            }
            // split search terms by , or |
            $search_terms = preg_split('/(\||,)/', $filter[$key]);
            $results = [];

            foreach ($search_terms as $term) {
                if (empty($term)) {
                    continue;
                }
                $term = $this->urlify_string($term);
                if (isset($this->db->{$key}->{$term})) {
                    $results = array_merge($results, $this->db->{$key}->{$term});
                }
            }

            $permalinks = array_filter($permalinks, function ($var) use ($results) {
                return in_array($var, $results);
            });
        }

        return $permalinks;
    }

    private function migrate_db()
    {
        $this->log_message("Migrating blog schema for {$this->slug}");

        foreach (new \DirectoryIterator($this->target_dir) as $fileInfo) {
            if ($fileInfo->isDot()) {
                continue;
            }
            $filename = $fileInfo->getFilename();
            if (false === $this->find_string($filename, '.'.self::EXT)) {
                continue;
            }
            if ($filename === '.'.self::EXT) {
                continue;
            }
            $post_file = "{$this->target_dir}/{$filename}";
            $post = json_decode(file_get_contents($post_file));
            if ('object' !== gettype($post) || empty($post->permalink)) {
                $this->log_message("Warning: Ignoring malformed data in {$this->target_dir}/{$filename}");

                continue;
            }

            // Backup file
            $backup_file = "{$this->bkp_dir}/migrate-v".self::DBVERSION."/{$filename}";
            $this->make_dir(dirname($backup_file));
            if (!copy($post_file, $backup_file)) {
                $this->log_error('Could not backup to cms. '.$backup_file);

                return false;
            }

            // Save new words attribute - v1.4.0
            $post->words = str_word_count($post->content);

            // Save updated post
            file_put_contents($post_file, json_encode($post), LOCK_EX);
        }
        $this->delete_json();
    }
}
