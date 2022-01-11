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
use jcobhams\NewsApi\NewsApi;

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
            $line_user_id = $event->getUserId();

            switch (true){
                //メッセージの受信
                case $event instanceof TextMessage:
                    if ($event->getText() === 'pick news type') {
                        session()->forget($line_user_id);

                        $message_builder = new TemplateMessageBuilder(
                            "Select Language / 言語選択",
                            new ConfirmTemplateBuilder("Select Language / 言語選択", [
                                new PostbackTemplateActionBuilder("Engish", "en"),
                                new PostbackTemplateActionBuilder("日本語", "jp"),
                            ])
                        );

                        session([$line_user_id => [
                            'step' => 1,
                            'values' => []
                        ]]);
                    }
                    break;
                //選択肢とか選んだ時に受信するイベント
                case $event instanceof PostbackEvent:
                    $answer = $event->getPostbackData();
                    $session = session()->get($line_user_id);
                    \Log::debug(implode( ",", $session));
                    switch ($session) {
                        case 1: // language
                            session()->push("$line_user_id.values.1", $answer);
                            \Log::debug(implode( ",", session()->get($line_user_id)));
                            break;
                        case 2: // country
                            break;
                        case 3: // category(end)
                            $newsapi = new NewsApi($your_api_key);
                            break;
                        default:
                            # code...
                            break;
                    }

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
     * Check LINE Signature
     * LINEの署名確認
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
