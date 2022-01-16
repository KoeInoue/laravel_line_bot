<?php

namespace App\Services;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Session;
use App\Models\Answer;
use LINE\LINEBot;
use LINE\LINEBot\HTTPClient\CurlHTTPClient;
use LINE\LINEBot\SignatureValidator;
use LINE\LINEBot\Event\PostbackEvent;
use LINE\LINEBot\Event\MessageEvent\TextMessage;
use LINE\LINEBot\MessageBuilder\TextMessageBuilder;
use LINE\LINEBot\TemplateActionBuilder\PostbackTemplateActionBuilder;
use LINE\LINEBot\TemplateActionBuilder\UriTemplateActionBuilder;
use LINE\LINEBot\MessageBuilder\TemplateMessageBuilder;
use LINE\LINEBot\MessageBuilder\TemplateBuilder\ConfirmTemplateBuilder;
use LINE\LINEBot\MessageBuilder\TemplateBuilder\ButtonTemplateBuilder;
use LINE\LINEBot\MessageBuilder\TemplateBuilder\CarouselColumnTemplateBuilder;
use LINE\LINEBot\MessageBuilder\TemplateBuilder\CarouselTemplateBuilder;
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

    /**
    * @var Answer
    */
    protected $answer_model;

    /**
    * @var NewsApi
    */
    protected $newsapi_client;

    public function __construct()
    {
        $this->httpClient = new CurlHTTPClient(config('app.line_channel_access_token'));
        $this->bot = new LINEBot($this->httpClient, ['channelSecret' => config('app.line_channel_secret')]);
        $this->answer_model = new Answer;
        $this->newsapi_client = new NewsApi(config('app.news_api_key'));
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
        // Verify the signature and exclude requests from other than LINE.
        // 署名を検証しLINE以外からのリクエストを受け付けない。
        $this->validateSignature($request);

        // Parse event request to Event objects.
        // リクエストをEventオブジェクトに変換する
        $events = $this->bot->parseEventRequest($request->getContent(), $request->header('x-line-signature'));

        foreach ($events as $event) {
            // Can't reply without reply token
            $reply_token = $event->getReplyToken();
            // LINE message sent when there is an invalid operation.
            $message_builder = new TextMessageBuilder('Invalid operation. 無効な操作です。');
            // Line user id
            $line_user_id = $event->getUserId();

            switch (true){
                // When got text message
                case $event instanceof TextMessage:
                    if ($event->getText() === 'pick news type') { // Only when text is sent with "pick news type"
                        // Reset all answer
                        $this->answer_model->resetStep($line_user_id);
                        // build message will be sent on step 0 (country)
                        $message_builder = $this->buildStep0Msg();

                        $this->answer_model->storeNextStep($line_user_id, 0);
                    }
                    break;
                // Events you receive when you make a choice or select something.
                case $event instanceof PostbackEvent:
                    $postback_answer = $event->getPostbackData();
                    $current_answer = $this->answer_model->latest()->where('answer', '')->first();

                    switch ($current_answer->step) {
                        case 0: // language
                            $this->answer_model->storeAnswer($current_answer, $postback_answer);

                            $this->answer_model->storeNextStep($line_user_id, 1);

                            $message_builder = $this->buildStep1Msg();

                            break;
                        case 1: // country
                            $this->answer_model->storeAnswer($current_answer, $postback_answer);

                            $this->answer_model->storeNextStep($line_user_id, 2);

                            $message_builder = $this->buildStep2Msg();
                            break;
                        case 2: // category(end)
                            $this->answer_model->storeAnswer($current_answer, $postback_answer);

                            $answers = $this->answer_model->where('line_user_id', $line_user_id)->get();

                            $category = $answers->whereStrict('step', 2)->first()->answer;
                            $language = $answers->whereStrict('step', 0)->first()->answer;
                            $country = $answers->whereStrict('step', 1)->first()->answer;
                            $news = $this->newsapi_client->getSources($category, $language, $country);

                            $message_builder = $this->buildResultMsg($news->sources);
                            break;
                        default:
                            # code...
                            break;
                    }

                    break;
                // ADD FRIEND
                // case $event instanceof FollowEvent:
                //     break;
                // LOCASTION
                // case $event instanceof LocationMessage:
                //     break;
                // BLOCK
                // case $event instanceof UnfollowEvent:
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
     * @return void
     * @throws HttpException
    */
    public function validateSignature(Request $request) : void
    {
        $signature = $request->header('x-line-signature');
        if ($signature === null) {
            abort(400);
        }

        $hash = hash_hmac('sha256', $request->getContent(), config('app.line_channel_secret'), true);
        $expect_signature = base64_encode($hash);

        if (!hash_equals($expect_signature, $signature)) {
            abort(400);
        }
    }

    /**
     * Return TemplateMessageBuilder for step0.
     * Step0用のTemplateMessageBuilderを生成する
     * @param void
     * @return TemplateMessageBuilder
    */
    public function buildStep0Msg() : TemplateMessageBuilder
    {
        return new TemplateMessageBuilder(
            "Select Language / 言語選択",
            new ConfirmTemplateBuilder("Select Language / 言語選択", [
                new PostbackTemplateActionBuilder("Engish", "en"),
                new PostbackTemplateActionBuilder("French", "fr"),
            ])
        );
    }

    /**
     * Return TemplateMessageBuilder for step1.
     * Step1用のTemplateMessageBuilderを生成する
     * @param void
     * @return TemplateMessageBuilder
    */
    public function buildStep1Msg() : TemplateMessageBuilder
    {
        return new TemplateMessageBuilder(
            "Which country do you watch the news for?",
            new ButtonTemplateBuilder(
                "Which country do you watch the news for?",
                "Select A Country / 国選択",
                "",
                [
                    new PostbackTemplateActionBuilder("United States", "us"),
                    new PostbackTemplateActionBuilder("Japan", "jp"),
                    new PostbackTemplateActionBuilder("Canada", "ca"),
                ]
            )
        );
    }

    /**
     * Return TemplateMessageBuilder for step2.
     * Step2用のTemplateMessageBuilderを生成する
     * @param void
     * @return TemplateMessageBuilder
    */
    public function buildStep2Msg() : TemplateMessageBuilder
    {
        return new TemplateMessageBuilder(
            "Which category?",
            new ButtonTemplateBuilder(
                "Which category?",
                "Select A Category / カテゴリ選択",
                "",
                [
                    new PostbackTemplateActionBuilder("Business", "business"),
                    new PostbackTemplateActionBuilder("General", "general"),
                    new PostbackTemplateActionBuilder("Science", "science"),
                    new PostbackTemplateActionBuilder("Tech", "technology"),
                ]
            )
        );
    }

    /**
     * Return TemplateMessageBuilder for result.
     * ニュース取得結果のTemplateMessageBuilderを生成する
     * @param array
     * @return TemplateMessageBuilder|TemplateMessageBuilder
    */
    public function buildResultMsg(array $sources) : mixed
    {
        if (empty($sources)) {
            return new TextMessageBuilder('No result / ニュースがありませんでした');
        } else {
            $columns = [];
            // カルーセルの各カラムを生成(5つまで)
            foreach ($sources as $num => $source) {
                if ($num > 4) {
                    break;
                }

                $replacement = [
                    '\\' => $source->url,
                    'http:' => 'https:'
                ];

                $url = str_replace(
                    array_keys($replacement),
                    array_values($replacement),
                    $source->url
                );

                $link = new UriTemplateActionBuilder('See This News', $url);

                $acp = $num * 200 === 0 ? 100 : $num * 200;
                $columns[] = new CarouselColumnTemplateBuilder(
                    $source->name,
                    mb_strimwidth($source->description, 0, 59, "...", 'UTF-8'),
                    "https://placeimg.com/640/$acp/tech",
                    [$link]
                );
            }
            // カラムをカルーセルに組み込む
            $carousel = new CarouselTemplateBuilder($columns, 'square');

            return new TemplateMessageBuilder("News results", $carousel);
        }
    }
}
