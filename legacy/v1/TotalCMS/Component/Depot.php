<?php
namespace TotalCMS\Component;

//---------------------------------------------------------------------------------
// Depot class
//---------------------------------------------------------------------------------
class Depot extends File
{
	public $files;

	public function __construct($slug,$options=array())
	{
    	$options = array_merge(array(
			'type'     => 'depot',
			'filename' => false
    	), $options);
		$options['set'] = true;

		parent::__construct($slug,$options);

		if ($options['filename'] !== false) {
			$info = pathinfo($options['filename']);
			$name = $info['filename'];
			$ext  = mb_strtolower(pathinfo($options['filename'],\PATHINFO_EXTENSION));
			$this->target_file = preg_replace('/\W+/','-',$name).".".$ext;
		}
	}

	protected function trim_old_backups()
	{
		// // 30 days old
		// $max_age = time() - 30*24*60*60;

		// $fi = new FilesystemIterator($this->bkp_dir,FilesystemIterator::SKIP_DOTS);
		// foreach ($fi as $file) {
		//     if (is_dir($file->getPathname())) continue;
		//     $fileage = filemtime($file->getPathname());
		//     if ($max_age > $fileage) unlink($file->getPathname());
		// }
	}

	public function process_data($sort='alpha')
	{
		$files = array();

		if (file_exists($this->target_dir)) {
			// json cache file found to be slower than jsut using FilesystemIterator
			$fi = new \FilesystemIterator($this->target_dir,\FilesystemIterator::SKIP_DOTS);
			foreach ($fi as $file) {
				$files[] = $file->getFileName();
			}
			switch ($sort) {
			    case 'newest':
					usort($files,function($a,$b){
						$atime = filemtime("$this->target_dir/$a");
						$btime = filemtime("$this->target_dir/$b");
						return $btime - $atime;
					});
			        break;
			    case 'oldest':
					usort($files,function($a,$b){
						$atime = filemtime("$this->target_dir/$a");
						$btime = filemtime("$this->target_dir/$b");
						return $atime - $btime;
					});
			        break;
				case 'zyx':
    				rsort($files, SORT_NATURAL | SORT_FLAG_CASE);
			        break;
			    default:
    				sort($files, SORT_NATURAL | SORT_FLAG_CASE);
			        break;
			}
			$this->files = $files;
		}
		return $files;
	}
}