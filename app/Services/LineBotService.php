<?php

namespace App\Services;

use Illuminate\Http\Request;
use App\Models\Answer;
use LINE\LINEBot;
use LINE\LINEBot\HTTPClient\CurlHTTPClient;
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
            // Reply token無しでは返信できないため定義しておく
            $reply_token = $event->getReplyToken();
            // LINE message sent when there is an invalid operation.
            // 無効な操作があったときに送るメッセージ
            $message_builder = new TextMessageBuilder('Invalid operation. 無効な操作です。');
            // Line user id
            // アクションした人のLINEのユーザーID
            $line_user_id = $event->getUserId();

            switch (true){
                // When got text message
                // テキストメッセージを受信した場合
                case $event instanceof TextMessage:
                    // Only when text is sent with "pick news type"
                    // "pick news type"と送信された場合
                    if ($event->getText() === 'pick news type') {
                        // Reset all answer
                        // 今までの回答をリセット
                        $this->answer_model->resetStep($line_user_id);
                        // build message will be sent on step 0 (country)
                        // 国選択メッセージを定義
                        $message_builder = $this->buildStep0Msg();

                        // Set a flag to indicate that you have moved on to the next step.
                        // 次のステップに進んだことを示すフラグを立てておく
                        $this->answer_model->storeNextStep($line_user_id, 0);
                    }
                    break;
                // Events you receive when you make a choice or select something.
                // 選択肢を選んだ場合
                case $event instanceof PostbackEvent:
                    // Define the answer
                    // 回答を定義
                    $postback_answer = $event->getPostbackData();
                    // Get an unanswered record.
                    // 未回答のレコードを取得
                    $current_answer = $this->answer_model->latest()->where('answer', '')->first();

                    switch ($current_answer->step) {
                        case 0: // 言語選択時 selected language
                            // Store the answer in DB
                            // 回答をDBに保存
                            $this->answer_model->storeAnswer($current_answer, $postback_answer);

                            // // Set a flag to indicate that you have moved on to the next step.
                            // 次のステップに進んだことを示すフラグを立てておく
                            $this->answer_model->storeNextStep($line_user_id, 1);

                            // Generate next step message
                            // 次のメッセージを生成する
                            $message_builder = $this->buildStep1Msg();

                            break;
                        case 1: // 国選択時 selected country
                            $this->answer_model->storeAnswer($current_answer, $postback_answer);

                            $this->answer_model->storeNextStep($line_user_id, 2);

                            $message_builder = $this->buildStep2Msg();
                            break;
                        case 2: // カテゴリ選択時 selected category(end)
                            $this->answer_model->storeAnswer($current_answer, $postback_answer);

                            // Get answer of step0 ~ 2
                            // Step 0 ~ 2までの回答を取得
                            $answers = $this->answer_model->where('line_user_id', $line_user_id)->get();

                            // Define each data
                            // それぞれ定義
                            $category = $answers->whereStrict('step', 2)->first()->answer;
                            $language = $answers->whereStrict('step', 0)->first()->answer;
                            $country = $answers->whereStrict('step', 1)->first()->answer;
                            // Get news
                            // ニュースを取得
                            $news = $this->newsapi_client->getSources($category, $language, $country);

                            // Generate a result message based on the retrieved news.
                            // 取得したニュースを基に結果メッセージを生成
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
                default: // Could not detect event
                    $body = $event->getEventBody();
                    logger()->warning('Unknown event. ['. get_class($event) . ']', compact('body'));
            }

            // Reply to LINE
            // LINEに返信
            $response = $this->bot->replyMessage($reply_token, $message_builder);

            // Logging when sending reply failed.
            // 送信に失敗したらログに吐いておく
            if (!$response->isSucceeded()) {
                \Log::error('Failed!' . $response->getHTTPStatus() . ' ' . $response->getRawBody());
            }

            // return status code
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
        // Actual signature in request header
        // リクエストヘッダーについてくる実際の署名
        $signature = $request->header('x-line-signature');
        if ($signature === null) {
            abort(400);
        }

        // Generate a signature based on the LINE channel secret and request body
        // LINEチャネルシークレットとリクエストボディを基に署名を生成
        $hash = hash_hmac('sha256', $request->getContent(), config('app.line_channel_secret'), true);
        $expect_signature = base64_encode($hash);

        // If the actual signature and the generated signature are the same, verification is OK.
        // 実際の署名と生成した署名が同じであれば検証OK
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
            "Select Language / 言語選択", // チャット一覧に表示される Displayed in the chat list
            new ConfirmTemplateBuilder(
                "Select Language / 言語選択", // title
                [
                    new PostbackTemplateActionBuilder("Engish", "en"), // option
                    new PostbackTemplateActionBuilder("French", "fr"), // option
                ]
            )
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
     * @return TemplateMessageBuilder|TextMessageBuilder
    */
    public function buildResultMsg(array $sources) : mixed
    {
        // In case of no news
        if (empty($sources)) {
            return new TextMessageBuilder('No result / ニュースがありませんでした');
        } else {
            $columns = [];
            // Generate each column of the carousel (up to 5)
            foreach ($sources as $num => $source) {
                if ($num > 4) {
                    break;
                }

                // URL must be start https
                $replacement = [
                    '\\' => $source->url,
                    'http:' => 'https:'
                ];

                // Replace
                $url = str_replace(
                    array_keys($replacement),
                    array_values($replacement),
                    $source->url
                );

                // Define url part
                // URL部分を定義
                $link = new UriTemplateActionBuilder('See This News', $url);
                // Change the aspect ratio appropriately to make each image different.
                // アスペクト比を適当に変えてそれぞれ違う画像にする
                $acp = $num * 200 === 0 ? 100 : $num * 200;
                // Put the items into an array as a Column.
                // アイテムをColumnとして配列に入れておく
                $columns[] = new CarouselColumnTemplateBuilder(
                    $source->name, // Title
                    mb_strimwidth($source->description, 0, 59, "...", 'UTF-8'), // News content up to 59 char
                    "https://placeimg.com/640/$acp/tech", // Thumb image url
                    [$link] // Bottom button
                );
            }

            // Incorporating a column into a carousel
            // カラムをカルーセルに組み込む
            $carousel = new CarouselTemplateBuilder($columns, 'square');

            return new TemplateMessageBuilder("News results", $carousel);
        }
    }
}
