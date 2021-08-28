<?php
namespace TotalCMS\Component;

//---------------------------------------------------------------------------------
// GALLERY class
//---------------------------------------------------------------------------------
class Gallery extends Image
{
    public $json_file;

    public function __construct($slug, $options=array())
    {
        $options = array_merge(array(
            'type'       => 'gallery',
            'ext'        => 'jpg',
            'resize'     => 'auto',
            'quality'    => 85,
            'scale'      => 1500,
            'scale_th'   => 300,
            'scale_sq'   => 300,
            'filename'   => false,
            'uploadname' => false
        ), $options);

        $options['set'] = true;
        $options['ext'] = self::JPG; //hard coded for now to make life easier

        parent::__construct($slug, $options);

        if ($options['filename'] === false) {
            if ($this->uploadname) {
                $this->set_filename($this->urlify_string($this->uploadname));
            } else {
                $this->set_filename($this->slug);
            }
            if (file_exists($this->target_path())) {
                // add timestamp if the file exists already
                $this->set_filename($this->filename.'-'.time());
            }
        }

        $this->add_thumb($this->thumb($options));
        $this->add_thumb($this->square($options));

        $this->json_file = "$this->target_dir/_gallery.json";
        $this->alt = new Alt($slug, array(
            'set'        => $this->set,
            'type'       => $this->type,
            'filename'   => $this->filename,
            'target_dir' => $this->target_dir
        ));
    }

    public function deleteAll()
    {
        // This exists for blogs. If you delete a blog post, you need to nuke the gallery
        if (file_exists($this->target_dir)) {
            $fi = new \FilesystemIterator($this->target_dir, \FilesystemIterator::SKIP_DOTS);
            foreach ($fi as $entry) {
                unlink($entry->getPathname());
            }
            rmdir($this->target_dir);
        }
    }

    public function delete()
    {
        if (file_exists($this->target_dir)) {
            parent::delete();

            $images = json_decode(file_get_contents($this->json_file), true);
            for ($i = 0, $size = count($images); $i < $size; ++$i) {
                if ($images[$i]['filename'] == $this->filename) {
                    array_splice($images, $i, 1);
                    break;
                }
            }
            file_put_contents($this->json_file, json_encode($images), LOCK_EX);

            $this->process_data();
        }
    }

    public function update_featured($featured)
    {
        $images = json_decode(file_get_contents($this->json_file), true);
        for ($i = 0, $size = count($images); $i < $size; ++$i) {
            if ($images[$i]['filename'] == $this->filename) {
                $images[$i]['featured'] = $featured;
                break;
            }
        }
        return file_put_contents($this->json_file, json_encode($images), LOCK_EX);
    }

    public function get_image_path($name, $suffix=false)
    {
        if (count($this->images) === 0) {
            if (file_exists($this->json_file)) {
                $this->images = json_decode(file_get_contents($this->json_file), true);
            } else {
                $this->images = [];
            }
        }
        $path = false;
        foreach ($this->images as $image) {
            if ($image['filename'] == $name) {
                $path = $suffix ? $image['thumb'][$suffix] : $image['img'];
                break;
            }
        }
        return $path;
    }

    public function update_alt($alt)
    {
        parent::update_alt($alt);

        $images = json_decode(file_get_contents($this->json_file), true);
        for ($i = 0, $size = count($images); $i < $size; ++$i) {
            if ($images[$i]['filename'] == $this->filename) {
                $images[$i]['alt'] = $alt;
                break;
            }
        }
        file_put_contents($this->json_file, json_encode($images), LOCK_EX);

        $this->process_data();
    }

    public function save_content_to_cms($image, $options=array())
    {
        $options = array_merge(array(
            'alt' => '',
            'alttype' => 'user',
        ), $options);

        $this->process_data();

        parent::save_content_to_cms($image, $options);

        $cms_dir = ltrim(str_replace($this->site_root, "", $this->target_dir), "/");
        $image   = array(
            'img'      => "$cms_dir/$this->target_file",
            'alt'      => $this->get_alt(),
            'date'     => time(),
            'featured' => false,
            'exif'     => $this->exif,
            'link'     => '',
            'filename' => $this->filename,
        );
        foreach ($this->get_thumbs() as $thumb) {
            $image['thumb'][$thumb->suffix] = "$cms_dir/$thumb->target_file";
        }

        $images = $this->images;
        array_unshift($images, $image);
        file_put_contents($this->json_file, json_encode($images), LOCK_EX);
    }

    private function moveElement(&$array, $old, $new)
    {
        $out = array_splice($array, $old, 1);
        array_splice($array, $new, 0, $out);
    }

    public function reorder_images($old, $new)
    {
        $images = json_decode(file_get_contents($this->json_file), true);
        $this->moveElement($images, $old, $new);
        file_put_contents($this->json_file, json_encode($images), LOCK_EX);
        return $images;
    }

    public function process_data($filename=false)
    {
        if ($filename !== false) {
            $this->images = array();
            return parent::process_data();
        } else {
            if (file_exists($this->json_file)) {
                $this->images = json_decode(file_get_contents($this->json_file), true);
            } else {
                $this->make_dir($this->target_dir);

                $images = array();
                $cms_dir = ltrim(str_replace($this->site_root, "", $this->target_dir), "/");

                $fi = new \FilesystemIterator($this->target_dir, \FilesystemIterator::SKIP_DOTS);
                foreach ($fi as $entry) {
                    $extension = $entry->getExtension();
                    if ($extension != self::JPG) {
                        continue;
                    }

                    $basename = $entry->getBasename('.'.$extension);
                    if (preg_match('/-(sq|th)$/', $basename)) {
                        continue;
                    }

                    $image = new Gallery($this->slug, array('filename'=>$basename,'target_dir'=>$this->target_dir));
                    $image->add_thumb($this->thumb());
                    $image->add_thumb($this->square());

                    $data  = array(
                        'img'      => "$cms_dir/$image->target_file",
                        'alt'      => $image->get_alt(),
                        'date'     => filemtime($image->target_path()),
                        'featured' => false,
                        'exif'     => $this->collect_meta_data($image->target_path()),
                        'link'     => '',
                        'filename' => $basename
                    );
                    foreach ($image->get_thumbs() as $thumb) {
                        $data['thumb'][$thumb->suffix] = "$cms_dir/$thumb->target_file";
                    }
                    $images[] = $data;
                }

                usort($images, function ($a, $b) {
                    // sort by date
                    return $a['date'] - $b['date'];
                });

                $this->images = $images;
                file_put_contents($this->json_file, json_encode($images), LOCK_EX);
            }
        }
        return $this->images;
    }
}
