<?php

namespace Dynamics;

// This is a helper class that has many useful static fucntions that are used throughout the application
class Dynamics
{
    const CMS_EXT = '.json';
    const DATASTORE_EXT = '.csv';

    public static function makeDir(string $dir) : bool
    {
        if (!file_exists($dir)) {
            return mkdir($dir, 0775, true);
        }
        return true;
    }

    public static function save(string $path, array $data) : array
    {
        if (!file_put_contents($path, json_encode($data, JSON_PRETTY_PRINT|JSON_PRESERVE_ZERO_FRACTION), LOCK_EX)) {
            return array();
        }
        return $data;
    }

    public static function reEncode($data)
    {
        return json_decode(json_encode($data, JSON_PRESERVE_ZERO_FRACTION));
    }

    public static function delete(string $path) : bool
    {
        if (file_exists($path)) {
            if (is_dir($path)) {
                return self::recursiveDelete($path);
            } else {
                return unlink($path);
            }
        }
        return true;
    }

    public static function recursiveDelete($source, $removeOnlyChildren = false) : bool
    {
        if (empty($source) || file_exists($source) === false) {
            return false;
        }
        if (is_file($source) || is_link($source)) {
            return unlink($source);
        }

        $dir_it = new \RecursiveDirectoryIterator($source, \RecursiveDirectoryIterator::SKIP_DOTS);
        $files  = new \RecursiveIteratorIterator($dir_it, \RecursiveIteratorIterator::CHILD_FIRST);

        foreach ($files as $fileinfo) {
            if ($fileinfo->isDir()) {
                if (self::recursiveDelete($fileinfo->getRealPath()) === false) {
                    return false;
                }
            } else {
                if (unlink($fileinfo->getRealPath()) === false) {
                    return false;
                }
            }
        }
        if ($removeOnlyChildren === false) {
            return rmdir($source);
        }
        return true;
    }

    public static function read(string $path, bool $assoc = true)
    {
        if (file_exists($path)) {
            return json_decode(file_get_contents($path), $assoc);
        }
        return [];
    }

    public static function readFile(string $path) : string
    {
        if (file_exists($path)) {
            return file_get_contents($path);
        }
        return "";
    }

    public static function processTemplate(string $template, array $data) : string
    {
        $m = new \Mustache_Engine;
        return $m->render($template, $data);
    }

    public static function objectToArray($object) : array
    {
        // return all public attributes as an array
        return json_decode(json_encode($object, JSON_PRESERVE_ZERO_FRACTION), true);
    }

    public static function objectLinkHref(string $link) : string
    {
        if (strpos($link, "http") === 0) {
            // If the URL starts with http we assume its pretty urls
            if (!preg_match("#/$#", $link)) {
                $link .= "/";
            }
        } else {
            // Add id parameter
            $link = $link."?id=";
        }
        return $link;
    }

    public static function cssGradient($color1, $color2, $offset = 50, $angle = 180) : string
    {
        $red      = $color1["rgb"][0];
        $green    = $color1["rgb"][1];
        $blue     = $color1["rgb"][2];
        $rgb      = "rgb($red,$green,$blue)";

        if ($color2) {
            $red2     = $color1["rgb"][0];
            $green2   = $color1["rgb"][1];
            $blue2    = $color1["rgb"][2];
            $rgbStart = "rgb($red2,$green2,$blue2)";
        } else {
            $redOff   = min($red+$offset, 255);
            $greenOff = min($green+$offset, 255);
            $blueOff  = min($blue+$offset, 255);
            $rgbStart = "rgb($redOff,$greenOff,$blueOff)";
        }
        return "background-color:$rgb;background:linear-gradient({$angle}deg,$rgbStart 0%,$rgb 85%);";
    }

    public static function isAssoc(array $arr) : bool
    {
        if (array() === $arr) {
            return false;
        }
        return array_keys($arr) !== range(0, count($arr) - 1);
    }
}
