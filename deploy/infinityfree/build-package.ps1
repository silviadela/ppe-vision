[CmdletBinding()]
param()

$ErrorActionPreference = 'Stop'

$ProjectRoot = (Resolve-Path (Join-Path $PSScriptRoot '..\..')).Path
$OutputRoot = Join-Path $PSScriptRoot 'package'
$HtdocsRoot = Join-Path $OutputRoot 'htdocs'
$ZipPath = Join-Path $PSScriptRoot 'ppe-vision-infinityfree.zip'

foreach ($command in @('php', 'composer', 'npm')) {
    if (-not (Get-Command $command -ErrorAction SilentlyContinue)) {
        throw "Perintah '$command' tidak ditemukan. Pasang environment lokal terlebih dahulu."
    }
}

$resolvedDeployRoot = (Resolve-Path $PSScriptRoot).Path
if (-not $OutputRoot.StartsWith($resolvedDeployRoot, [StringComparison]::OrdinalIgnoreCase)) {
    throw 'Lokasi output berada di luar folder deploy/infinityfree.'
}

if (Test-Path -LiteralPath $OutputRoot) {
    Remove-Item -LiteralPath $OutputRoot -Recurse -Force
}
if (Test-Path -LiteralPath $ZipPath) {
    Remove-Item -LiteralPath $ZipPath -Force
}
New-Item -ItemType Directory -Path $HtdocsRoot -Force | Out-Null

Write-Host '[1/6] Menyalin source Laravel...'
foreach ($directory in @('app', 'bootstrap', 'config', 'database', 'resources', 'routes', 'storage')) {
    Copy-Item -LiteralPath (Join-Path $ProjectRoot $directory) -Destination $HtdocsRoot -Recurse -Force
}
foreach ($file in @('artisan', 'composer.json', 'composer.lock', 'package.json', 'package-lock.json', 'vite.config.js')) {
    Copy-Item -LiteralPath (Join-Path $ProjectRoot $file) -Destination $HtdocsRoot -Force
}

# public/storage pada pengembangan lokal berupa junction. Di hosting dibuat sebagai folder biasa.
$publicTarget = Join-Path $HtdocsRoot 'public'
New-Item -ItemType Directory -Path $publicTarget -Force | Out-Null
Get-ChildItem -LiteralPath (Join-Path $ProjectRoot 'public') -Force |
    Where-Object Name -ne 'storage' |
    ForEach-Object { Copy-Item -LiteralPath $_.FullName -Destination $publicTarget -Recurse -Force }
New-Item -ItemType Directory -Path (Join-Path $publicTarget 'storage') -Force | Out-Null

Remove-Item -LiteralPath (Join-Path $HtdocsRoot 'database\database.sqlite') -Force -ErrorAction SilentlyContinue
Copy-Item -LiteralPath (Join-Path $ProjectRoot '.env.infinityfree.example') -Destination (Join-Path $HtdocsRoot '.env') -Force
Copy-Item -LiteralPath (Join-Path $PSScriptRoot 'htdocs.htaccess') -Destination (Join-Path $HtdocsRoot '.htaccess') -Force

Push-Location $HtdocsRoot
try {
    Write-Host '[2/6] Memasang dependency PHP production...'
    & composer install --no-dev --prefer-dist --no-interaction --optimize-autoloader
    if ($LASTEXITCODE -ne 0) { throw 'Composer gagal memasang dependency.' }

    Write-Host '[3/6] Membangun asset Vite...'
    & npm ci --no-audit --no-fund
    if ($LASTEXITCODE -ne 0) { throw 'npm ci gagal.' }
    & npm run build
    if ($LASTEXITCODE -ne 0) { throw 'Build Vite gagal.' }
    Remove-Item -LiteralPath (Join-Path $HtdocsRoot 'node_modules') -Recurse -Force

    Write-Host '[4/6] Membuat APP_KEY unik...'
    $appKey = (& php artisan key:generate --show).Trim()
    if ($LASTEXITCODE -ne 0 -or -not $appKey.StartsWith('base64:')) {
        throw 'APP_KEY tidak berhasil dibuat.'
    }
    $envPath = Join-Path $HtdocsRoot '.env'
    $envContent = [IO.File]::ReadAllText($envPath)
    $envContent = [Text.RegularExpressions.Regex]::Replace($envContent, '(?m)^APP_KEY=.*$', "APP_KEY=$appKey")
    [IO.File]::WriteAllText($envPath, $envContent, [Text.UTF8Encoding]::new($false))

    Write-Host '[5/6] Menyiapkan direktori writable...'
    foreach ($directory in @(
        'bootstrap/cache',
        'storage/app/private',
        'storage/framework/cache/data',
        'storage/framework/sessions',
        'storage/framework/views',
        'storage/logs',
        'public/storage/detections/input',
        'public/storage/detections/output'
    )) {
        New-Item -ItemType Directory -Path (Join-Path $HtdocsRoot $directory) -Force | Out-Null
    }
}
finally {
    Pop-Location
}

Write-Host '[6/6] Membuat ZIP upload...'
Add-Type -AssemblyName System.IO.Compression.FileSystem
[IO.Compression.ZipFile]::CreateFromDirectory(
    $HtdocsRoot,
    $ZipPath,
    [IO.Compression.CompressionLevel]::Optimal,
    $false
)

Write-Host ''
Write-Host 'Paket selesai:'
Write-Host "Folder : $HtdocsRoot"
Write-Host "ZIP    : $ZipPath"
Write-Host 'Edit htdocs/.env, lalu unggah seluruh isi folder htdocs ke htdocs InfinityFree.'
