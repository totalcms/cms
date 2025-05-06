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