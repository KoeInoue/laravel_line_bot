<?php

namespace App\Services;

use Illuminate\Http\Request;
use LINE\LINEBot\HTTPClient\CurlHTTPClient;
use LINE\LINEBot;

class LineBotService
{
    /**
    * @var CurlHTTPClient
    */
    protected $httpClient;

    /**
    * @var LINEBot
    */
    protected $bot;

    public function __construct()
    {
        $this->httpClient = new CurlHTTPClient('<channel access token>');
        $this->bot = new LINEBot($this->httpClient, ['channelSecret' => '<channel secret>']);
    }
}
