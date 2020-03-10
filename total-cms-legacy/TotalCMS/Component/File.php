<?php
namespace TotalCMS\Component;

//---------------------------------------------------------------------------------
// FILE class
//---------------------------------------------------------------------------------
class File extends Component
{
	protected $file;

	public function __construct($slug,$options=array())
	{
		$options = array_merge(array(
			'type'     => 'file',
			'filename' => false
		), $options);

		parent::__construct($slug,$options);
	}

	public function save_content_to_cms($file,$options=array())
	{
		if (php_sapi_name() === 'cli') {
			//  Running Local for testing
			return copy($file['tmp_name'],$this->target_path());
		}
		return move_uploaded_file($file['tmp_name'],$this->target_path());
	}
}