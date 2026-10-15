<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Throwable;

class InstallationController extends Controller
{
    public function show()
    {
        $this->ensureInstallerIsEnabled();

        try {
            $installed = Schema::hasTable('training_runs');
        } catch (Throwable) {
            $installed = false;
        }

        return view('install', ['installed' => $installed]);
    }

    public function store(Request $request)
    {
        $expectedToken = $this->ensureInstallerIsEnabled();
        $providedToken = (string) $request->validate([
            'install_token' => ['required', 'string'],
        ])['install_token'];

        if (! hash_equals($expectedToken, $providedToken)) {
            throw ValidationException::withMessages([
                'install_token' => 'Token instalasi tidak sesuai.',
            ]);
        }

        try {
            // DDL MySQL tidak selalu dibatalkan ketika proses hosting terputus.
            // Pada instalasi baru, bersihkan tabel parsial sebelum mengulang migrasi.
            $migrationCommand = Schema::hasTable('training_runs')
                ? 'migrate'
                : 'migrate:fresh';

            Artisan::call($migrationCommand, ['--force' => true]);
            $migrationOutput = trim(Artisan::output());

            Artisan::call('db:seed', ['--force' => true]);
            $seederOutput = trim(Artisan::output());
        } catch (Throwable $exception) {
            report($exception);

            return back()->withErrors([
                'installation' => app()->isProduction()
                    ? 'Instalasi gagal. Periksa log aplikasi atau konfigurasi database.'
                    : 'Instalasi gagal: '.$exception->getMessage(),
            ]);
        }

        return view('install', [
            'installed' => true,
            'completed' => true,
            'output' => trim($migrationOutput."\n".$seederOutput),
        ]);
    }

    private function ensureInstallerIsEnabled(): string
    {
        $token = trim((string) config('deployment.infinityfree_install_token'));
        abort_if($token === '' || str_starts_with($token, 'GANTI_'), 404);

        return $token;
    }
}
