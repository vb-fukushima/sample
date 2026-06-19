<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use GuzzleHttp\Client;

class SetupRichMenu extends Command
{
    protected $signature = 'line:setup-richmenu';
    protected $description = 'LINE リッチメニューを作成・設定する';

    public function handle(): void
    {
        $token = config('services.line.channel_access_token');
        $client = new Client([
            'headers' => [
                'Authorization' => "Bearer {$token}",
                'Content-Type' => 'application/json',
            ],
        ]);

        // 1. 既存のリッチメニューを削除
        $this->info('既存のリッチメニューを確認中...');
        $res = $client->get('https://api.line.me/v2/bot/richmenu/list');
        $list = json_decode($res->getBody(), true);
        foreach ($list['richmenus'] ?? [] as $menu) {
            $client->delete("https://api.line.me/v2/bot/richmenu/{$menu['richMenuId']}");
            $this->line("  削除: {$menu['richMenuId']}");
        }

        // 2. リッチメニュー作成
        $this->info('リッチメニューを作成中...');
        $res = $client->post('https://api.line.me/v2/bot/richmenu', [
            'json' => [
                'size' => ['width' => 2500, 'height' => 1686],
                'selected' => true,
                'name' => '診断メニュー',
                'chatBarText' => 'メニュー',
                'areas' => [
                    [
                        'bounds' => ['x' => 0, 'y' => 0, 'width' => 1250, 'height' => 843],
                        'action' => ['type' => 'message', 'label' => '診断開始', 'text' => '診断開始'],
                    ],
                    [
                        'bounds' => ['x' => 1250, 'y' => 0, 'width' => 1250, 'height' => 843],
                        'action' => ['type' => 'message', 'label' => '問題社員チェックリスト', 'text' => '問題社員チェックリスト'],
                    ],
                    [
                        'bounds' => ['x' => 0, 'y' => 843, 'width' => 1250, 'height' => 843],
                        'action' => ['type' => 'message', 'label' => '対応マニュアルDL', 'text' => '対応マニュアルDL'],
                    ],
                    [
                        'bounds' => ['x' => 1250, 'y' => 843, 'width' => 1250, 'height' => 843],
                        'action' => ['type' => 'message', 'label' => '無料相談', 'text' => '無料相談'],
                    ],
                ],
            ],
        ]);
        $richMenuId = json_decode($res->getBody(), true)['richMenuId'];
        $this->info("  作成完了: {$richMenuId}");

        // 3. 画像をアップロード
        $this->info('画像をアップロード中...');
        $imagePath = base_path('../img/richmenu/richmenu_2500.jpg');
        $imageClient = new Client([
            'headers' => [
                'Authorization' => "Bearer {$token}",
                'Content-Type' => 'image/jpeg',
            ],
        ]);
        $imageClient->post(
            "https://api-data.line.me/v2/bot/richmenu/{$richMenuId}/content",
            ['body' => fopen($imagePath, 'r')]
        );
        $this->info('  画像アップロード完了');

        // 4. デフォルトに設定
        $this->info('デフォルトリッチメニューに設定中...');
        $client->post("https://api.line.me/v2/bot/user/all/richmenu/{$richMenuId}");
        $this->info('  設定完了');

        $this->info('');
        $this->info('リッチメニューのセットアップが完了しました！');
    }
}
