<#
    Switches the application from the bundled SQLite file to a local MySQL
    database, then migrates and seeds it.

    The password is read interactively and passed to the client through the
    MYSQL_PWD environment variable, so it never lands in the shell history.

    Usage (from the project root):
        powershell -ExecutionPolicy Bypass -File tools\setup-mysql.ps1
#>

param(
    [string]$Database = 'budong_budong_dt',
    [string]$User = 'root',
    [string]$DbHost = '127.0.0.1',
    [int]$Port = 3306,
    [string]$MysqlClient = ''
)

$ErrorActionPreference = 'Stop'
$projectRoot = Split-Path -Parent $PSScriptRoot
$envFile = Join-Path $projectRoot '.env'

if (-not (Test-Path $envFile)) {
    throw "Berkas .env tidak ditemukan di $projectRoot. Jalankan 'cp .env.example .env' lebih dahulu."
}

# Locate the mysql client.
if ([string]::IsNullOrWhiteSpace($MysqlClient)) {
    $candidates = @(
        'C:\Program Files\MySQL\MySQL Server 8.0\bin\mysql.exe',
        'C:\Program Files\MySQL\MySQL Server 8.4\bin\mysql.exe',
        'C:\xampp\mysql\bin\mysql.exe',
        'C:\laragon\bin\mysql\mysql-8.0.30-winx64\bin\mysql.exe'
    )
    foreach ($candidate in $candidates) {
        if (Test-Path $candidate) { $MysqlClient = $candidate; break }
    }
    if ([string]::IsNullOrWhiteSpace($MysqlClient)) {
        $command = Get-Command mysql -ErrorAction SilentlyContinue
        if ($command) { $MysqlClient = $command.Source }
    }
}

if ([string]::IsNullOrWhiteSpace($MysqlClient)) {
    throw 'Klien mysql.exe tidak ditemukan. Jalankan ulang dengan -MysqlClient "C:\path\ke\mysql.exe".'
}

Write-Host "Klien MySQL : $MysqlClient"
Write-Host "Target      : $User@${DbHost}:$Port, database $Database"

$secure = Read-Host "Kata sandi MySQL untuk '$User' (kosongkan bila tanpa sandi)" -AsSecureString
$bstr = [Runtime.InteropServices.Marshal]::SecureStringToBSTR($secure)
$password = [Runtime.InteropServices.Marshal]::PtrToStringAuto($bstr)
[Runtime.InteropServices.Marshal]::ZeroFreeBSTR($bstr)

$env:MYSQL_PWD = $password
try {
    $sql = "CREATE DATABASE IF NOT EXISTS ``$Database`` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
    & $MysqlClient --protocol=TCP -h $DbHost -P $Port -u $User -e $sql
    if ($LASTEXITCODE -ne 0) {
        throw "Gagal membuat database (exit $LASTEXITCODE). Periksa kembali kredensial MySQL."
    }
    Write-Host "Database '$Database' siap." -ForegroundColor Green

    # Point .env at MySQL.
    $lines = Get-Content $envFile
    $updated = foreach ($line in $lines) {
        switch -Regex ($line) {
            '^DB_CONNECTION=' { 'DB_CONNECTION=mysql'; continue }
            '^#?\s*DB_HOST=' { "DB_HOST=$DbHost"; continue }
            '^#?\s*DB_PORT=' { "DB_PORT=$Port"; continue }
            '^#?\s*DB_DATABASE=' { "DB_DATABASE=$Database"; continue }
            '^#?\s*DB_USERNAME=' { "DB_USERNAME=$User"; continue }
            '^#?\s*DB_PASSWORD=' { "DB_PASSWORD=$password"; continue }
            default { $line }
        }
    }
    $updated | Set-Content $envFile -Encoding utf8
    Write-Host '.env diperbarui ke koneksi mysql.' -ForegroundColor Green

    Push-Location $projectRoot
    try {
        & php artisan config:clear | Out-Null
        Write-Host 'Menjalankan migrasi dan seeder (butuh ~1-2 menit untuk 30 hari data)...'
        & php artisan migrate:fresh --seed --force
        if ($LASTEXITCODE -ne 0) { throw "Migrasi gagal (exit $LASTEXITCODE)." }
    } finally {
        Pop-Location
    }

    Write-Host ''
    Write-Host 'Selesai. Jalankan: php artisan serve' -ForegroundColor Green
} finally {
    Remove-Item Env:MYSQL_PWD -ErrorAction SilentlyContinue
    $password = $null
}
