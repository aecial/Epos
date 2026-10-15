<?php

namespace App\Console\Commands;

use App\Models\Shift;
use App\Services\TelegramShiftReportService;
use Illuminate\Console\Command;
use Throwable;

class SendShiftSalesReports extends Command
{
    protected $signature = 'telegram:shift-reports
        {--shift= : Send this closed shift\'s full report again}
        {--preview : With --shift, print the report here instead of sending it}
        {--test : Send a test message to check TELEGRAM_BOT_TOKEN and TELEGRAM_CHAT_ID}
        {--find-chat : List the chats the bot has seen, to find TELEGRAM_CHAT_ID}';

    protected $description = 'Post closed shifts\' items sold and raw materials used to the Telegram group (scheduled every minute)';

    public function handle(TelegramShiftReportService $reports): int
    {
        try {
            if ($this->option('find-chat')) {
                return $this->findChat($reports);
            }

            if ($this->option('preview')) {
                $this->line($reports->FullReport(Shift::query()->findOrFail((int) $this->option('shift'))));

                return self::SUCCESS;
            }

            if (! $reports->IsConfigured()) {
                $this->warn('Telegram is not set up: add TELEGRAM_BOT_TOKEN and TELEGRAM_CHAT_ID to .env.');

                return self::SUCCESS;
            }

            if ($this->option('test')) {
                $reports->SendTest();
                $this->info('Test message sent.');

                return self::SUCCESS;
            }

            if ($this->option('shift') !== null) {
                $reports->Resend(Shift::query()->findOrFail((int) $this->option('shift')));
                $this->info("Shift #{$this->option('shift')} report sent.");

                return self::SUCCESS;
            }
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $failed = false;

        foreach ($reports->SendPending() as $result) {
            if ($result['error'] !== null) {
                $failed = true;
                $this->error("Shift #{$result['shift_id']}: {$result['error']}");
            } elseif ($result['sent']) {
                $this->info("Shift #{$result['shift_id']}: report sent.");
            }
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }

    private function findChat(TelegramShiftReportService $reports): int
    {
        $chats = $reports->RecentChats();

        if ($chats === []) {
            $this->warn('The bot has not seen any message yet. Add it to the group, send a message there (e.g. "hi"), then run this again.');

            return self::SUCCESS;
        }

        $this->table(['TELEGRAM_CHAT_ID', 'Chat', 'Type'], array_map(fn (array $chat): array => [$chat['id'], $chat['title'], $chat['type']], $chats));

        return self::SUCCESS;
    }
}
