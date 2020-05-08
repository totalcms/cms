<?php
namespace Dynamics;

//---------------------------------------------------------------------------------
// Total CMS Preview
//---------------------------------------------------------------------------------
class Preview
{

    public function __construct(string $api)
    {
        $this->api = $api;
    }

    private function query($url)
    {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
        $results = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return $httpCode == 404 ? false : $results;
    }

    public function queryObject(string $collection, string $id)
    {
        $query = "$this->api/collections/$collection/$id";
        // retrun object as an array
        return json_decode($this->query($query), true);
    }
}
