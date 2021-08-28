<?php

use TotalCMS\Component\Blog;

class BlogTest extends PHPUnit_Framework_TestCase
{
    protected static $post;

    protected static $alt;

    protected static $rss_title;
    protected static $rss_description;
    protected static $rss_baseurl;

    protected static $categories;
    protected static $tags;
    protected static $draft;
    protected static $featured;
    protected static $author;
    protected static $permalink;
    protected static $title;
    protected static $summary;
    protected static $content;

	public static function setUpBeforeClass()
    {
        $_FILES = array(
            'image' => array(
                'name' => 'landscape.jpg',
                'type' => 'image/jpeg',
                'size' => 2231317,
                'tmp_name' => __DIR__ . '/../../../phpunit-testdata/landscape.jpg',
                'error' => 0
            ),
        );
        self::$alt = 'Total Blog Gallery Image';

        self::$rss_title = 'Total CMS Unit Test Blog';
        self::$rss_description = 'This is a blog feed that will test the Total CMS blog.';

        self::$categories = 'News,RapidWeaver';
        self::$tags = 'love,Stacks,Total CMS';
        self::$draft = false;
        self::$featured = false;
        self::$author = 'Joe Workman';
        self::$permalink = 'test-post';
        self::$title = 'PHP Unit Test Post';
        self::$summary = 'Artisan four dollar toast waistcoat, keffiyeh pop-up DIY roof party.  Whatever meh tote bag, occupy jean shorts direct trade  craft beer. Intelligentsia VHS readymade artisan yr. Everyday carry direct trade  bushwick, polaroid butcher fab church-key pickled craft beer shoreditch cronut vice.';
        self::$content = "Whatever letterpress blog VHS normcore.  Waistcoat venmo DIY food truck locavore ennui.  Bespoke scenester disrupt, neutra celiac tousled whatever offal ethical PBR&amp;B chartreuse tilde forage ennui.  Tofu knausgaard vice, listicle dreamcatcher cornhole post-ironic banjo kogi schlitz semiotics drinking vinegar.  Brooklyn letterpress gentrify plaid street art waistcoat wolf thundercats 90's hoodie heirloom, deep v cornhole typewriter.  Mlkshk tilde mixtape, DIY chicharrones mustache sustainable PBR&amp;B salvia.  Swag thundercats meh, PBR&amp;B try-hard small batch gluten-free brooklyn cray listicle actually williamsburg blog.

Squid skateboard tousled hashtag.  Blue bottle trust fund butcher post-ironic raw denim, tousled lo-fi VHS cardigan chia austin bushwick viral.  Crucifix trust fund four dollar toast, brooklyn post-ironic fixie health goth sriracha leggings plaid williamsburg occupy squid beard.  Hashtag celiac intelligentsia, cred green juice kogi kickstarter 3 wolf moon.  Pinterest vinyl next level, polaroid hella heirloom raw denim lo-fi.  Pour-over XOXO shabby chic venmo disrupt selfies meggings.  Meditation cardigan food truck four dollar toast, vinyl mixtape migas art party.

Gluten-free taxidermy beard, post-ironic mumblecore flexitarian butcher freegan mixtape. Taxidermy master cleanse sriracha marfa, artisan knausgaard chillwave godard pickled YOLO.  Chia fashion axe 90's, four loko yr offal cred seitan echo park cardigan.  Lomo locavore paleo letterpress, etsy fixie church-key.  Seitan post-ironic butcher ethical.  Small batch trust fund shabby chic twee neutra kombucha, dreamcatcher pour-over blue bottle celiac mumblecore forage portland umami thundercats.  Blue bottle trust fund meh hoodie, migas cray 8-bit street art leggings banjo quinoa tilde hammock.
";

        self::$post = new Blog('phpunit',array(
            "categories"      => self::$categories,
            "tags"            => self::$tags,
            "draft"           => self::$draft,
            "author"          => self::$author,
            "title"           => self::$title,
            "permalink"       => self::$permalink,
            "summary"         => self::$summary,
            "content"         => self::$content,
            'rss_title'       => self::$rss_title,
            'rss_description' => self::$rss_description
        ));

        // Save image post
        self::$post->save_content(self::$content,array(
            'image' => $_FILES['image'],
            'alt'   => self::$alt
        ));
    }

    public function testSave()
    {
        // Save post text
        self::$post->save_content(self::$content);
        $this->assertFileExists(self::$post->target_path());

        // Verify contents
        // $this->assertEquals(self::$content,self::$text_only->get_contents());
    }

    /**
     * @group delete
     */
    public function testDelete() {
        // Delete text post and verify
        self::$post->delete();
        $this->assertFileNotExists(self::$post->target_path());
    }

}