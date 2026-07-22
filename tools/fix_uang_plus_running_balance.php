<?php

declare(strict_types=1);

/**
 * Safe patcher: saldo Uang Plus berjalan sampai end_date.
 *
 * Usage from Laravel project root:
 *   php /path/to/fix_uang_plus_running_balance.php
 *
 * Optional:
 *   php /path/to/fix_uang_plus_running_balance.php /www/wwwroot/report.geprekincloud.tech
 */

$root = $argv[1] ?? getcwd();
$root = rtrim((string) realpath($root ?: '.'), DIRECTORY_SEPARATOR);

if ($root === '' || !is_dir($root)) {
    fwrite(STDERR, "Project root tidak valid.\n");
    exit(1);
}

$controllerPath = $root . '/app/Http/Controllers/QCRController.php';
$serviceCandidates = [
    $root . '/app/Services/QCR/QcrControllerService.php',
    $root . '/app/Services/QcrControllerService.php',
];

$servicePath = null;
foreach ($serviceCandidates as $candidate) {
    if (is_file($candidate)) {
        $servicePath = $candidate;
        break;
    }
}

if (!is_file($controllerPath)) {
    fwrite(STDERR, "QCRController.php tidak ditemukan: {$controllerPath}\n");
    exit(1);
}

if ($servicePath === null) {
    fwrite(STDERR, "QcrControllerService.php tidak ditemukan.\n");
    exit(1);
}

function backupFile(string $path): string
{
    $backup = $path . '.bak_uang_plus_running_' . date('Ymd_His');
    if (!copy($path, $backup)) {
        throw new RuntimeException("Gagal membuat backup: {$backup}");
    }
    return $backup;
}

function replaceOnce(string $content, string $pattern, string $replacement, string $label): string
{
    $count = 0;
    $result = preg_replace($pattern, $replacement, $content, 1, $count);

    if ($result === null) {
        throw new RuntimeException("Regex error saat patch {$label}.");
    }

    if ($count !== 1) {
        throw new RuntimeException("Target {$label} tidak ditemukan atau tidak unik. Jumlah match: {$count}");
    }

    return $result;
}

try {
    $controllerBackup = backupFile($controllerPath);
    $serviceBackup = backupFile($servicePath);

    $controller = file_get_contents($controllerPath);
    $service = file_get_contents($servicePath);

    if ($controller === false || $service === false) {
        throw new RuntimeException('Gagal membaca file sumber.');
    }

    // 1) Tambahkan awal ledger jika belum ada.
    if (!str_contains($controller, 'getQcrUangPlusLedgerStartDate')) {
        $helper = <<<'PHP'

    /**
     * Tanggal awal ledger Uang Plus.
     * Saldo tetap dibawa walaupun start_date filter QCR berubah.
     */
    private function getQcrUangPlusLedgerStartDate(): string
    {
        return '2026-05-28';
    }

PHP;

        $controller = replaceOnce(
            $controller,
            '/\n\s*private function getQcrUangPlusServerSaldo\s*\(/',
            $helper . '    private function getQcrUangPlusServerSaldo(',
            'insert helper ledger start date'
        );
    }

    // 2) Ubah server balance agar selalu dari awal ledger sampai end_date.
    $saldoReplacement = <<<'PHP'
    private function getQcrUangPlusServerSaldo(array $outletIds, string $startDate, string $endDate, bool $lockForUpdate = false): float
    {
        // $startDate dipertahankan agar signature lama tidak berubah.
        // Saldo Uang Plus bersifat berjalan sampai tanggal akhir filter.
        $ledgerStartDate = $this->getQcrUangPlusLedgerStartDate();

        return (float) $this->getQcrUangPlusServerRows(
            $outletIds,
            $ledgerStartDate,
            $endDate,
            $lockForUpdate
        )->sum(fn ($row) => (float) ($row->uang_plus ?? 0));
    }
PHP;

    $controller = replaceOnce(
        $controller,
        '/\s*private function getQcrUangPlusServerSaldo\s*\(array \$outletIds, string \$startDate, string \$endDate, bool \$lockForUpdate = false\): float\s*\{.*?\n\s*\}/s',
        "\n" . $saldoReplacement,
        'getQcrUangPlusServerSaldo'
    );

    // 3) Saat menyimpan penukaran, gunakan ledger berjalan juga.
    $saveReplacement = <<<'PHP'
            $ledgerStartDate = $this->getQcrUangPlusLedgerStartDate();

            $stockRows = $this->getQcrUangPlusServerRows(
                $outletIds,
                $ledgerStartDate,
                $endDate,
                true
            );

            $saldoServer = (float) $stockRows->sum(
                fn ($row) => (float) ($row->uang_plus ?? 0)
            );
PHP;

    $controller = replaceOnce(
        $controller,
        '/\s*\$stockRows\s*=\s*\$this->getQcrUangPlusServerRows\(\$outletIds,\s*\$startDate,\s*\$endDate,\s*true\);\s*\$saldoServer\s*=\s*\(float\)\s*\$stockRows->sum\(fn\s*\(\$row\)\s*=>\s*\(float\)\s*\(\$row->uang_plus\s*\?\?\s*0\)\);/s',
        "\n" . $saveReplacement,
        'saveUangPlus running balance rows'
    );

    // 4) Tampilan QCR memakai server balance berjalan.
    $serviceReplacement = <<<'PHP'
        /*
        |--------------------------------------------------------------------------
        | SALDO UANG PLUS BERJALAN SAMPAI TANGGAL AKHIR
        |--------------------------------------------------------------------------
        | Saldo tidak dipotong oleh start_date filter QCR.
        | History pembelian tetap mengikuti start_date sampai end_date.
        |--------------------------------------------------------------------------
        */
        $totalUangPlus = 0.0;

        if (! $isAllOutlet && ! empty($outletIds)) {
            $totalUangPlus = (float) $this->getQcrUangPlusServerSaldo(
                $outletIds,
                $start_date,
                $end_date,
                false
            );
        }
PHP;

    $service = replaceOnce(
        $service,
        '/\s*\/\/\s*Saldo Uang Plus exact sesuai filter tanggal UI\. Tidak ikut H\+1\.\s*\$uangPlusStockRows\s*=\s*\$this->getQcrMergedStockRows\(.*?\)->values\(\);\s*\$totalUangPlus\s*=\s*\$this->sumQcrUangPlusSaldo\(\$uangPlusStockRows\);/s',
        "\n" . $serviceReplacement,
        'QcrControllerService totalUangPlus'
    );

    if (file_put_contents($controllerPath, $controller) === false) {
        throw new RuntimeException('Gagal menulis QCRController.php.');
    }

    if (file_put_contents($servicePath, $service) === false) {
        throw new RuntimeException('Gagal menulis QcrControllerService.php.');
    }

    echo "PATCH BERHASIL\n";
    echo "Controller : {$controllerPath}\n";
    echo "Service    : {$servicePath}\n";
    echo "Backup     : {$controllerBackup}\n";
    echo "Backup     : {$serviceBackup}\n\n";
    echo "Jalankan:\n";
    echo "php -l app/Http/Controllers/QCRController.php\n";
    echo "php -l {$servicePath}\n";
    echo "php artisan optimize:clear\n";
    echo "php artisan view:clear\n";
    echo "php artisan cache:clear\n";
} catch (Throwable $e) {
    fwrite(STDERR, "PATCH GAGAL: {$e->getMessage()}\n");
    exit(1);
}
