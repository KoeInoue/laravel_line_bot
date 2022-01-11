<?php

namespace App\Services;

use Illuminate\Http\Request;
use LINE\LINEBot\HTTPClient\CurlHTTPClient;
use LINE\LINEBot;
use LINE\LINEBot\SignatureValidator;
use LINE\LINEBot\Event\MessageEvent\TextMessage;
use LINE\LINEBot\Event\PostbackEvent;

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
        $this->httpClient = new CurlHTTPClient(config('app.line_channel_access_token'));
        $this->bot = new LINEBot($this->httpClient, ['channelSecret' => config('app.line_channel_secret')]);
    }

    /**
     * Reply based on the message sent to LINE.
     * LINEに送信されたメッセージをもとに返信する
     *
     * @param Request
     * @return int
     * @throws \LINE\LINEBot\Exception\InvalidSignatureException
    */
    public function reply(Request $request)
    {
        // Requestが来たかどうか確認する
        $content = 'Request from LINE';
        $header = $request->header('x-line-signature');
        $param_str = json_encode($request->all());
        $log_message =
        <<<__EOM__
        $content
        $header
        $param_str
        __EOM__;

        \Log::debug($log_message);

        $signature = $request->header('x-line-signature');
        $hash = hash_hmac('sha256', $request->getContent(), config('app.line_channel_secret'), true);
        $expect_signature = base64_encode($hash);

        if (!hash_equals($expect_signature, $signature)) {
            \Log::debug("400 signature");
            abort(400);
        }

        $events = $this->bot->parseEventRequest($request->getContent(), $signature);

        foreach ($events as $event) {
            $reply_token = $event->getReplyToken();
            $reply_message = 'その操作はサポートしてません。.[' . get_class($event) . '][' . $event->getType() . ']';

            switch (true){
                //メッセージの受信
                case $event instanceof TextMessage:
                    \Log::debug('text message');
                    break;
                //選択肢とか選んだ時に受信するイベント
                case $event instanceof PostbackEvent:
                    \Log::debug('postback');
                    break;
                //友達登録＆ブロック解除
                // case $event instanceof LINEBot\Event\FollowEvent:
                //     break;
                // //位置情報の受信
                // case $event instanceof LINEBot\Event\MessageEvent\LocationMessage:
                //     break;
                //ブロック
                // case $event instanceof LINEBot\Event\UnfollowEvent:
                //     break;
                default:
                    $body = $event->getEventBody();
                    logger()->warning('Unknown event. ['. get_class($event) . ']', compact('body'));
            }

            $this->bot->replyText($reply_token, $reply_message);

            return 200;
        }
    }
}
