<?php
namespace TotalCMS;

use TotalCMS\Component\Text;
use TotalCMS\Component\Alt;
use TotalCMS\Component\Image;
use TotalCMS\Component\Gallery;
use TotalCMS\Component\File;
use TotalCMS\Component\Toggle;
use TotalCMS\Component\DataStore;

//---------------------------------------------------------------------------------
// REPLACE class
//---------------------------------------------------------------------------------
class ReplaceText
{
    protected $ext;
    protected $type;
    protected $doc_root;
    protected $site_root;

    public function __construct($type='text', $ext='cms')
    {
        $this->ext  = $ext;
        $this->type = $type;

        // Set this data up first so that the logfile could be used
        if (php_sapi_name() === 'cli') {
            //  Running Local for testing. cms-data will be inside Library folder
            $this->site_root = preg_replace('/(.*\/Library).+/', '$1', __DIR__);
        } else {
            // Assuming the this is deployed at /rw_common/plugins/stacks/total-cms
            $this->site_root = preg_replace('/(.*).rw_common.+/', '$1', __DIR__);
        }
        $this->doc_root = realpath(preg_replace("!${_SERVER['SCRIPT_NAME']}$!", '', $_SERVER['SCRIPT_FILENAME']));

        // if (strpos($this->site_root,$this->doc_root) === false) {
        // 	$this->doc_root = realpath($this->doc_root);
        // }
        $this->root_offset = str_replace($this->doc_root, "", $this->site_root);
    }

    public function replace($buffer, $prefix='')
    {
        if ($this->type === 'text') {
            return $this->totaltext_replace($buffer, $prefix);
        }
        return $buffer;
    }

    private function cmsData($macro, $slug, $buffer)
    {
        $totaltext = new Text($slug);
        $text = $totaltext->get_contents();
        return str_replace($macro, $text, $buffer);
    }

    private function cmsText($macro, $slug, $buffer)
    {
        $totaltext = new Text($slug);
        $text = str_replace("\n", '<br/>', $totaltext->get_contents());
        return str_replace($macro, $text, $buffer);
    }

    private function cmsTextFormat($macro, $slug, $buffer)
    {
        $totaltext = new Text($slug);
        return str_replace($macro, $totaltext->get_contents(true), $buffer);
    }

    private function cmsImageAlt($macro, $slug, $buffer)
    {
        $totalalt = new Alt($slug, array('type'=>'image'));
        return str_replace($macro, $totalalt->get_contents(), $buffer);
    }

    private function cmsImageAltFormat($macro, $slug, $buffer)
    {
        $totalalt = new Alt($slug, array('type'=>'image'));
        return str_replace($macro, $totalalt->get_contents(true), $buffer);
    }

    private function cmsGalleryImageFeaturedAlt($macro, $slug, $buffer)
    {
        $gallery = new Gallery($slug);
        $images = json_decode(file_get_contents($gallery->json_file));
        usort($images, function ($a, $b) {
            return $b->featured - $a->featured;
        });
        $alt = $images[0]->alt;
        return str_replace($macro, $alt, $buffer);
    }

    private function cmsGalleryImageFeaturedAltFormat($macro, $slug, $buffer)
    {
        $gallery = new Gallery($slug);
        $images = json_decode(file_get_contents($gallery->json_file));
        usort($images, function ($a, $b) {
            return $b->featured - $a->featured;
        });
        $alt = $images[0]->alt;
        return str_replace($macro, $gallery->format_text($alt), $buffer);
    }

    private function cmsGalleryImageFirstAlt($macro, $slug, $buffer)
    {
        $gallery = new Gallery($slug);
        $images = json_decode(file_get_contents($gallery->json_file));
        $alt = $images[0]->alt;
        return str_replace($macro, $alt, $buffer);
    }

    private function cmsGalleryImageFirstAltFormat($macro, $slug, $buffer)
    {
        $gallery = new Gallery($slug);
        $images = json_decode(file_get_contents($gallery->json_file));
        $alt = $images[0]->alt;
        return str_replace($macro, $gallery->format_text($alt), $buffer);
    }

    private function cmsGalleryImageLastAlt($macro, $slug, $buffer)
    {
        $gallery = new Gallery($slug);
        $images = json_decode(file_get_contents($gallery->json_file));
        $image = array_pop($images);
        $alt = $image->alt;
        return str_replace($macro, $alt, $buffer);
    }

    private function cmsGalleryImageLastAltFormat($macro, $slug, $buffer)
    {
        $gallery = new Gallery($slug);
        $images = json_decode(file_get_contents($gallery->json_file));
        $image = array_pop($images);
        $alt = $image->alt;
        return str_replace($macro, $gallery->format_text($alt), $buffer);
    }

    private function cmsImage($macro, $slug, $buffer)
    {
        $totalimage = new Image($slug);
        $path = str_replace($this->doc_root, "", $totalimage->target_path());
        return str_replace($macro, $path, $buffer);
    }

    private function cmsImageThumb($macro, $slug, $buffer)
    {
        $totalimage = new Image($slug, array('suffix'=>'th','filename'=>"$slug-th"));
        $path = str_replace($this->doc_root, "", $totalimage->target_path());
        return str_replace($macro, $path, $buffer);
    }

    private function cmsImageSquare($macro, $slug, $buffer)
    {
        $totalimage = new Image($slug, array('suffix'=>'sq','filename'=>"$slug-sq"));
        $path = str_replace($this->doc_root, "", $totalimage->target_path());
        return str_replace($macro, $path, $buffer);
    }

    private function cmsImagePng($macro, $slug, $buffer)
    {
        $totalimage = new Image($slug, array('ext'=>'png'));
        $path = str_replace($this->doc_root, "", $totalimage->target_path());
        return str_replace($macro, $path, $buffer);
    }

    private function cmsImagePngThumb($macro, $slug, $buffer)
    {
        $totalimage = new Image($slug, array('ext'=>'png','suffix'=>'th','filename'=>"$slug-th"));
        $path = str_replace($this->doc_root, "", $totalimage->target_path());
        return str_replace($macro, $path, $buffer);
    }

    private function cmsImagePngSquare($macro, $slug, $buffer)
    {
        $totalimage = new Image($slug, array('ext'=>'png','suffix'=>'sq','filename'=>"$slug-sq"));
        $path = str_replace($this->doc_root, "", $totalimage->target_path());
        return str_replace($macro, $path, $buffer);
    }

    private function cmsGalleryImageFeatured($macro, $slug, $buffer)
    {
        $gallery = new Gallery($slug);
        $images = json_decode(file_get_contents($gallery->json_file));
        usort($images, function ($a, $b) {
            return $b->featured - $a->featured;
        });
        $path = $this->root_offset.'/'.$images[0]->img;
        return str_replace($macro, $path, $buffer);
    }

    private function cmsGalleryImageFeaturedThumb($macro, $slug, $buffer)
    {
        $gallery = new Gallery($slug);
        $images = json_decode(file_get_contents($gallery->json_file));
        usort($images, function ($a, $b) {
            return $b->featured - $a->featured;
        });
        $path = $this->root_offset.'/'.$images[0]->thumb->th;
        return str_replace($macro, $path, $buffer);
    }

    private function cmsGalleryImageFeaturedSquare($macro, $slug, $buffer)
    {
        $gallery = new Gallery($slug);
        $images = json_decode(file_get_contents($gallery->json_file));
        usort($images, function ($a, $b) {
            return $b->featured - $a->featured;
        });
        $path = $this->root_offset.'/'.$images[0]->thumb->sq;
        return str_replace($macro, $path, $buffer);
    }

    private function cmsGalleryImageFirst($macro, $slug, $buffer)
    {
        $gallery = new Gallery($slug);
        $images = json_decode(file_get_contents($gallery->json_file));
        $path = $this->root_offset.'/'.$images[0]->img;
        return str_replace($macro, $path, $buffer);
    }

    private function cmsGalleryImageFirstThumb($macro, $slug, $buffer)
    {
        $gallery = new Gallery($slug);
        $images = json_decode(file_get_contents($gallery->json_file));
        $path = $this->root_offset.'/'.$images[0]->thumb->th;
        return str_replace($macro, $path, $buffer);
    }

    private function cmsGalleryImageFirstSquare($macro, $slug, $buffer)
    {
        $gallery = new Gallery($slug);
        $images = json_decode(file_get_contents($gallery->json_file));
        $path = $this->root_offset.'/'.$images[0]->thumb->sq;
        return str_replace($macro, $path, $buffer);
    }

    private function cmsGalleryImageLast($macro, $slug, $buffer)
    {
        $gallery = new Gallery($slug);
        $images = json_decode(file_get_contents($gallery->json_file));
        $image = array_pop($images);
        $path = $this->root_offset.'/'.$image->img;
        return str_replace($macro, $path, $buffer);
    }

    private function cmsGalleryImageLastThumb($macro, $slug, $buffer)
    {
        $gallery = new Gallery($slug);
        $images = json_decode(file_get_contents($gallery->json_file));
        $image = array_pop($images);
        $path = $this->root_offset.'/'.$image->thumb->th;
        return str_replace($macro, $path, $buffer);
    }

    private function cmsGalleryImageLastSquare($macro, $slug, $buffer)
    {
        $gallery = new Gallery($slug);
        $images = json_decode(file_get_contents($gallery->json_file));
        $image = array_pop($images);
        $path = $this->root_offset.'/'.$image->thumb->sq;
        return str_replace($macro, $path, $buffer);
    }

    private function cmsGalleryImageRandom($macro, $slug, $buffer)
    {
        $gallery = new Gallery($slug);
        $images = json_decode(file_get_contents($gallery->json_file));
        $index = array_rand($images);
        $path = $this->root_offset.'/'.$images[$index]->img;
        return str_replace($macro, $path, $buffer);
    }

    private function cmsGalleryImageRandomThumb($macro, $slug, $buffer)
    {
        $gallery = new Gallery($slug);
        $images = json_decode(file_get_contents($gallery->json_file));
        $index = array_rand($images);
        $path = $this->root_offset.'/'.$images[$index]->thumb->th;
        return str_replace($macro, $path, $buffer);
    }

    private function cmsGalleryImageRandomSquare($macro, $slug, $buffer)
    {
        $gallery = new Gallery($slug);
        $images = json_decode(file_get_contents($gallery->json_file));
        $index = array_rand($images);
        $path = $this->root_offset.'/'.$images[$index]->thumb->sq;
        return str_replace($macro, $path, $buffer);
    }

    private function cmsVideo($macro, $slug, $buffer)
    {
        $video = new Video($slug);
        $code = $video->get_video_embed();
        return str_replace($macro, $code, $buffer);
    }

    private function cmsDataStore($macro, $slug, $buffer)
    {
        $ds = new DataStore($slug);
        $path = str_replace($this->doc_root, "", $ds->target_path());
        return str_replace($macro, $path, $buffer);
    }

    private function cmsDataStoreDownload($macro, $slug, $buffer)
    {
        $download = $this->root_offset."/rw_common/plugins/stacks/total-cms/totaldownload.php?type=datastore&slug=".$slug;
        return str_replace($macro, $download, $buffer);
    }

    private function cmsFile($macro, $filename, $buffer)
    {
        $info = pathinfo($filename);
        $file = new File($info['filename'], array('ext'=>$info['extension']));
        $path = str_replace($this->doc_root, "", $file->target_path());
        return str_replace($macro, $path, $buffer);
    }

    private function cmsFileDownload($macro, $filename, $buffer)
    {
        $info = pathinfo($filename);
        $download = $this->root_offset."/rw_common/plugins/stacks/total-cms/totaldownload.php?type=file&slug=".$info['filename']."&ext=".$info['extension'];
        return str_replace($macro, $download, $buffer);
    }

    private function cmsToggle($macro, $slug, $buffer)
    {
        $toggle = new Toggle($slug);
        $status = $toggle->status() ? 'true' : 'false';
        return str_replace($macro, $status, $buffer);
    }

    private function blogTitle($macro, $post, $buffer)
    {
        return str_replace($macro, $post->title, $buffer);
    }

    private function blogAuthor($macro, $post, $buffer)
    {
        return str_replace($macro, $post->author, $buffer);
    }

    private function blogGenre($macro, $post, $buffer)
    {
        return str_replace($macro, $post->genre, $buffer);
    }

    private function blogContent($macro, $post, $buffer)
    {
        return str_replace($macro, $post->content, $buffer);
    }

    private function blogExtraContent($macro, $post, $buffer)
    {
        return str_replace($macro, $post->extra, $buffer);
    }

    private function blogExtraContent2($macro, $post, $buffer)
    {
        return str_replace($macro, $post->extra2, $buffer);
    }

    private function blogMedia($macro, $post, $buffer)
    {
        return str_replace($macro, $post->media, $buffer);
    }

    private function blogTags($macro, $post, $buffer)
    {
        $tags = array_filter($post->tags, function ($tag) {
            return strpos($tag, '-') !== 0; // ignore -tags
        });
        return str_replace($macro, implode(', ', $tags), $buffer);
    }

    private function blogCategories($macro, $post, $buffer)
    {
        $categories = array_filter($post->categories, function ($category) {
            return strpos($category, '-') !== 0; // ignore -categories
        });
        return str_replace($macro, implode(', ', $categories), $buffer);
    }

    private function blogLabels($macro, $post, $buffer)
    {
        $labels = array_filter($post->labels, function ($label) {
            return strpos($label, '-') !== 0; // ignore -categories
        });
        return str_replace($macro, implode(', ', $labels), $buffer);
    }

    private function blogContentFormat($macro, $post, $buffer)
    {
        $component = new Text('replace');
        return str_replace($macro, $component->format_text($post->content), $buffer);
    }

    private function blogExtraContentFormat($macro, $post, $buffer)
    {
        $component = new Text('replace');
        return str_replace($macro, $component->format_text($post->extra), $buffer);
    }

    private function blogExtraContent2Format($macro, $post, $buffer)
    {
        $component = new Text('replace');
        return str_replace($macro, $component->format_text($post->extra2), $buffer);
    }

    private function blogMediaFormat($macro, $post, $buffer)
    {
        $component = new Text('replace');
        return str_replace($macro, $component->format_text($post->media), $buffer);
    }

    private function blogDateMonth($macro, $post, $buffer)
    {
        return str_replace($macro, date('m', $post->timestamp), $buffer);
    }

    private function blogDateMonthName($macro, $post, $buffer)
    {
        return str_replace($macro, strftime('%B', $post->timestamp), $buffer);
    }

    private function blogDateMonthNameShort($macro, $post, $buffer)
    {
        return str_replace($macro, strftime('%b', $post->timestamp), $buffer);
    }

    private function blogDateDay($macro, $post, $buffer)
    {
        return str_replace($macro, date('d', $post->timestamp), $buffer);
    }

    private function blogDateDayName($macro, $post, $buffer)
    {
        return str_replace($macro, strftime('%A', $post->timestamp), $buffer);
    }

    private function blogDateDayNameShort($macro, $post, $buffer)
    {
        return str_replace($macro, strftime('%a', $post->timestamp), $buffer);
    }

    private function blogDateYear($macro, $post, $buffer)
    {
        return str_replace($macro, date('Y', $post->timestamp), $buffer);
    }

    private function blogDateYearShort($macro, $post, $buffer)
    {
        return str_replace($macro, date('y', $post->timestamp), $buffer);
    }

    private function blogPermalink($macro, $post, $buffer)
    {
        return str_replace($macro, $post->permalink, $buffer);
    }

    private function blogSummary($macro, $post, $buffer)
    {
        return str_replace($macro, $post->summary, $buffer);
    }

    private function blogSummaryFormat($macro, $post, $buffer)
    {
        $component = new Text('replace');
        return str_replace($macro, $component->format_text($post->summary), $buffer);
    }

    private function checkBlogImages($post)
    {
        return (is_array($post->gallery) && count($post->gallery) > 0);
    }

    private function blogImageFirstAlt($macro, $post, $buffer)
    {
        if (!$this->checkBlogImages($post)) {
            return str_replace($macro, '', $buffer);
        }
        return str_replace($macro, $post->gallery[0]->alt, $buffer);
    }

    private function blogImageFirstAltFormat($macro, $post, $buffer)
    {
        if (!$this->checkBlogImages($post)) {
            return str_replace($macro, '', $buffer);
        }
        $component = new Text('replace');
        return str_replace($macro, $component->format_text($post->gallery[0]->alt), $buffer);
    }

    private function blogImageLastAlt($macro, $post, $buffer)
    {
        if (!$this->checkBlogImages($post)) {
            return str_replace($macro, '', $buffer);
        }
        $image = end($post->gallery);
        return str_replace($macro, $image->alt, $buffer);
    }

    private function blogImageLastAltFormat($macro, $post, $buffer)
    {
        if (!$this->checkBlogImages($post)) {
            return str_replace($macro, '', $buffer);
        }
        $component = new Text('replace');
        $image = end($post->gallery);
        return str_replace($macro, $component->format_text($image->alt), $buffer);
    }

    private function blogImageFeaturedAltFormat($macro, $post, $buffer)
    {
        if (!$this->checkBlogImages($post)) {
            return str_replace($macro, '', $buffer);
        }
        $images = $post->gallery;
        usort($images, function ($a, $b) {
            return $b->featured - $a->featured;
        });
        $component = new Text('replace');
        $alt = $images[0]->alt;
        return str_replace($macro, $component->format_text($alt), $buffer);
    }

    private function blogImageFeaturedAlt($macro, $post, $buffer)
    {
        if (!$this->checkBlogImages($post)) {
            return str_replace($macro, '', $buffer);
        }
        $images = $post->gallery;
        usort($images, function ($a, $b) {
            return $b->featured - $a->featured;
        });
        $alt = $images[0]->alt;
        return str_replace($macro, $alt, $buffer);
    }

    private function blogImage($macro, $post, $buffer)
    {
        if (!is_object($post->image)) {
            return str_replace($macro, '', $buffer);
        }
        $path = $this->root_offset.'/'.$post->image->img;
        return str_replace($macro, $path, $buffer);
    }

    private function blogImageAlt($macro, $post, $buffer)
    {
        if (!is_object($post->image)) {
            return str_replace($macro, '', $buffer);
        }
        return str_replace($macro, $post->image->alt, $buffer);
    }

    private function blogImageAltFormat($macro, $post, $buffer)
    {
        if (!is_object($post->image)) {
            return str_replace($macro, '', $buffer);
        }
        $component = new Text('replace');
        return str_replace($macro, $component->format_text($post->image->alt), $buffer);
    }

    private function blogImageThumb($macro, $post, $buffer)
    {
        if (!is_object($post->image)) {
            return str_replace($macro, '', $buffer);
        }
        $path = $this->root_offset.'/'.$post->image->thumb->th;
        return str_replace($macro, $path, $buffer);
    }

    private function blogImageSquare($macro, $post, $buffer)
    {
        if (!is_object($post->image)) {
            return str_replace($macro, '', $buffer);
        }
        $path = $this->root_offset.'/'.$post->image->thumb->sq;
        return str_replace($macro, $path, $buffer);
    }

    private function blogImageFeatured($macro, $post, $buffer)
    {
        if (!$this->checkBlogImages($post)) {
            return str_replace($macro, '', $buffer);
        }
        $images = $post->gallery;
        usort($images, function ($a, $b) {
            return $b->featured - $a->featured;
        });
        $path = $this->root_offset.'/'.$images[0]->img;
        return str_replace($macro, $path, $buffer);
    }

    private function blogImageFeaturedThumb($macro, $post, $buffer)
    {
        if (!$this->checkBlogImages($post)) {
            return str_replace($macro, '', $buffer);
        }
        $images = $post->gallery;
        usort($images, function ($a, $b) {
            return $b->featured - $a->featured;
        });
        $path = $this->root_offset.'/'.$images[0]->thumb->th;
        return str_replace($macro, $path, $buffer);
    }

    private function blogImageFeaturedSquare($macro, $post, $buffer)
    {
        if (!$this->checkBlogImages($post)) {
            return str_replace($macro, '', $buffer);
        }
        $images = $post->gallery;
        usort($images, function ($a, $b) {
            return $b->featured - $a->featured;
        });
        $path = $this->root_offset.'/'.$images[0]->thumb->sq;
        return str_replace($macro, $path, $buffer);
    }

    private function blogImageFirst($macro, $post, $buffer)
    {
        if (!$this->checkBlogImages($post)) {
            return str_replace($macro, '', $buffer);
        }
        $path = $this->root_offset.'/'.$post->gallery[0]->img;
        return str_replace($macro, $path, $buffer);
    }

    private function blogImageFirstThumb($macro, $post, $buffer)
    {
        if (!$this->checkBlogImages($post)) {
            return str_replace($macro, '', $buffer);
        }
        $path = $this->root_offset.'/'.$post->gallery[0]->thumb->th;
        return str_replace($macro, $path, $buffer);
    }

    private function blogImageFirstSquare($macro, $post, $buffer)
    {
        if (!$this->checkBlogImages($post)) {
            return str_replace($macro, '', $buffer);
        }
        $path = $this->root_offset.'/'.$post->gallery[0]->thumb->sq;
        return str_replace($macro, $path, $buffer);
    }

    private function blogImageLast($macro, $post, $buffer)
    {
        if (!$this->checkBlogImages($post)) {
            return str_replace($macro, '', $buffer);
        }
        $image = end($post->gallery);
        $path = $this->root_offset.'/'.$image->img;
        return str_replace($macro, $path, $buffer);
    }

    private function blogImageLastThumb($macro, $post, $buffer)
    {
        if (!$this->checkBlogImages($post)) {
            return str_replace($macro, '', $buffer);
        }
        $image = end($post->gallery);
        $path = $this->root_offset.'/'.$image->thumb->th;
        return str_replace($macro, $path, $buffer);
    }

    private function blogImageLastSquare($macro, $post, $buffer)
    {
        if (!$this->checkBlogImages($post)) {
            return str_replace($macro, '', $buffer);
        }
        $image = end($post->gallery);
        $path = $this->root_offset.'/'.$image->thumb->sq;
        return str_replace($macro, $path, $buffer);
    }

    private function blogImageRandom($macro, $post, $buffer)
    {
        if (!$this->checkBlogImages($post)) {
            return str_replace($macro, '', $buffer);
        }
        $index = array_rand($post->gallery);
        $path = $this->root_offset.'/'.$post->gallery[$index]->img;
        return str_replace($macro, $path, $buffer);
    }

    private function blogImageRandomThumb($macro, $post, $buffer)
    {
        if (!$this->checkBlogImages($post)) {
            return str_replace($macro, '', $buffer);
        }
        $index = array_rand($post->gallery);
        $path = $this->root_offset.'/'.$post->gallery[$index]->thumb->th;
        return str_replace($macro, $path, $buffer);
    }

    private function blogImageRandomSquare($macro, $post, $buffer)
    {
        if (!$this->checkBlogImages($post)) {
            return str_replace($macro, '', $buffer);
        }
        $index = array_rand($post->gallery);
        $path = $this->root_offset.'/'.$post->gallery[$index]->thumb->sq;
        return str_replace($macro, $path, $buffer);
    }

    private function legacy_macros($buffer)
    {
        // Find all of the macros defined on the page
        if (preg_match_all('/\W%(\w+)(\s-(format|alt))*%\W/', $buffer, $matches)) {
            $slugs = array_unique($matches[1]);

            foreach ($slugs as $slug) {
                $totaltext = new Text($slug, array(
                    'type' => $this->type,
                    'ext'  => $this->ext
                ));

                $macro = "%$slug%";
                if (strpos($buffer, $macro) !== false) {
                    $text   = str_replace("\n", '<br/>', $totaltext->get_contents());
                    $buffer = str_replace($macro, $text, $buffer);
                }

                $macro = "%$slug -format%";
                if (strpos($buffer, $macro) !== false) {
                    $buffer = str_replace($macro, $totaltext->get_contents(true), $buffer);
                }

                $macro = "%$slug -alt%";
                if (strpos($buffer, $macro) !== false) {
                    $totalalt = new Alt($slug, array('type'=>'image'));
                    $buffer = str_replace($macro, $totalalt->get_contents(), $buffer);
                }
            }
        }
        return $buffer;
    }

    private function totaltext_replace($buffer, $prefix='')
    {
        $prefix = preg_replace('/\W/', '', $prefix);
        // Find all of the macros defined on the page
        if (preg_match_all('/%(cms\w+)\(\s*(\S+?)\s*\)%/', $buffer, $matches)) {
            $macros = $matches[0];
            $functions = $matches[1];
            $slugs = $matches[2];

            foreach ($functions as $index => $function) {
                if (method_exists($this, $function)) {
                    $buffer = $this->$function($macros[$index], $prefix.$slugs[$index], $buffer);
                }
            }
        }

        // Eventually this needs to go away
        // Turned off in v1.4.0
        // $buffer = $this->legacy_macros($buffer);

        return $buffer;
    }

    public function blog_replace($post, $buffer)
    {
        // Find all of the macros defined on the page
        if (preg_match_all('/%(blog\w+)\(.*?\)%/', $buffer, $matches)) {
            $macros = $matches[0];
            $functions = $matches[1];

            foreach ($functions as $index => $function) {
                if (method_exists($this, $function)) {
                    $buffer = $this->$function($macros[$index], $post, $buffer);
                }
            }
        }
        return $buffer;
    }
}
