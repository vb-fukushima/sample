<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use LINE\Clients\MessagingApi\Api\MessagingApiApi;
use LINE\Clients\MessagingApi\Model\ReplyMessageRequest;
use LINE\Clients\MessagingApi\Model\TextMessage;
use LINE\Clients\MessagingApi\Model\TemplateMessage;
use LINE\Clients\MessagingApi\Model\ButtonsTemplate;
use LINE\Clients\MessagingApi\Model\CarouselTemplate;
use LINE\Clients\MessagingApi\Model\CarouselColumn;
use LINE\Clients\MessagingApi\Model\MessageAction;
use LINE\Constants\HTTPHeader;
use LINE\Parser\EventRequestParser;
use LINE\Webhook\Model\MessageEvent;
use LINE\Webhook\Model\TextMessageContent;
use LINE\Webhook\Model\FollowEvent;

class LineBotController extends Controller
{
    private MessagingApiApi $bot;
    private string $baseUrl;

    public function __construct()
    {
        $config = new \LINE\Clients\MessagingApi\Configuration();
        $config->setAccessToken(config('services.line.channel_access_token'));
        $client = new \GuzzleHttp\Client();
        $this->bot = new MessagingApiApi($client, $config);
        $this->baseUrl = config('app.url');
    }

    public function webhook(Request $request)
    {
        $channelSecret = config('services.line.channel_secret');
        $signature = $request->header(HTTPHeader::LINE_SIGNATURE);

        if (!$this->validateSignature($request->getContent(), $channelSecret, $signature)) {
            return response('Invalid signature', 400);
        }

        $parsedEvents = EventRequestParser::parseEventRequest(
            $request->getContent(),
            $channelSecret,
            $signature
        );

        foreach ($parsedEvents->getEvents() as $event) {
            if ($event instanceof FollowEvent) {
                $this->handleFollow($event);
            } elseif ($event instanceof MessageEvent && $event->getMessage() instanceof TextMessageContent) {
                $this->handleTextMessage($event);
            }
        }

        return response('OK', 200);
    }

    private function handleFollow(FollowEvent $event): void
    {
        $message = new TextMessage([
            'type' => 'text',
            'text' => "友だち追加ありがとうございます！\n\n「問題社員リスク診断」へようこそ。\n\n下のメニューから「診断開始」をタップしてください。",
        ]);
        $this->replyMessage($event->getReplyToken(), $message);
    }

    private function handleTextMessage(MessageEvent $event): void
    {
        /** @var TextMessageContent $messageContent */
        $text = $event->getMessage()->getText();
        $userId = $event->getSource()->getUserId();

        $message = $this->getReplyMessage($userId, $text);
        $this->replyMessage($event->getReplyToken(), $message);
    }

    private function getReplyMessage(string $userId, string $text): TextMessage|TemplateMessage
    {
        $session = cache()->get("diagnosis:{$userId}", ['step' => 0]);
        $step = $session['step'];

        if ($text === '問題社員チェックリスト') {
            return $this->textMessage("「問題社員チェックリスト」機能は現在実装予定です。");
        }

        if ($text === '対応マニュアルDL') {
            return $this->textMessage("「対応マニュアルDL」機能は現在実装予定です。");
        }

        if ($text === '無料相談') {
            return $this->textMessage("「無料相談」機能は現在実装予定です。");
        }

        if (str_contains($text, '診断開始') || str_contains($text, 'はじめる')) {
            cache()->put("diagnosis:{$userId}", ['step' => 1], now()->addHours(1));
            return $this->buildQ1Carousel();
        }

        $q1Answers = ['スキル・能力不足', '態度不良・指示不従', 'ハラスメント行為', '勤怠不良', '企業法令・規則違反'];
        if ($step === 1 && in_array($text, $q1Answers)) {
            $session = ['step' => 2, 'problem' => $text];
            cache()->put("diagnosis:{$userId}", $session, now()->addHours(1));
            return $this->buildQ2Buttons();
        }

        $q2Answers = ['ある（メール・録音・日報など）', 'ない', '不明・一部のみ'];
        if ($step === 2 && in_array($text, $q2Answers)) {
            $evidenceMap = [
                'ある（メール・録音・日報など）' => true,
                'ない' => false,
                '不明・一部のみ' => null,
            ];
            $session['step'] = 3;
            $session['evidence'] = $evidenceMap[$text];
            cache()->put("diagnosis:{$userId}", $session, now()->addHours(1));
            return $this->buildQ3Buttons();
        }

        if ($step === 3 && in_array($text, ['ある', 'ない', '不明'])) {
            $hasRules = $text === 'ある';
            $result = $this->diagnose($session['problem'], $session['evidence'], $hasRules);
            cache()->forget("diagnosis:{$userId}");
            return $this->textMessage($result);
        }

        return $this->textMessage("下のメニューから「診断開始」をタップしてください。");
    }

    private function buildQ1Carousel(): TemplateMessage
    {
        $cards = [
            ['text' => 'スキル・能力不足',   'img' => 'q1_skill.jpg'],
            ['text' => '態度不良・指示不従',  'img' => 'q1_attitude.jpg'],
            ['text' => 'ハラスメント行為',    'img' => 'q1_harassment.jpg'],
            ['text' => '勤怠不良',           'img' => 'q1_attendance.jpg'],
            ['text' => '企業法令・規則違反',  'img' => 'q1_violation.jpg'],
        ];

        $columns = array_map(fn($card) => new CarouselColumn([
            'imageUrl' => "{$this->baseUrl}/img/{$card['img']}",
            'title' => $card['text'],
            'text' => 'タップして選択',
            'actions' => [
                new MessageAction([
                    'type' => 'message',
                    'label' => $card['text'],
                    'text' => $card['text'],
                ]),
            ],
        ]), $cards);

        return new TemplateMessage([
            'type' => 'template',
            'altText' => 'Q1. どのような問題が発生していますか？',
            'template' => new CarouselTemplate([
                'type' => 'carousel',
                'columns' => $columns,
                'imageAspectRatio' => 'square',
                'imageSize' => 'cover',
            ]),
        ]);
    }

    private function buildQ2Buttons(): TemplateMessage
    {
        return new TemplateMessage([
            'type' => 'template',
            'altText' => 'Q2. 証拠や記録はありますか？',
            'template' => new ButtonsTemplate([
                'type' => 'buttons',
                'imageUrl' => "{$this->baseUrl}/img/q2_evidence.jpg",
                'imageAspectRatio' => 'square',
                'imageSize' => 'cover',
                'title' => 'Q2. 証拠・記録の有無',
                'text' => '証拠や記録はありますか？',
                'actions' => [
                    new MessageAction(['type' => 'message', 'label' => 'ある（メール・録音・日報など）', 'text' => 'ある（メール・録音・日報など）']),
                    new MessageAction(['type' => 'message', 'label' => 'ない', 'text' => 'ない']),
                    new MessageAction(['type' => 'message', 'label' => '不明・一部のみ', 'text' => '不明・一部のみ']),
                ],
            ]),
        ]);
    }

    private function buildQ3Buttons(): TemplateMessage
    {
        return new TemplateMessage([
            'type' => 'template',
            'altText' => 'Q3. 就業規則はありますか？',
            'template' => new ButtonsTemplate([
                'type' => 'buttons',
                'imageUrl' => "{$this->baseUrl}/img/q3_rules.jpg",
                'imageAspectRatio' => 'square',
                'imageSize' => 'cover',
                'title' => 'Q3. 就業規則の有無',
                'text' => '就業規則はありますか？',
                'actions' => [
                    new MessageAction(['type' => 'message', 'label' => 'ある', 'text' => 'ある']),
                    new MessageAction(['type' => 'message', 'label' => 'ない', 'text' => 'ない']),
                    new MessageAction(['type' => 'message', 'label' => '不明', 'text' => '不明']),
                ],
            ]),
        ]);
    }

    private function diagnose(string $problem, ?bool $evidence, bool $hasRules): string
    {
        $isHighRisk = in_array($problem, ['ハラスメント行為', '企業法令・規則違反']);
        $score = 0;
        if ($evidence === true) $score += 2;
        if ($evidence === null) $score += 1;
        if ($hasRules) $score += 1;
        if ($isHighRisk) $score += 2;

        if ($score >= 4) {
            $rankText = "【Aランク：弁護士相談を強く推薦】\n⚠️ 法的リスクが高く、対応を誤ると会社側が不利になる可能性があります。早急に弁護士へ相談することをお勧めします。";
        } elseif ($score >= 2) {
            $rankText = "【Bランク：社内対応＋専門家確認を推薦】\n⚠️ 対応次第でリスクが増減します。就業規則の整備と証拠収集を進めながら、必要に応じて専門家に確認しましょう。";
        } else {
            $rankText = "【Cランク：まず社内対応・記録整備を】\n📝 現時点では法的リスクは比較的低めです。指導記録の整備と就業規則の確認から始めましょう。";
        }

        return "━━━━━━━━━━━━\n📊 診断結果\n━━━━━━━━━━━━\n\n問題種別：{$problem}\n\n{$rankText}\n\n━━━━━━━━━━━━\n\n再度診断する場合は下のメニューから「診断開始」をタップしてください。";
    }

    private function textMessage(string $text): TextMessage
    {
        return new TextMessage(['type' => 'text', 'text' => $text]);
    }

    private function replyMessage(string $replyToken, TextMessage|TemplateMessage $message): void
    {
        $this->bot->replyMessage(new ReplyMessageRequest([
            'replyToken' => $replyToken,
            'messages' => [$message],
        ]));
    }

    private function validateSignature(string $body, string $secret, ?string $signature): bool
    {
        if (empty($signature)) {
            return false;
        }
        $hash = hash_hmac('sha256', $body, $secret, true);
        return hash_equals(base64_encode($hash), $signature);
    }
}
