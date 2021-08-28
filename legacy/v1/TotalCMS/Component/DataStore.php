<?php
namespace TotalCMS\Component;

//---------------------------------------------------------------------------------
// DATASTORE class
//---------------------------------------------------------------------------------
class DataStore extends Component
{

	public function __construct($slug,$options=array())
	{
    	$options = array_merge(array(
			'type' => 'datastore',
			'ext'  => 'csv'
    	), $options);

		parent::__construct($slug,$options);
	}

    public function save_content_to_cms($content,$options=array())
    {
    	if (is_string($content)) $content = array($content);
		$handle = fopen($this->target_path(),"a");
		fputcsv($handle,$content);
		fclose($handle);
    }

    public function bulk_update($content)
    {
    	return file_put_contents($this->target_path(), $content, LOCK_EX);
    }

}
