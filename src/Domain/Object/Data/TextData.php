<?php

namespace TotalCMS\Domain\Object\Data;

/**
 * Text collection object.
 */
class TextData extends ObjectData
{
    // public function __construct(string $id, array $properties)
    // {
    //     $properties['wordcount'] = self::wordcount($properties['text']);
    //     $properties['charcount'] = self::charcount($properties['text']);

    //     parent::__construct($id, $properties);
    // }

    // public static function wordcount(string $text): int
    // {
    //     $text  = strip_tags($text); // strip HTML
    //     $words = preg_split('/[\s,:?!]+/u', $text, -1, PREG_SPLIT_NO_EMPTY);
    //     return is_array($words) ? sizeof($words) : 0;
    // }

    // public static function charcount(string $text): int
    // {
    //     $text = strip_tags($text); // strip HTML
    //     $text = preg_replace('/\s+/', " ", $text); // replace multiple spaces with a single space
    //     return mb_strlen($text??"");
    // }
}
