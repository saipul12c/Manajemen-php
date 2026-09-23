<?php
session_start();
require_once __DIR__ . "/../../config/database.php";

requireRole(['administrator']);

$user_id = (int)$_SESSION['user_id'];
$message = "";
$message_type = "";

// -------------------------------------------------------------
// 1. GENERATE & DOWNLOAD FULL DATABASE SQL DUMP
// -------------------------------------------------------------
if (isset($_GET['action']) && $_GET['action'] === 'download_sql') {
    if (!validateCsrfToken()) {
        die("Token keamanan tidak valid.");
    }

    logActivity($pdo, 'BACKUP_DATABASE', "Mengunduh cadangan lengkap basis data SQL");

    $filename = "backup_manajemen_php_" . date('Y-m-d_His') . ".sql";
    header('Content-Type: application/sql');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Pragma: no-cache');
    header('Expires: 0');

    echo "-- =========================================================\n";
    echo "-- Backup Otomatis Database Sistem Manajemen-PHP\n";
    echo "-- Waktu Cadangan: " . date('Y-m-d H:i:s') . "\n";
    echo "-- Dibuat Oleh: " . ($_SESSION['user_name'] ?? 'Administrator') . "\n";
    echo "-- =========================================================\n\n";
    echo "SET FOREIGN_KEY_CHECKS=0;\n\n";

    // Ambil semua tabel
    $tables = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);

    foreach ($tables as $table) {
        echo "-- ---------------------------------------------------------\n";
        echo "-- Struktur Tabel untuk `$table`\n";
        echo "-- ---------------------------------------------------------\n";
        echo "DROP TABLE IF EXISTS `$table`;\n";

        $create_table = $pdo->query("SHOW CREATE TABLE `$table`")->fetch();
        echo $create_table['Create Table'] . ";\n\n";

        // Dump data
        $rows = $pdo->query("SELECT * FROM `$table`")->fetchAll(PDO::FETCH_ASSOC);
        if (!empty($rows)) {
            echo "-- Dumping data untuk tabel `$table`\n";
            $columns = array_keys($rows[0]);
            $col_list = "`" . implode("`, `", $columns) . "`";

            foreach ($rows as $row) {
                $values = [];
                foreach ($row as $val) {
                    if ($val === null) {
                        $values[] = "NULL";
                    } else {
                        $values[] = $pdo->quote($val);
                    }
                }
                echo "INSERT INTO `$table` ($col_list) VALUES (" . implode(", ", $values) . ");\n";
            }
            echo "\n";
        }
    }

    echo "SET FOREIGN_KEY_CHECKS=1;\n";
    exit;
}

// -------------------------------------------------------------
// 2. EXPORT TABEL TERTENTU KE FORMAT CSV
// -------------------------------------------------------------
if (isset($_GET['action']) && $_GET['action'] === 'export_csv') {
    if (!validateCsrfToken()) {
        die("Token keamanan tidak valid.");
    }

    $table_name = preg_replace('/[^a-zA-Z0-9_]/', '', $_GET['table'] ?? '');
    if (empty($table_name)) {
        die("Nama tabel tidak valid.");
    }

    logActivity($pdo, 'EXPORT_CSV', "Mengekspor tabel $table_name ke CSV");

    $filename = "export_" . $table_name . "_" . date('Y-m-d') . ".csv";
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');

    $output = fopen('php://output', 'w');
    // BOM UTF-8 agar karakter Indonesia dan simbol terbaca rapi di Excel
    fputs($output, "\xEF\xBB\xBF");

    $rows = $pdo->query("SELECT * FROM `$table_name`")->fetchAll(PDO::FETCH_ASSOC);
    if (!empty($rows)) {
        fputcsv($output, array_keys($rows[0]));
        foreach ($rows as $row) {
            fputcsv($output, $row);
        }
    }
    fclose($output);
    exit;
}

// -------------------------------------------------------------
// 3. STATISTIK INVENTORY TABEL & DATABASE
// -------------------------------------------------------------
$tables_info = [];
$total_db_size = 0;
$total_rows_all = 0;

try {
    $stmt_status = $pdo->query("SHOW TABLE STATUS");
    while ($row = $stmt_status->fetch()) {
        $data_len = (int)($row['Data_length'] ?? 0);
        $index_len = (int)($row['Index_length'] ?? 0);
        $size_kb = round(($data_len + $index_len) / 1024, 2);
        $total_db_size += ($data_len + $index_len);
        $total_rows_all += (int)($row['Rows'] ?? 0);

        $tables_info[] = [
            'name'       => $row['Name'],
            'engine'     => $row['Engine'],
            'rows'       => (int)($row['Rows'] ?? 0),
            'size_kb'    => $size_kb,
            'collation'  => $row['Collation'] ?? '-',
            'updated_at' => $row['Update_time'] ?? '-'
        ];
    }
} catch (Exception $e) {}

$total_db_size_mb = round($total_db_size / (1024 * 1024), 2);
$db_version = $pdo->query("SELECT VERSION()")->fetchColumn();

$page_title = "Backup & Pemulihan Database";
require_once __DIR__ . "/../includes/header.php";
?>

<div class="space-y-6">

    <!-- Top Header -->
    <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <div>
            <h1 class="text-2xl font-bold tracking-tight text-white flex items-center gap-2.5">
                <i class="fa-solid fa-database text-blue-400"></i> Backup & Ekspor Basis Data
            </h1>
            <p class="text-sm text-slate-400 mt-1">
                Utilitas pengamanan data sekolah, pencadangan 1-klik format SQL dump, dan ekspor tabel spreadsheet.
            </p>
        </div>

        <a href="backup.php?action=download_sql&csrf_token=<?= generateCsrfToken() ?>" class="inline-flex items-center gap-2 rounded-xl bg-blue-600 px-5 py-2.5 text-sm font-bold text-white shadow-lg shadow-blue-500/30 hover:bg-blue-500 transition cursor-pointer self-start sm:self-auto">
            <i class="fa-solid fa-download"></i> Unduh Backup SQL Penuh (1-Klik)
        </a>
    </div>

    <!-- Health & Stats Overview -->
    <div class="grid grid-cols-1 sm:grid-cols-4 gap-4">
        <div class="rounded-2xl border border-white/10 bg-slate-900/60 p-4 backdrop-blur">
            <span class="text-xs font-semibold text-slate-400 uppercase tracking-wider">Total Ukuran Basis Data</span>
            <div class="text-2xl font-bold text-white mt-1">
                <?= $total_db_size_mb ?> MB
            </div>
            <p class="text-xs text-slate-500 mt-1">Data & Index MySQL</p>
        </div>

        <div class="rounded-2xl border border-white/10 bg-slate-900/60 p-4 backdrop-blur">
            <span class="text-xs font-semibold text-slate-400 uppercase tracking-wider">Jumlah Tabel Aktif</span>
            <div class="text-2xl font-bold text-blue-400 mt-1">
                <?= count($tables_info) ?> Tabel
            </div>
            <p class="text-xs text-slate-500 mt-1">Tersinkronisasi skema sistem</p>
        </div>

        <div class="rounded-2xl border border-white/10 bg-slate-900/60 p-4 backdrop-blur">
            <span class="text-xs font-semibold text-slate-400 uppercase tracking-wider">Total Record / Baris</span>
            <div class="text-2xl font-bold text-emerald-400 mt-1">
                <?= number_format($total_rows_all) ?>
            </div>
            <p class="text-xs text-slate-500 mt-1">Total akumulasi seluruh entri</p>
        </div>

        <div class="rounded-2xl border border-white/10 bg-slate-900/60 p-4 backdrop-blur">
            <span class="text-xs font-semibold text-slate-400 uppercase tracking-wider">Versi Server Mesin</span>
            <div class="text-base font-bold text-purple-300 mt-1.5 truncate">
                <?= htmlspecialchars($db_version) ?>
            </div>
            <p class="text-xs text-slate-500 mt-1">InnoDB Engine UTF-8</p>
        </div>
    </div>

    <!-- Table Inventory & Specific CSV Export -->
    <div class="rounded-2xl border border-white/10 bg-slate-900/50 shadow-lg backdrop-blur overflow-hidden">
        <div class="border-b border-white/10 px-5 py-4 flex items-center justify-between">
            <div>
                <h3 class="text-base font-bold text-white">Inventaris Tabel Basis Data</h3>
                <p class="text-xs text-slate-400">Pilih tabel tertentu untuk diekspor ke format Excel / CSV</p>
            </div>
            <span class="text-xs text-slate-400 font-mono">Database: <strong>website_login</strong></span>
        </div>

        <div class="overflow-x-auto">
            <table class="w-full text-left text-sm text-slate-300">
                <thead class="bg-slate-950/60 text-xs uppercase font-semibold text-slate-400">
                    <tr>
                        <th class="px-5 py-3.5">Nama Tabel</th>
                        <th class="px-5 py-3.5">Engine</th>
                        <th class="px-5 py-3.5">Jumlah Baris</th>
                        <th class="px-5 py-3.5">Ukuran</th>
                        <th class="px-5 py-3.5">Collation</th>
                        <th class="px-5 py-3.5 text-right">Ekspor CSV</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-white/5">
                    <?php foreach ($tables_info as $tbl): ?>
                        <tr class="hover:bg-white/[0.02] transition">
                            <td class="px-5 py-3.5 font-mono font-bold text-white">
                                <i class="fa-solid fa-table text-blue-400 text-xs mr-2"></i><?= htmlspecialchars($tbl['name']) ?>
                            </td>
                            <td class="px-5 py-3.5 text-xs text-slate-400">
                                <?= htmlspecialchars($tbl['engine']) ?>
                            </td>
                            <td class="px-5 py-3.5 text-xs font-semibold text-slate-200">
                                <?= number_format($tbl['rows']) ?> baris
                            </td>
                            <td class="px-5 py-3.5 text-xs font-mono text-slate-400">
                                <?= $tbl['size_kb'] ?> KB
                            </td>
                            <td class="px-5 py-3.5 text-xs text-slate-500">
                                <?= htmlspecialchars($tbl['collation']) ?>
                            </td>
                            <td class="px-5 py-3.5 text-right">
                                <a href="backup.php?action=export_csv&table=<?= urlencode($tbl['name']) ?>&csrf_token=<?= generateCsrfToken() ?>" class="inline-flex items-center gap-1.5 rounded-lg border border-white/10 bg-slate-800 px-3 py-1 text-xs font-medium text-slate-300 hover:bg-slate-700 hover:text-white transition">
                                    <i class="fa-solid fa-file-csv text-emerald-400"></i> Ekspor CSV
                                </a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

</div>

<?php require_once __DIR__ . "/../includes/footer.php"; ?>
