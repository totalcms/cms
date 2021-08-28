<?php
namespace TotalCMS;

//---------------------------------------------------------------------------------
// TotalDB class
//---------------------------------------------------------------------------------
class Cache
{
	// Implement Interface for $ob? CMSAble?

	private $db_file;
	private $db_dir;
	private $db_path;
	private $totalcms;
	public $data;

	function __construct($file,$path,$ob)
	{
		$this->totalcms = $ob;

		$this->db_file = "$file.cmscache";
		$this->db_dir  = "$path";
		$this->db_path = "$this->db_dir/$this->db_file";

		if (file_exists($this->db_path)) {
			// Get the database
			$json = file_get_contents($this->db_path);
			$this->data = json_decode($json);
		}
		elseif($this->count_elements() > 0) {
			$this->totalcms->log_message("Rebulding schema for ".$this->totalcms->slug);
			// If doesn't exists but there are files, rebuild it
			$this->rebuild();
		}
		else {
			// default schema
			$json = $this->totalcms->default_schema();
			$this->data = json_decode($json);
		}
	}

	public function rebuild()
	{
		$json = $this->totalcms->rebuild_schema();
		$this->data = json_decode($json);
		$this->save();
	}

	private function count_elements()
	{
		if (file_exists($this->db_dir)) {
			$fi = new \FilesystemIterator($this->db_dir, \FilesystemIterator::SKIP_DOTS);
			return iterator_count($fi);
		}
		return 0;
	}

	public function save()
	{
		if (file_put_contents($this->db_path,json_encode($this->data), LOCK_EX) === false) {
			$this->totalcms->return_error("Could not write to cmscache file! ".$this->db_path);
		}
		// $this->backup();
	}

    private function backup()
    {
    	$this->totalcms->backup($this->db_file);
    }
}