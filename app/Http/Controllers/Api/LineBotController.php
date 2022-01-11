<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use App\Services\LineBotService;

class LineBotController extends Controller
{
    /**
    * @var LineBotService
    */
    protected $line_bot_service;

    public function __construct()
    {
        $this->line_bot_service = new LineBotService();
    }

    /**
     * When a message is sent to the official Line account,
     * the API(api/line-bot/reply) is called by LINE Web Hook and this method is called.
     *
     * Lineの公式アカウントにメッセージが送られたときに
     * LINE Web HookにてAPI(api/line-bot/reply)がCallされこのメソッドが呼ばれる
     *
     * @param Request
     * @return Response
    */
    public function reply(Request $request)
    {
        $status_code = $this->line_bot_service->eventHandler($request);

        return response('', $status_code, []);
    }
}
