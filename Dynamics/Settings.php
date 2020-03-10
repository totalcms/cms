<?php
namespace Dynamics;

use Dynamics\Dynamics;

class Settings
{
    public $config;
    public $cms_dir;
    public $site_root;
    public $doc_root;
    public $root_offset;
    private $user_config;

    public function __construct()
    {
        // LiteSpeed server hack. SCRIPT_NAME on shared hosting contains domain name
        // This was on A2 hosting. Strip the domain out
        $domain = isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : $_SERVER['SERVER_NAME'];
        $scriptName = preg_replace("/http[s]*:\/\/$domain/", '', $_SERVER['SCRIPT_NAME']);

        // Cannot trust $_SERVER["DOCUMENT_ROOT"] on shared hosting
        $this->doc_root = realpath(preg_replace("!${scriptName}$!", '', $_SERVER['SCRIPT_FILENAME']));

        // Site Root is where the website is published on the server
        if (php_sapi_name() === 'cli') {
            //  Running Local for testing. tcms-data will be inside Library folder
            $this->site_root = preg_replace('/(.*\/Library).+/', '$1', __DIR__);
        } elseif (php_sapi_name() === 'cli-server') {
            //  Running Local for testing. tcms-data will be inside Library folder
            $this->site_root = $_SERVER["DOCUMENT_ROOT"];
        } else {
            // Assuming the this is deployed at /rw_common/plugins/stacks/dynamics
            $this->site_root = preg_replace('/(.*).rw_common.+/', '$1', __DIR__);
        }
        // Find the offset for when site is published in a sub-folder
        $this->root_offset = str_replace($this->doc_root, '', $this->site_root);

        // Read in the default configuation
        $config = require 'config.php';

        // Read in the user defined configuation.
        // This file is a JSON since the API can save things to it.
        $this->user_config = "$this->site_root/.cmsconf";
        if (file_exists($this->user_config)) {
            $localConfig = Dynamics::read($this->user_config);
            $config = \Zend\Stdlib\ArrayUtils::merge($config, $localConfig);
        }

        // Now that we read in the config, we can setup the cms dir and log
        $this->cms_dir  = "$this->site_root/".$config['cms_dir'];
        $config["logger"]["path"] = "$this->cms_dir/totalcms.log";

        // Save the configs
        $this->config = $config;
    }

    public function appConfig(): array
    {
        $config["settings"] = $this->config;
        return $config;
    }

    public function get(string $setting)
    {
        // Nested value support: logger.loglevel
        $value = $this->config;
        $setting_path = explode('.', $setting);
        foreach ($setting_path as $path) {
            $value = $value[$path];
        }
        return $value;
    }

    public function set(string $setting, $value): void
    {
        // Nested value support: logger.loglevel
        $temp = &$this->config;
        $setting_path = explode('.', $setting);
        foreach ($setting_path as $key) {
            $temp = &$temp[$key];
        }
        $temp = $value;
        unset($temp);
    }

    public function save(): int
    {
        return file_put_contents($this->user_config, json_encode($this->config, JSON_PRETTY_PRINT));
    }
}
