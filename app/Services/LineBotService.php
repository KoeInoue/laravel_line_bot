<?php

namespace App\Services;

use Illuminate\Http\Request;
use LINE\LINEBot\HTTPClient\CurlHTTPClient;
use LINE\LINEBot;
use LINE\LINEBot\SignatureValidator;
use LINE\LINEBot\Event\MessageEvent\TextMessage;
use LINE\LINEBot\Event\PostbackEvent;
use LINE\LINEBot\MessageBuilder\TextMessageBuilder;
use LINE\LINEBot\TemplateActionBuilder\PostbackTemplateActionBuilder;
use LINE\LINEBot\MessageBuilder\TemplateMessageBuilder;
use LINE\LINEBot\MessageBuilder\TemplateBuilder\ConfirmTemplateBuilder;

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
    public function eventHandler(Request $request) : int
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
        $this->validateSignature($request, $signature);

        $events = $this->bot->parseEventRequest($request->getContent(), $signature);

        foreach ($events as $event) {
            $reply_token = $event->getReplyToken();
            $reply_message = 'Please select menu. メニューから選択してください。';
            $message_builder = new TextMessageBuilder($reply_message);

            switch (true){
                //メッセージの受信
                case $event instanceof TextMessage:
                    $message_builder = new TemplateMessageBuilder(
                        "Please select / 選択してください",
                        // Confirmテンプレートの引数はテキスト、アクションの配列
                        new ConfirmTemplateBuilder("Pick one", [
                            new PostbackTemplateActionBuilder("Yes", "Yes"),
                            new PostbackTemplateActionBuilder("No", "No"),
                        ])
                    );
                    break;
                //選択肢とか選んだ時に受信するイベント
                case $event instanceof PostbackEvent:
                    \Log::debug("postback" + json_encode($event));
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

            $response = $this->bot->replyMessage($reply_token, $message_builder);

            if (!$response->isSucceeded()) {
                \Log::error('Failed!' . $response->getHTTPStatus() . ' ' . $response->getRawBody());
            }
            return $response->getHTTPStatus();
        }
    }

    /**
     * Reply based on the message sent to LINE.
     * LINEに送信されたメッセージをもとに返信する
     *
     * @param Request
     * @param string
     * @return void
     * @throws HttpException
    */
    public function validateSignature(Request $request, string $signature) : void
    {
        if ($signature === null) {
            abort(400);
        }

        $hash = hash_hmac('sha256', $request->getContent(), config('app.line_channel_secret'), true);
        $expect_signature = base64_encode($hash);

        if (!hash_equals($expect_signature, $signature)) {
            abort(400);
        }
    }
}
