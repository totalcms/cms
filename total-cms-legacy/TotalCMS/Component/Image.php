<?php
namespace TotalCMS\Component;

use Imagine\Image\Metadata\ExifMetadataReader;

//---------------------------------------------------------------------------------
// IMAGE class
//---------------------------------------------------------------------------------
class Image extends Component
{
	// Thumb and Square need to be totalimage objects as well

	protected $image;
	protected $resize;
	protected $quality;
	protected $scale;
	protected $alt;
	protected $thumbs;
	protected $optimize;
	protected $uploadname;
	protected $uploadext;
	protected $pcrop; // portrait crop
	protected $lcrop; // landscape crop
	public    $exif;
	public    $suffix;
	public    $images;

	public function __construct($slug,$options=array())
	{
    	$options = array_merge(array(
			'type'     => 'image',
			'ext'      => 'jpg',
			'resize'   => 'auto',
			'pcrop'    => 'top',
			'lcrop'    => 'center',
			'optimize' => true,
			'quality'  => 85,
			'scale'    => 1500,
			'suffix'   => false,
			'filename'   => false,
			'uploadname' => false,
			'altfile'    => false,
    	), $options);

		parent::__construct($slug,$options);

		$this->suffix   = $options['suffix'];
		$this->resize   = $options['resize'];
		$this->quality  = $options['quality'];
		$this->scale    = $options['scale'];
		$this->pcrop    = $options['pcrop'];
		$this->lcrop    = $options['lcrop'];
		$this->optimize = $options['optimize'] == '0' ? false : true;
		$this->uploadext = $options['ext'];

		if ($options['uploadname']) {
			$info = pathinfo($options['uploadname']);
			$options['uploadname'] = basename($options['uploadname'],'.'.$info['extension']);
			$this->uploadext = $info['extension'];
		}
		$this->uploadname = $options['uploadname'];

		$this->images = array();
		$this->thumbs = array();

		$this->alt = new Alt($slug,array(
			'set'      => $this->set,
			'type'     => $this->type,
			'filename' => $options['altfile'] === false ? $this->filename : $options['altfile']
		));
	}

	public static function square($options=array()) {
		$options = array_merge(array(
			'suffix' => 'sq',
	        'scale_sq' => 100
		), $options);

		$options['scale'] = $options['scale_sq'];
		$options['resize'] = 'crop';

		return $options;
	}

	public static function thumb($options=array()) {
		$options = array_merge(array(
			'suffix'   => 'th',
	        'scale_th' => 100
		), $options);

		$options['scale'] = $options['scale_th'];
		$options['resize'] = 'auto';

		return $options;
	}

	private function calc_string($string)
	{
		$value = intval($string);
		if (preg_match('/(\d+)(?:\s*)([\+\-\*\/])(?:\s*)(\d+)/', $string, $matches) != false){
		    $operator = $matches[2];
		    switch($operator){
		        case '+':
		            $value = $matches[1] + $matches[3];
		            break;
		        case '-':
		            $value = $matches[1] - $matches[3];
		            break;
		        case '*':
		            $value = $matches[1] * $matches[3];
		            break;
		        case '/':
		            $value = $matches[1] / $matches[3];
		            break;
		    }
		}
	    return $value;
	}

	public function collect_meta_data($image)
	{
		$title     = '';
		$caption   = '';
		$copyright = '';
		$width     = 0;
		$height    = 0;

		if (function_exists('getimageSize')) {
			$size = getimageSize($image,$info);
			$width = $size[0];
			$height = $size[1];

			if (function_exists('iptcparse')) {
				if (isset($info["APP13"])) {
					$iptc = iptcparse($info["APP13"]);
					if (is_array($iptc)) {
						$title     = array_key_exists('2#005',$iptc) ? $iptc["2#005"][0] : '';
						$caption   = array_key_exists('2#120',$iptc) ? $iptc["2#120"][0] : '';
						$copyright = array_key_exists('2#116',$iptc) ? $iptc["2#116"][0] : '';
					}
				}
			}
			else {
				$this->log_message("Unable to gather image meta data for alt tag. Is GD and iptcparse installed?");
			}
		}
		else {
			$this->log_message("Unable to gather image meta data for alt tag. Is GD and getimageSize installed?");
		}

		$imagine = new \Imagine\Gd\Imagine();
		$metadata = $imagine->open($image)->metadata();
		unset($imagine); // free up memory as soon as possible

		$this->exif = array(
			"focalLength"  => isset($metadata["exif.FocalLength"])       ? $this->calc_string($metadata["exif.FocalLength"]) : '',
			"aperture"     => isset($metadata["exif.FNumber"])           ? $this->calc_string($metadata["exif.FNumber"]) : '',
			"exposureBias" => isset($metadata["exif.ExposureBiasValue"]) ? $this->calc_string($metadata["exif.ExposureBiasValue"]) : '',
			"shutterSpeed" => isset($metadata["exif.ExposureTime"])      ? $metadata["exif.ExposureTime"] : '',
			"iso"          => isset($metadata["exif.ISOSpeedRatings"])   ? $metadata["exif.ISOSpeedRatings"] : '',
			"date"         => isset($metadata["exif.DateTimeOriginal"])  ? $metadata["exif.DateTimeOriginal"] : '',
			"make"         => isset($metadata["ifd0.Make"])  ? ucwords($metadata["ifd0.Make"])  : '',
			"model"        => isset($metadata["ifd0.Model"]) ? ucwords($metadata["ifd0.Model"]) : '',
			"copyright"    => $copyright,
			"caption"      => $caption,
			"title"    	   => $title,
			"width"    	   => $width,
			"height"       => $height
		);
		return $this->exif;
	}

	public function get_thumbs()
	{
		return $this->thumbs;
	}

	public function get_alt()
	{
		return $this->alt->get_contents();
	}

	public function get_alt_file()
	{
		return $this->alt->target_path();
	}

	public function update_alt($alt)
	{
		return $this->alt->save_content($alt);
	}

	public function add_thumb($options=array())
	{
    	$options = array_merge(array(
			'suffix'   => 'th',
			'type'     => $this->type,
			'ext'      => $this->ext,
			'resize'   => $this->resize,
			'quality'  => $this->quality,
			'set'      => $this->set,
			'scale'    => $this->scale,
			'pcrop'    => $this->pcrop,
			'lcrop'    => $this->lcrop,
			'optimize' => true
    	), $options);

		// Set the filename to have the thumb suffix
		$options['filename'] = $this->filename.'-'.$options['suffix'];
		// Force thumbnails to be optimized
		$options['optimize'] = false;

		$thumb = new Image($this->slug,$options);
		array_push($this->thumbs,$thumb);
	}

	public function delete()
	{
		parent::delete();

		$this->alt->delete();

		foreach ($this->thumbs as $thumb) {
			$thumb->delete();
		}
		return true;
	}

	public function get_images()
	{
		$images = array($this->target_path());
		foreach ($this->thumbs as $thumb) {
			array_push($images, $thumb->target_path());
		}
		return $images;
	}

	public function backup($prefix=false)
	{
		$parent_prefix = parent::backup($prefix);
		foreach ($this->thumbs as $thumb) {
			$thumb->backup($parent_prefix);
		}
		return $parent_prefix;
	}

	private function uploadErrorToMessage($code)
    {
        switch ($code) {
            case UPLOAD_ERR_INI_SIZE:
                $message = "UPLOAD_ERR_INI_SIZE The uploaded file exceeds the upload_max_filesize directive in php.ini";
                break;
            case UPLOAD_ERR_FORM_SIZE:
                $message = "UPLOAD_ERR_FORM_SIZE The uploaded file exceeds the MAX_FILE_SIZE directive that was specified in the HTML form";
                break;
            case UPLOAD_ERR_PARTIAL:
                $message = "UPLOAD_ERR_PARTIAL The uploaded file was only partially uploaded";
                break;
            case UPLOAD_ERR_NO_FILE:
                $message = "UPLOAD_ERR_NO_FILE No file was uploaded";
                break;
            case UPLOAD_ERR_NO_TMP_DIR:
                $message = "UPLOAD_ERR_NO_TMP_DIR Missing a temporary folder";
                break;
            case UPLOAD_ERR_CANT_WRITE:
                $message = "UPLOAD_ERR_CANT_WRITE Failed to write file to disk";
                break;
            case UPLOAD_ERR_EXTENSION:
                $message = "UPLOAD_ERR_EXTENSION File upload stopped by extension";
                break;
            default:
                $message = "Unknown upload error";
                break;
        }
        return $message;
    }

	public function save_content_to_cms($image,$options=array())
	{
    	$options = array_merge(array(
	        'alt' => '',
	        'alttype' => 'user',
    	), $options);

		$this->make_dir($this->tmp_dir);

		// Move uploaded image to tmp_dir for processing
		$tmp_image = $this->tmp_dir.'/'.$this->target_file;

		if (php_sapi_name() === 'cli') {
			//  Running Local for testing
			$rc = copy($image['tmp_name'],$tmp_image);
		}
		else {
			$rc = move_uploaded_file($image['tmp_name'],$tmp_image);
		}

		if (!$rc) {
			$tempfile = sys_get_temp_dir().'/'.$image['tmp_name'];
			$this->log_error("Could not save uploaded image ($tempfile) to cms ($tmp_image): ".$this->uploadErrorToMessage($image['error']));
			return false;
		}

		// Check if the GD extension is loaded and just use the uploaded image if its not
		if (!extension_loaded('gd')) {
			copy($tmp_image, $this->target_path());
			$this->log_error("The GD PHP extension must be installed. Not resizing image.");
			return false;
		}

		if (intval(ini_get('memory_limit')) < 256)	{
			// Setting the memory limit higher for larger images
			// I don't like this but I dont have control of server settings
			ini_set('memory_limit','256M');
		}

		$this->collect_meta_data($tmp_image);

		// Save (default) alt file unless one is there
		if (!file_exists($this->get_alt_file())) {
			if ($options['alttype'] == 'user') {
				$this->alt->save_content($options['alt']);
			}
			else {
				$alt = str_replace('{{filename}}' , $this->uploadname, $options['alt']);
				$alt = str_replace('{{caption}}'  , $this->exif["caption"],   $alt);
				$alt = str_replace('{{title}}'    , $this->exif["title"],     $alt);
				$alt = str_replace('{{copyright}}', $this->exif["copyright"], $alt);
				$this->alt->save_content(trim($alt));
			}
		}
		if ($this->optimize) {
			$this->resize_image($tmp_image);
		}
		else {
			copy($tmp_image, $this->target_path());
		}

		if (!file_exists($this->target_path())) {
			$this->log_error("Unknown error while saving image to ".$this->target_path());
			return false;
		}

		foreach ($this->thumbs as $thumb) {
			$thumb->resize_image($tmp_image);
		}

		if (file_exists($tmp_image)) { unlink($tmp_image); }

		return true;
	}

	protected function resize_image($image_path)
	{
		$rotateVal = 0;

		if (function_exists('exif_imagetype') && exif_imagetype($image_path) == IMAGETYPE_JPEG && file_exists($image_path)) {
			// EXIF only supports JPEG
			try {
				if (function_exists('exif_read_data')) {
					error_reporting(E_ERROR|E_PARSE); // disable exif warnings
					$exif = @exif_read_data($image_path);
					error_reporting(E_ALL);
				}
				else {
					$this->log_message("Warning: exif_read_data() function is not enabled on this server.");
				}
			}
			catch (Exception $e) {
				$this->log_message("Unable to process EXIF data for image ($image_path) $e");
			}
			if (gettype($exif) === 'array' && array_key_exists('Orientation', $exif)) {
				switch($exif['Orientation']) {
				    case 8:
				        $rotateVal = -90;
				        break;
				    case 3:
				        $rotateVal = 180;
				        break;
				    case 6:
				        $rotateVal = 90;
				        break;
				}
			}
		}

		$imagine = new \Imagine\Gd\Imagine();
		$autorotate = new \Imagine\Filter\Basic\Rotate($rotateVal);
		$image = $rotateVal == 0 ? $imagine->open($image_path) : $autorotate->apply($imagine->open($image_path));

		$this->scale_image($image);

		if ($this->resize === 'crop') {
			$this->crop_image($image);
		}

		$image->interlace(\Imagine\Image\ImageInterface::INTERLACE_PLANE);

		return $this->save_image($image);
	}

	protected function scale_image($image)
	{
		$size   = $image->getSize();
		$width  = $size->getWidth();
		$height = $size->getHeight();

		$mode = $this->resize;
		if ($mode === 'auto') {
			$mode = $height > $width ? 'portrait' : 'landscape';
		}
		elseif ($mode === 'crop') {
			$mode = $height > $width ? 'landscape' : 'portrait';
		}

		switch ($mode) {
			case 'portrait':
				if ($height > $this->scale) {
					return $image->resize($size->heighten($this->scale));
				}
				break;
			case 'landscape':
				if ($width > $this->scale) {
					return $image->resize($size->widen($this->scale));
				}
				break;
		}
		return false;
	}

	protected function crop_image($image)
	{
		$size   = $image->getSize();
		$width  = $size->getWidth();
		$height = $size->getHeight();

		$startX = 0;
		$startY = 0;

		$smallest = min($height,$width,$this->scale);

		if ($height > $width) { // portrait
			switch ($this->pcrop) {
			    case "middle":
					$startY = round(($height - $smallest)/2);
			        break;
			    case "bottom":
					$startY = round($height - $smallest);
			        break;
			}
		}
		else { // landscape
			switch ($this->lcrop) {
			    case "center":
					$startX = round(($width - $smallest)/2);
			        break;
			    case "right":
					$startX = round($width - $smallest);
			        break;
			}
		}

		$point = new \Imagine\Image\Point($startX,$startY);
		$box   = new \Imagine\Image\Box($smallest,$smallest);
		return $image->crop($point,$box);
	}

	protected function save_image($image)
	{
		// Default jpeg compression
		$compression_level = array('jpeg_quality' => $this->quality);

		// png compression
		if ($this->ext === 'png') {
			// Scale quality from 0-100 to 0-9
			$scaleQuality = round(($this->quality/100) * 9);
			// Invert quality setting as 0 is best, not 9
			$invertScaleQuality = 9 - $scaleQuality;
			$compression_level = array('png_compression_level' => $invertScaleQuality);
		}

		if ($this->ext === 'jpg' && $this->uploadext === 'png') {
			// add a white backgorund to jpegs in case there were transparent pixels
			$imagine = new \Imagine\Gd\Imagine();
			$palette = new \Imagine\Image\Palette\RGB();
			$white   = $palette->color('#ffffff');
			$canvas  = $imagine->create($image->getSize(),$white);

			$topLeft = new \Imagine\Image\Point(0,0);
			$canvas->paste($image, $topLeft);
			$image = $canvas;
		}

		return $image->save($this->target_path(),$compression_level);
	}

	public function process_data()
	{
		$this->make_dir($this->target_dir);

		$images = array();
		$cms_dir = ltrim(str_replace($this->site_root,"",$this->target_dir),"/");

		$data  = array(
			'img'   => "$cms_dir/$this->target_file",
			'alt'   => $this->get_alt()
		);

		$this->add_thumb($this->thumb());
		$this->add_thumb($this->square());

		foreach ($this->thumbs as $thumb) {
			if (file_exists($thumb->target_path())) {
				$data['thumb'][$thumb->suffix] = "$cms_dir/$thumb->target_file";
			}
		}

		$images[] = $data;
		$this->images = $images;
		return $this->images;
	}
}