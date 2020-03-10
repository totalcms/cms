<?php
namespace Dynamics;

//---------------------------------------------------------------------------------
// Dynamics Conditional Utilities
//---------------------------------------------------------------------------------
class Conditional
{

    public function __construct()
    {
        // new instance
    }

    public function eq($content, $condition) : bool
    {
        return (trim("$content") === trim("$condition"));
    }

    public function ne($content, $condition) : bool
    {
        return (trim("$content") !== trim("$condition"));
    }

    public function gt($content, $condition) : bool
    {
        return (floatval($content) > floatval($condition));
    }

    public function ge($content, $condition) : bool
    {
        return (floatval($content) >= floatval($condition));
    }

    public function lt($content, $condition) : bool
    {
        return (floatval($content) < floatval($condition));
    }

    public function le($content, $condition) : bool
    {
        return (floatval($content) <= floatval($condition));
    }

    public function empty($content, $condition) : bool
    {
        return empty(trim($content));
    }

    public function notEmpty($content, $condition) : bool
    {
        return !$this->empty($content, $condition);
    }

    public function contains($content, $condition) : bool
    {
        return (strpos("$content", trim("$condition")) !== false);
    }

    public function notContains($content, $condition) : bool
    {
        return !$this->contains($content, $condition);
    }

    public function matches($content, $condition) : bool
    {
        return preg_match("$condition", "$content");
    }

    public function notMatches($content, $condition) : bool
    {
        return !$this->matches($content, $condition);
    }

    public function on($content) : bool
    {
        return (boolval($content) === true);
    }

    public function off($content) : bool
    {
        return (boolval($content) === false);
    }
}
