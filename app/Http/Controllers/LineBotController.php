<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use LINE\Clients\MessagingApi\Api\MessagingApiApi;
use LINE\Clients\MessagingApi\Model\ReplyMessageRequest;
use LINE\Clients\MessagingApi\Model\TextMessage;
use LINE\Constants\HTTPHeader;
use LINE\Parser\EventRequestParser;
use LINE\Webhook\Model\MessageEvent;
use LINE\Webhook\Model\TextMessageContent;
use LINE\Webhook\Model\FollowEvent;

class LineBotController extends Controller
{
    private MessagingApiApi $bot;

    public function __construct()
    {
        $config = new \LINE\Clients\MessagingApi\Configuration();
        $config->setAccessToken(config('services.line.channel_access_token'));
        $client = new \GuzzleHttp\Client();
        $this->bot = new MessagingApiApi($client, $config);
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
            'text' => "友だち追加ありがとうございます！\n\n「問題社員リスク診断」へようこそ。\n\n「診断開始」と送ってください。",
        ]);

        $this->replyMessage($event->getReplyToken(), $message);
    }

    private function handleTextMessage(MessageEvent $event): void
    {
        /** @var TextMessageContent $messageContent */
        $messageContent = $event->getMessage();
        $text = $messageContent->getText();
        $userId = $event->getSource()->getUserId();

        $replyText = $this->getReply($userId, $text);

        $message = new TextMessage([
            'type' => 'text',
            'text' => $replyText,
        ]);

        $this->replyMessage($event->getReplyToken(), $message);
    }

    private function getReply(string $userId, string $text): string
    {
        $session = cache()->get("diagnosis:{$userId}", ['step' => 0]);
        $step = $session['step'];

        if (str_contains($text, '診断開始') || str_contains($text, 'はじめる')) {
            cache()->put("diagnosis:{$userId}", ['step' => 1], now()->addHours(1));
            return "【問題社員リスク診断】\n\nQ1. どのような問題が発生していますか？\n\n1️⃣ スキル・能力不足\n2️⃣ 態度不良・指示不従\n3️⃣ ハラスメント行為\n4️⃣ 勤怠不良（遅刻・欠勤）\n5️⃣ 企業法令・規則違反\n\n番号で答えてください。";
        }

        if ($step === 1 && in_array($text, ['1', '2', '3', '4', '5'])) {
            $problems = [
                '1' => 'スキル・能力不足',
                '2' => '態度不良・指示不従',
                '3' => 'ハラスメント行為',
                '4' => '勤怠不良',
                '5' => '企業法令・規則違反',
            ];
            $session = ['step' => 2, 'problem' => $problems[$text]];
            cache()->put("diagnosis:{$userId}", $session, now()->addHours(1));
            return "Q2. 証拠や記録はありますか？\n\n1️⃣ ある（メール・録音・日報など）\n2️⃣ ない\n3️⃣ 不明・一部のみ\n\n番号で答えてください。";
        }

        if ($step === 2 && in_array($text, ['1', '2', '3'])) {
            $evidences = ['1' => true, '2' => false, '3' => null];
            $session['step'] = 3;
            $session['evidence'] = $evidences[$text];
            cache()->put("diagnosis:{$userId}", $session, now()->addHours(1));
            return "Q3. 就業規則はありますか？\n\n1️⃣ ある\n2️⃣ ない\n3️⃣ 不明\n\n番号で答えてください。";
        }

        if ($step === 3 && in_array($text, ['1', '2', '3'])) {
            $hasRules = $text === '1';
            $result = $this->diagnose($session['problem'], $session['evidence'], $hasRules);
            cache()->forget("diagnosis:{$userId}");
            return $result;
        }

        return "「診断開始」と送ると問題社員リスク診断が始まります。";
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
            $rank = 'A';
            $rankText = "【Aランク：弁護士相談を強く推薦】\n⚠️ 法的リスクが高く、対応を誤ると会社側が不利になる可能性があります。早急に弁護士へ相談することをお勧めします。";
        } elseif ($score >= 2) {
            $rank = 'B';
            $rankText = "【Bランク：社内対応＋専門家確認を推薦】\n⚠️ 対応次第でリスクが増減します。就業規則の整備と証拠収集を進めながら、必要に応じて専門家に確認しましょう。";
        } else {
            $rank = 'C';
            $rankText = "【Cランク：まず社内対応・記録整備を】\n📝 現時点では法的リスクは比較的低めです。指導記録の整備と就業規則の確認から始めましょう。";
        }

        return "━━━━━━━━━━━━\n📊 診断結果\n━━━━━━━━━━━━\n\n問題種別：{$problem}\n\n{$rankText}\n\n━━━━━━━━━━━━\n\n再度診断する場合は「診断開始」と送ってください。";
    }

    private function replyMessage(string $replyToken, TextMessage $message): void
    {
        $request = new ReplyMessageRequest([
            'replyToken' => $replyToken,
            'messages' => [$message],
        ]);
        $this->bot->replyMessage($request);
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
