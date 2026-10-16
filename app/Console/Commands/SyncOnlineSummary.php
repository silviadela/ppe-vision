<?php

namespace App\Console\Commands;

use App\Services\OnlineSummarySyncService;
use Illuminate\Console\Command;
use Throwable;

class SyncOnlineSummary extends Command
{
    protected $signature = 'summary:sync-online {run=1 : ID eksperimen pada database online}';

    protected $description = 'Generate summary dengan Ollama lokal dan simpan ke database dashboard online';

    public function handle(OnlineSummarySyncService $service): int
    {
        try {
            $result = $service->generateAndPush((int) $this->argument('run'));
        } catch (Throwable $exception) {
            report($exception);
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info('Summary berhasil dibuat oleh '.$result['engine'].' dan disimpan online.');
        if ($result['summary_url']) {
            $this->line('Buka: '.$result['summary_url']);
        }

        return self::SUCCESS;
    }
}
