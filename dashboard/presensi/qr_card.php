<?php
session_start();
require_once __DIR__ . "/../../config/database.php";

requireLogin();

$user_id = (int)$_SESSION['user_id'];
$user_role = $_SESSION['user_role'] ?? 'siswa';
$is_staff = in_array($user_role, ['administrator', 'guru', 'staf'], true);
$school = getSchoolSettings($pdo);

// Mode: 'single' (default) atau 'batch' (cetak massal rombel)
$view_mode = $_GET['mode'] ?? 'single';
$selected_class_id = isset($_GET['class_id']) && $_GET['class_id'] !== '' ? (int)$_GET['class_id'] : null;

// Tentukan siswa target jika single mode
$target_student_id = $user_id;
if ($is_staff && isset($_GET['student_id'])) {
    $target_student_id = (int)$_GET['student_id'];
} elseif ($user_role === 'orang_tua') {
    $stmt_child = $pdo->prepare("SELECT student_id FROM parent_students WHERE parent_id = ? LIMIT 1");
    $stmt_child->execute([$user_id]);
    $target_student_id = (int)($stmt_child->fetchColumn() ?: $user_id);
} elseif ($is_staff && !isset($_GET['student_id'])) {
    $stmt_first = $pdo->query("SELECT id FROM users WHERE role = 'siswa' ORDER BY class_id ASC, name ASC LIMIT 1");
    $target_student_id = (int)($stmt_first->fetchColumn() ?: 0);
}

// Ambil kelas
$classes_list = $pdo->query("SELECT id, name FROM classes ORDER BY name ASC")->fetchAll();

// Jika mode batch: ambil seluruh siswa pada kelas terpilih
$batch_students = [];
if ($view_mode === 'batch' && $is_staff) {
    $sql_batch = "
        SELECT u.*, c.name as class_name 
        FROM users u 
        LEFT JOIN classes c ON u.class_id = c.id 
        WHERE u.role = 'siswa'
    ";
    $params_batch = [];
    if ($selected_class_id) {
        $sql_batch .= " AND u.class_id = ?";
        $params_batch[] = $selected_class_id;
    }
    $sql_batch .= " ORDER BY c.name ASC, u.name ASC";
    $stmt_b = $pdo->prepare($sql_batch);
    $stmt_b->execute($params_batch);
    $batch_students = $stmt_b->fetchAll();
} else {
    // Mode Single: Ambil data 1 siswa
    $stmt_stu = $pdo->prepare("
        SELECT u.*, c.name as class_name, c.grade_level
        FROM users u
        LEFT JOIN classes c ON u.class_id = c.id
        WHERE u.id = ? AND u.role = 'siswa'
    ");
    $stmt_stu->execute([$target_student_id]);
    $student = $stmt_stu->fetch();
}

// Daftar siswa untuk dropdown pemilihan di single mode
$all_students = [];
if ($is_staff && $view_mode === 'single') {
    $all_students = $pdo->query("
        SELECT u.id, u.name, u.nisn, u.class_id, c.name as class_name
        FROM users u
        LEFT JOIN classes c ON u.class_id = c.id
        WHERE u.role = 'siswa'
        ORDER BY c.name ASC, u.name ASC
    ")->fetchAll();
}

$page_title = "Generator Kartu Pelajar Digital";
require_once __DIR__ . "/../includes/header.php";
?>

<!-- Library CDN Barcode & QR Code -->
<script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/jsbarcode@3.11.5/dist/JsBarcode.all.min.js"></script>

<style>
/* Dimensi Standar Kartu CR-80 (85.6mm x 54mm) */
.id-card-wrap {
    width: 330px;
    height: 520px;
}
@media print {
    header, nav, footer, .no-print, button, form, .no-print-bar {
        display: none !important;
    }
    body {
        background: white !important;
        color: #000 !important;
        padding: 0 !important;
        margin: 0 !important;
    }
    .print-sheet {
        display: block !important;
        width: 100% !important;
        margin: 0 !important;
        padding: 5mm !important;
    }
    .id-card-print {
        box-shadow: none !important;
        border: 1px solid #cbd5e1 !important;
        page-break-inside: avoid !important;
        -webkit-print-color-adjust: exact !important;
        print-color-adjust: exact !important;
    }
}
</style>

<div class="space-y-6">

    <!-- Header Action Bar (No-Print) -->
    <div class="no-print flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <div>
            <div class="flex items-center gap-3">
                <span class="flex h-11 w-11 items-center justify-center rounded-2xl bg-blue-500/20 text-blue-400 text-xl border border-blue-500/30 shadow-lg shadow-blue-500/10">
                    <i class="fa-solid fa-id-card"></i>
                </span>
                <div>
                    <h1 class="text-2xl font-bold tracking-tight text-white">Generator Kartu Pelajar Digital</h1>
                    <p class="text-xs sm:text-sm text-slate-400">
                        Kartu identitas resmi siswa 2 Sisi (Depan & Belakang) dengan Barcode NISN & QR Code presensi.
                    </p>
                </div>
            </div>
        </div>

        <div class="flex flex-wrap items-center gap-2">
            <?php if ($is_staff): ?>
                <?php if ($view_mode === 'single'): ?>
                    <a href="qr_card.php?mode=batch<?= $selected_class_id ? '&class_id=' . $selected_class_id : '' ?>" class="inline-flex items-center gap-2 rounded-xl border border-emerald-500/30 bg-emerald-500/10 px-3.5 py-2 text-xs font-semibold text-emerald-300 hover:bg-emerald-500/20 transition">
                        <i class="fa-solid fa-layer-group"></i> Mode Cetak Massal (Rombel)
                    </a>
                <?php else: ?>
                    <a href="qr_card.php?mode=single<?= $target_student_id ? '&student_id=' . $target_student_id : '' ?>" class="inline-flex items-center gap-2 rounded-xl border border-blue-500/30 bg-blue-500/10 px-3.5 py-2 text-xs font-semibold text-blue-300 hover:bg-blue-500/20 transition">
                        <i class="fa-solid fa-user"></i> Mode Kartu Tunggal
                    </a>
                <?php endif; ?>
                <a href="scan_qr.php" class="inline-flex items-center gap-2 rounded-xl border border-white/10 bg-slate-800 px-3.5 py-2 text-xs font-semibold text-slate-200 hover:bg-slate-700 transition">
                    <i class="fa-solid fa-camera"></i> Scanner Presensi
                </a>
            <?php endif; ?>

            <button onclick="window.print()" class="inline-flex items-center gap-2 rounded-xl bg-blue-600 px-4 py-2 text-xs font-bold text-white shadow-lg shadow-blue-500/25 hover:bg-blue-500 transition cursor-pointer">
                <i class="fa-solid fa-print"></i> Cetak Kartu
            </button>
        </div>
    </div>

    <!-- Toolbar Selector (No-Print) -->
    <div class="no-print rounded-2xl border border-white/10 bg-slate-900/70 p-4 backdrop-blur">
        <?php if ($view_mode === 'batch' && $is_staff): ?>
            <!-- Filter Rombel untuk Cetak Massal -->
            <form method="GET" class="flex flex-wrap items-center gap-3">
                <input type="hidden" name="mode" value="batch">
                <span class="text-xs font-bold uppercase tracking-wider text-slate-400">Pilih Rombel / Kelas:</span>
                <select name="class_id" onchange="this.form.submit()" class="rounded-xl border border-white/10 bg-slate-950 px-3.5 py-2 text-xs text-white focus:outline-none">
                    <option value="">-- Semua Kelas (Total: <?= count($batch_students) ?> Siswa) --</option>
                    <?php foreach ($classes_list as $cl): ?>
                        <option value="<?= $cl['id'] ?>" <?= ($selected_class_id == $cl['id']) ? 'selected' : '' ?>>
                            Kelas <?= htmlspecialchars($cl['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <span class="text-xs text-slate-400">
                    <i class="fa-regular fa-lightbulb text-amber-400 mr-1"></i> Menampilkan <?= count($batch_students) ?> kartu siap cetak potong (Format A4).
                </span>
            </form>
        <?php elseif ($is_staff && !empty($all_students)): ?>
            <!-- Filter Siswa untuk Mode Tunggal -->
            <form method="GET" class="flex flex-wrap items-center gap-3">
                <input type="hidden" name="mode" value="single">
                <span class="text-xs font-bold uppercase tracking-wider text-slate-400">Pilih Siswa:</span>
                <select name="student_id" onchange="this.form.submit()" class="rounded-xl border border-white/10 bg-slate-950 px-3.5 py-2 text-xs text-white focus:outline-none max-w-md">
                    <?php foreach ($all_students as $st): ?>
                        <option value="<?= $st['id'] ?>" <?= ($target_student_id == $st['id']) ? 'selected' : '' ?>>
                            <?= htmlspecialchars($st['name']) ?> (NISN: <?= htmlspecialchars($st['nisn'] ?: '-') ?>) - <?= htmlspecialchars($st['class_name'] ?: 'Umum') ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </form>
        <?php endif; ?>
    </div>

    <!-- TAMPILAN KARTU TUNGGAL (2 SISI) -->
    <?php if ($view_mode === 'single'): ?>
        <?php if (!$student): ?>
            <div class="rounded-2xl border border-rose-500/20 bg-rose-950/20 p-8 text-center text-rose-300">
                Data profil siswa tidak ditemukan.
            </div>
        <?php else: ?>
            <div class="print-sheet flex flex-col lg:flex-row items-center justify-center gap-8 py-6">
                
                <!-- 1. KARTU SISI DEPAN (FRONT SIDE) -->
                <div class="id-card-wrap id-card-print relative rounded-3xl border border-blue-500/40 bg-gradient-to-br from-slate-900 via-slate-950 to-blue-950/80 p-5 shadow-2xl backdrop-blur flex flex-col justify-between overflow-hidden text-slate-100">
                    <!-- Glow Accents -->
                    <div class="absolute -right-8 -top-8 h-32 w-32 rounded-full bg-blue-600/25 blur-2xl pointer-events-none"></div>
                    <div class="absolute -left-8 -bottom-8 h-32 w-32 rounded-full bg-purple-600/20 blur-2xl pointer-events-none"></div>
                    <div class="absolute top-2 right-4 text-[9px] font-mono tracking-widest text-blue-400/40 uppercase">OFFICIAL STUDENT ID</div>

                    <!-- Header Kop Kartu -->
                    <div class="relative z-10 border-b border-white/10 pb-3 flex items-center gap-2.5">
                        <span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-2xl bg-blue-600 text-xl font-bold shadow-md shadow-blue-500/30 text-white">
                            <i class="fa-solid fa-bolt"></i>
                        </span>
                        <div class="min-w-0">
                            <h2 class="text-xs font-black uppercase tracking-tight text-white truncate">
                                <?= htmlspecialchars($school['school_name']) ?>
                            </h2>
                            <p class="text-[9px] text-slate-400 leading-tight truncate"><?= htmlspecialchars($school['school_address']) ?></p>
                            <span class="inline-block mt-0.5 rounded-full bg-blue-500/20 border border-blue-400/30 px-2 py-0.2 text-[8px] font-bold text-blue-300 uppercase tracking-widest">
                                KARTU TANDA PELAJAR
                            </span>
                        </div>
                    </div>

                    <!-- Body: Foto & Profil Siswa -->
                    <div class="relative z-10 my-auto py-2 space-y-3">
                        <div class="flex items-center gap-4">
                            <!-- Foto Avatar -->
                            <div class="relative shrink-0">
                                <div class="flex h-24 w-24 items-center justify-center rounded-2xl border-2 border-blue-400/60 bg-gradient-to-tr from-slate-800 to-blue-900/60 text-3xl font-extrabold text-white shadow-xl shadow-blue-950/50">
                                    <?= strtoupper(substr($student['name'], 0, 2)) ?>
                                </div>
                                <span class="absolute -bottom-1 -right-1 flex h-6 w-6 items-center justify-center rounded-lg bg-emerald-500 text-[10px] text-white font-bold shadow">
                                    <i class="fa-solid fa-check"></i>
                                </span>
                            </div>

                            <!-- Bio Utama -->
                            <div class="min-w-0 flex-1">
                                <span class="text-[9px] uppercase tracking-wider text-blue-400 font-bold block">Nama Lengkap</span>
                                <h3 class="text-base font-extrabold text-white leading-tight truncate">
                                    <?= htmlspecialchars($student['name']) ?>
                                </h3>
                                
                                <span class="text-[9px] uppercase tracking-wider text-slate-400 font-bold block mt-2">Rombel / Kelas</span>
                                <p class="text-xs font-bold text-emerald-400 font-mono">
                                    <?= htmlspecialchars($student['class_name'] ?: 'Reguler') ?>
                                </p>
                            </div>
                        </div>

                        <!-- Data Atribut Siswa -->
                        <div class="rounded-xl border border-white/10 bg-slate-950/60 p-2.5 text-[11px] space-y-1">
                            <div class="flex justify-between"><span class="text-slate-400">NISN</span><strong class="font-mono text-white tracking-wider"><?= htmlspecialchars($student['nisn'] ?: '-') ?></strong></div>
                            <div class="flex justify-between"><span class="text-slate-400">Jenis Kelamin</span><span class="text-slate-200"><?= $student['gender'] === 'P' ? 'Perempuan' : 'Laki-laki' ?></span></div>
                            <div class="flex justify-between"><span class="text-slate-400">Masa Berlaku</span><span class="text-slate-200"><?= htmlspecialchars($school['academic_year'] ?? '2026/2027') ?></span></div>
                        </div>
                    </div>

                    <!-- Footer Sisi Depan -->
                    <div class="relative z-10 border-t border-white/10 pt-2.5 flex items-center justify-between text-[9px] text-slate-400">
                        <span class="font-bold text-emerald-400 flex items-center gap-1">
                            <span class="h-1.5 w-1.5 rounded-full bg-emerald-400 inline-block"></span> STATUS: AKTIF
                        </span>
                        <span class="font-mono text-[8px] text-slate-500">TAMPAK DEPAN</span>
                    </div>
                </div>

                <!-- 2. KARTU SISI BELAKANG (BACK SIDE) -->
                <div class="id-card-wrap id-card-print relative rounded-3xl border border-slate-700/60 bg-gradient-to-br from-slate-950 via-slate-900 to-slate-950 p-5 shadow-2xl backdrop-blur flex flex-col justify-between overflow-hidden text-slate-100">
                    
                    <div class="relative z-10 border-b border-white/10 pb-2 flex items-center justify-between">
                        <h4 class="text-[10px] font-bold uppercase tracking-wider text-slate-300">Ketentuan Pemegang Kartu</h4>
                        <span class="font-mono text-[8px] text-slate-500">TAMPAK BELAKANG</span>
                    </div>

                    <!-- Tata Tertib Singkat -->
                    <div class="relative z-10 my-2 text-[9px] text-slate-300 space-y-1 leading-snug">
                        <p>1. Kartu ini adalah tanda pengenal sah peserta didik <?= htmlspecialchars($school['school_name']) ?>.</p>
                        <p>2. Wajib dibawa setiap hari dan digunakan untuk presensi gerbang & peminjaman perpustakaan.</p>
                        <p>3. Dilarang memindahtangankan kartu ini kepada orang lain.</p>
                        <p>4. Jika kartu hilang, segera laporkan ke bagian Tata Usaha Sekolah.</p>
                    </div>

                    <!-- QR Code & Barcode Section -->
                    <div class="relative z-10 my-auto flex flex-col items-center bg-white rounded-2xl p-3 shadow-md border border-slate-300">
                        <!-- QR Code Container -->
                        <div id="singleQrBox" class="mb-2"></div>

                        <!-- Barcode 1D (Code128) -->
                        <svg id="singleBarcode" class="w-full max-h-11"></svg>
                    </div>

                    <!-- Tanda Tangan & Stempel Kepala Sekolah -->
                    <div class="relative z-10 border-t border-white/10 pt-2 flex items-end justify-between text-[9px]">
                        <div>
                            <span class="text-slate-400 block text-[8px]">Scan QR untuk:</span>
                            <span class="text-emerald-400 font-semibold text-[8px]">Presensi & Perpus Digital</span>
                        </div>
                        <div class="text-right">
                            <span class="text-slate-400 block text-[8px]">Kepala Sekolah,</span>
                            <div class="h-6"></div>
                            <span class="font-bold underline text-white block text-[9px]"><?= htmlspecialchars($school['headmaster_name']) ?></span>
                            <span class="font-mono text-[7px] text-slate-400 block">NIP. <?= htmlspecialchars($school['headmaster_nip']) ?></span>
                        </div>
                    </div>
                </div>

            </div>

            <!-- Inisialisasi Script QR & Barcode Single -->
            <script>
            document.addEventListener('DOMContentLoaded', function() {
                const qrVal = "<?= htmlspecialchars($student['nisn'] ?: ('ID-' . $student['id'])) ?>";
                const qrBox = document.getElementById('singleQrBox');
                if (qrBox && typeof QRCode !== 'undefined') {
                    new QRCode(qrBox, {
                        text: qrVal,
                        width: 105,
                        height: 105,
                        colorDark : "#0f172a",
                        colorLight : "#ffffff",
                        correctLevel : QRCode.CorrectLevel.M
                    });
                }

                if (typeof JsBarcode !== 'undefined') {
                    try {
                        JsBarcode("#singleBarcode", qrVal, {
                            format: "CODE128",
                            lineColor: "#0f172a",
                            width: 1.4,
                            height: 28,
                            fontSize: 9,
                            displayValue: true
                        });
                    } catch (e) {
                        console.error("Barcode generation:", e);
                    }
                }
            });
            </script>
        <?php endif; ?>

    <!-- TAMPILAN CETAK MASSAL ROMBEL (GRID A4) -->
    <?php else: ?>
        <div class="print-sheet">
            <div class="grid grid-cols-1 md:grid-cols-2 gap-6 justify-items-center">
                <?php foreach ($batch_students as $idx => $st): ?>
                    <!-- Kartu Sisi Depan Siswa -->
                    <div class="id-card-wrap id-card-print relative rounded-3xl border border-blue-500/40 bg-gradient-to-br from-slate-900 via-slate-950 to-blue-950/80 p-5 shadow-md flex flex-col justify-between overflow-hidden text-slate-100">
                        <div class="border-b border-white/10 pb-2 flex items-center gap-2">
                            <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-xl bg-blue-600 text-sm font-bold text-white"><i class="fa-solid fa-bolt"></i></span>
                            <div class="min-w-0">
                                <h4 class="text-[11px] font-black uppercase text-white truncate"><?= htmlspecialchars($school['school_name']) ?></h4>
                                <span class="rounded bg-blue-500/20 px-1.5 py-0.5 text-[7px] font-bold text-blue-300 uppercase">KARTU TANDA PELAJAR</span>
                            </div>
                        </div>

                        <div class="my-auto py-2 space-y-2">
                            <div class="flex items-center gap-3">
                                <div class="flex h-16 w-16 items-center justify-center rounded-xl bg-slate-800 text-xl font-extrabold text-white border border-blue-400">
                                    <?= strtoupper(substr($st['name'], 0, 2)) ?>
                                </div>
                                <div class="min-w-0 flex-1">
                                    <h3 class="text-sm font-bold text-white truncate"><?= htmlspecialchars($st['name']) ?></h3>
                                    <p class="text-xs font-semibold text-emerald-400"><?= htmlspecialchars($st['class_name'] ?: 'Reguler') ?></p>
                                    <p class="text-[10px] font-mono text-slate-300">NISN: <?= htmlspecialchars($st['nisn'] ?: '-') ?></p>
                                </div>
                            </div>
                        </div>

                        <!-- Mini QR & Barcode -->
                        <div class="bg-white rounded-xl p-2 flex items-center justify-between">
                            <div id="batchQr_<?= $st['id'] ?>" class="shrink-0"></div>
                            <svg id="batchBarcode_<?= $st['id'] ?>" class="flex-1 max-h-9 ml-2"></svg>
                        </div>

                        <div class="border-t border-white/10 pt-2 flex items-center justify-between text-[8px] text-slate-400">
                            <span class="font-bold text-emerald-400">STATUS: AKTIF</span>
                            <span><?= htmlspecialchars($school['academic_year'] ?? '2026/2027') ?></span>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>

        <script>
        document.addEventListener('DOMContentLoaded', function() {
            <?php foreach ($batch_students as $st): 
                $payload = htmlspecialchars($st['nisn'] ?: ('ID-' . $st['id']));
            ?>
                if (typeof QRCode !== 'undefined') {
                    const el_<?= $st['id'] ?> = document.getElementById('batchQr_<?= $st['id'] ?>');
                    if (el_<?= $st['id'] ?>) {
                        new QRCode(el_<?= $st['id'] ?>, {
                            text: "<?= $payload ?>",
                            width: 65,
                            height: 65,
                            colorDark : "#0f172a",
                            colorLight : "#ffffff"
                        });
                    }
                }

                if (typeof JsBarcode !== 'undefined') {
                    try {
                        JsBarcode("#batchBarcode_<?= $st['id'] ?>", "<?= $payload ?>", {
                            format: "CODE128",
                            lineColor: "#0f172a",
                            width: 1.2,
                            height: 22,
                            fontSize: 8,
                            displayValue: true
                        });
                    } catch(e) {}
                }
            <?php endforeach; ?>
        });
        </script>
    <?php endif; ?>

</div>

<?php require_once __DIR__ . "/../includes/footer.php"; ?>
