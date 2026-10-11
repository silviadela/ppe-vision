<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

class YoloInferenceService
{
    public function isReady(): bool
    {
        $url = config('services.yolo.url');
        if (! $url) {
            return false;
        }

        try {
            return Http::timeout(3)->get(rtrim($url, '/').'/health')->json('status') === 'ready';
        } catch (Throwable) {
            return false;
        }
    }

    public function detect(UploadedFile $file, float $confidence): array
    {
        $url = config('services.yolo.url');
        if (! $url) {
            throw new RuntimeException('YOLO_API_URL belum dikonfigurasi. Jalankan service Python lalu isi URL-nya di file .env.');
        }

        return Http::timeout(120)
            ->attach('file', fopen($file->getRealPath(), 'r'), $file->getClientOriginalName())
            ->post(rtrim($url, '/').'/predict', ['confidence' => $confidence])
            ->throw()
            ->json();
    }
}
