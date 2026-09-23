<?php
session_start();
require_once __DIR__ . "/../../config/database.php";

requireRole(['guru', 'staf', 'administrator']);

$user_id = (int)$_SESSION['user_id'];
$user_role = $_SESSION['user_role'] ?? 'guru';

// -------------------------------------------------------------
// 1. AJAX HANDLER: PENCATATAN PRESENSI DARI QR / BARCODE SCAN
// -------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'scan_attend') {
    header('Content-Type: application/json');

    if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
        echo json_encode(['success' => false, 'message' => 'Token keamanan CSRF tidak valid.']);
        exit;
    }

    $qr_code = trim($_POST['qr_code'] ?? '');
    $attend_mode = trim($_POST['attend_mode'] ?? 'masuk'); // 'masuk' atau 'pulang'

    if (empty($qr_code)) {
        echo json_encode(['success' => false, 'message' => 'Kode QR atau Barcode kosong.']);
        exit;
    }

    // Ekstrak kode jika berformat ID-X atau STUDENT:X atau NISN murni
    $cleaned_code = $qr_code;
    if (strpos($qr_code, ':') !== false) {
        $parts = explode(':', $qr_code);
        $cleaned_code = end($parts);
    }
    $cleaned_code = str_replace('ID-', '', $cleaned_code);

    // Cari siswa berdasarkan NISN atau ID
    $stmt_find = $pdo->prepare("
        SELECT u.id, u.name, u.nisn, u.gender, c.name as class_name
        FROM users u
        LEFT JOIN classes c ON u.class_id = c.id
        WHERE (u.nisn = ? OR u.id = ?) AND u.role = 'siswa'
        LIMIT 1
    ");
    $stmt_find->execute([$cleaned_code, is_numeric($cleaned_code) ? (int)$cleaned_code : 0]);
    $student = $stmt_find->fetch();

    if (!$student) {
        echo json_encode([
            'success' => false,
            'message' => "Data siswa tidak ditemukan untuk kode: $cleaned_code"
        ]);
        exit;
    }

    $today = date('Y-m-d');
    $time_now = date('H:i:s');
    $time_formatted = date('H:i');

    // Tentukan status keterlambatan jika mode masuk (batas jam 07:15)
    $is_late = false;
    if ($attend_mode === 'masuk') {
        $limit_time = '07:15:00';
        $is_late = ($time_now > $limit_time);
        if ($is_late) {
            $notes_text = "Terlambat Masuk (Pindai Pukul $time_formatted WIB)";
            $status_msg = "TERLAMBAT MASUK ($time_formatted WIB)";
        } else {
            $notes_text = "Hadir Tepat Waktu (Pindai Pukul $time_formatted WIB)";
            $status_msg = "HADIR TEPAT WAKTU ($time_formatted WIB)";
        }
    } else {
        $notes_text = "Presensi Pulang Sekolah (Pindai Pukul $time_formatted WIB)";
        $status_msg = "PULANG SEKOLAH ($time_formatted WIB)";
    }

    // Cek apakah sudah ada presensi hari ini
    $stmt_chk = $pdo->prepare("SELECT id, status, notes FROM student_attendance WHERE student_id = ? AND date = ?");
    $stmt_chk->execute([$student['id'], $today]);
    $existing = $stmt_chk->fetch();

    if ($existing) {
        $merged_notes = $existing['notes'] ? ($existing['notes'] . " | " . $notes_text) : $notes_text;
        $stmt_up = $pdo->prepare("
            UPDATE student_attendance 
            SET status = 'hadir', notes = ?, recorded_by = ?, updated_at = NOW() 
            WHERE id = ?
        ");
        $stmt_up->execute([$merged_notes, $user_id, $existing['id']]);
    } else {
        $stmt_ins = $pdo->prepare("
            INSERT INTO student_attendance (student_id, date, status, notes, recorded_by)
            VALUES (?, ?, 'hadir', ?, ?)
        ");
        $stmt_ins->execute([$student['id'], $today, $notes_text, $user_id]);
    }

    logActivity($pdo, 'SCAN_ATTENDANCE', "Pindai presensi [$attend_mode] {$student['name']} ($cleaned_code)");

    echo json_encode([
        'success'      => true,
        'student_id'   => $student['id'],
        'student_name' => $student['name'],
        'nisn'         => $student['nisn'],
        'class_name'   => $student['class_name'] ?: 'Reguler',
        'gender'       => $student['gender'] ?? 'L',
        'time'         => $time_formatted,
        'mode'         => $attend_mode,
        'is_late'      => $is_late,
        'message'      => $status_msg
    ]);
    exit;
}

// -------------------------------------------------------------
// 2. QUERY DAFTAR HADIR HARI INI
// -------------------------------------------------------------
$today = date('Y-m-d');
$stmt_today = $pdo->prepare("
    SELECT sa.*, u.name as student_name, u.nisn, c.name as class_name, rec.name as recorder_name
    FROM student_attendance sa
    JOIN users u ON sa.student_id = u.id
    LEFT JOIN classes c ON u.class_id = c.id
    JOIN users rec ON sa.recorded_by = rec.id
    WHERE sa.date = ?
    ORDER BY sa.updated_at DESC LIMIT 30
");
$stmt_today->execute([$today]);
$today_records = $stmt_today->fetchAll();

$page_title = "Scanner Presensi Cepat QR & Barcode";
require_once __DIR__ . "/../includes/header.php";
?>

<script src="https://unpkg.com/html5-qrcode"></script>

<div class="space-y-6">

    <!-- Header Action Bar -->
    <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <div>
            <div class="flex items-center gap-3">
                <span class="flex h-11 w-11 items-center justify-center rounded-2xl bg-emerald-500/20 text-emerald-400 text-xl border border-emerald-500/30 shadow-lg shadow-emerald-500/10">
                    <i class="fa-solid fa-camera"></i>
                </span>
                <div>
                    <h1 class="text-2xl font-bold tracking-tight text-white">Scanner Presensi Cepat Digital</h1>
                    <p class="text-xs sm:text-sm text-slate-400">
                        Mendukung Kamera HP (Kamera Depan/Belakang), Barcode Laser USB/Bluetooth, dan QR Code.
                    </p>
                </div>
            </div>
        </div>

        <div class="flex flex-wrap items-center gap-2">
            <a href="attendance.php" class="inline-flex items-center gap-2 rounded-xl border border-white/10 bg-slate-800 px-3.5 py-2 text-xs font-semibold text-slate-200 hover:bg-slate-700 transition">
                <i class="fa-solid fa-calendar-days"></i> Rekap Presensi
            </a>
            <a href="qr_card.php" class="inline-flex items-center gap-2 rounded-xl bg-blue-600 px-4 py-2 text-xs font-bold text-white shadow-lg shadow-blue-500/25 hover:bg-blue-500 transition">
                <i class="fa-solid fa-id-card"></i> Cetak Kartu Pelajar
            </a>
        </div>
    </div>

    <!-- Scanner & Today's Attendance Layout -->
    <div class="grid grid-cols-1 lg:grid-cols-12 gap-6">

        <!-- Left: Camera Viewport & Controls (7 Cols) -->
        <div class="lg:col-span-7 space-y-4">
            
            <div class="rounded-3xl border border-white/10 bg-slate-900/60 p-5 shadow-2xl backdrop-blur space-y-4">
                
                <!-- Mode Presensi Pill Selector -->
                <div class="flex flex-wrap items-center justify-between gap-3 border-b border-white/10 pb-3">
                    <div class="flex items-center gap-2">
                        <span class="text-xs font-bold text-slate-300 uppercase tracking-wider">Mode:</span>
                        <div class="inline-flex rounded-xl p-1 bg-slate-950 border border-white/10">
                            <button type="button" onclick="setAttendMode('masuk')" id="btnModeMasuk" class="inline-flex items-center gap-1.5 rounded-lg px-3 py-1 text-xs font-bold bg-emerald-600 text-white shadow transition cursor-pointer">
                                <i class="fa-solid fa-circle-play text-emerald-300"></i> Masuk Pagi (Batas 07:15)
                            </button>
                            <button type="button" onclick="setAttendMode('pulang')" id="btnModePulang" class="inline-flex items-center gap-1.5 rounded-lg px-3 py-1 text-xs font-bold text-slate-400 hover:text-white transition cursor-pointer">
                                <i class="fa-solid fa-house text-blue-400"></i> Pulang Sekolah
                            </button>
                        </div>
                    </div>

                    <span id="scanStatusIndicator" class="text-xs font-mono text-emerald-400 font-semibold flex items-center gap-1.5">
                        <span class="h-2 w-2 rounded-full bg-emerald-400 animate-ping"></span> Siap Memindai
                    </span>
                </div>

                <!-- Video Viewport -->
                <div class="relative overflow-hidden rounded-2xl bg-black border border-white/10 flex flex-col items-center justify-center min-h-[330px]">
                    <div id="qr-reader" class="w-full"></div>
                </div>

                <!-- Control Buttons: Start, Stop, Flip Camera -->
                <div class="flex flex-wrap items-center justify-center gap-2.5 pt-1">
                    <button type="button" id="btnStartScan" onclick="startScanner()" class="inline-flex items-center gap-2 rounded-xl bg-emerald-600 px-4 py-2 text-xs sm:text-sm font-bold text-white shadow-md hover:bg-emerald-500 transition cursor-pointer">
                        <i class="fa-solid fa-play"></i> Nyalakan Kamera
                    </button>
                    <button type="button" id="btnFlipCamera" onclick="flipCamera()" class="inline-flex items-center gap-2 rounded-xl border border-blue-500/30 bg-blue-500/10 px-3.5 py-2 text-xs sm:text-sm font-semibold text-blue-300 hover:bg-blue-500/20 transition cursor-pointer hidden">
                        <i class="fa-solid fa-camera-rotate"></i> Ganti Kamera (Depan/Belakang)
                    </button>
                    <button type="button" id="btnStopScan" onclick="stopScanner()" class="inline-flex items-center gap-2 rounded-xl border border-white/10 bg-slate-800 px-4 py-2 text-xs sm:text-sm font-semibold text-slate-300 hover:bg-slate-700 transition cursor-pointer hidden">
                        <i class="fa-solid fa-stop"></i> Hentikan Kamera
                    </button>
                </div>

                <!-- Grand Toast Feedback (Terlihat Jelas dari Jarak 2 Meter) -->
                <div id="grandFeedbackCard" class="hidden rounded-2xl border p-5 shadow-2xl transition-all duration-300">
                    <div class="flex items-center gap-4">
                        <div id="grandFeedbackIcon" class="flex h-16 w-16 items-center justify-center rounded-2xl text-2xl font-bold shrink-0">
                            <i class="fa-solid fa-circle-check"></i>
                        </div>
                        <div class="flex-1 min-w-0">
                            <span id="grandFeedbackBadge" class="inline-block px-2.5 py-0.5 rounded-full text-[10px] font-black uppercase tracking-wider mb-1">
                                HADIR TEPAT WAKTU
                            </span>
                            <h3 id="grandFeedbackName" class="text-lg sm:text-xl font-black text-white leading-tight truncate">
                                Ahmad Fauzi
                            </h3>
                            <p id="grandFeedbackBio" class="text-xs text-slate-300 mt-0.5">
                                NISN: 0081234567 • Kelas X MIPA 1
                            </p>
                        </div>
                        <div class="text-right shrink-0">
                            <span id="grandFeedbackTime" class="text-xl sm:text-2xl font-black text-white font-mono block">
                                07:05
                            </span>
                            <span class="text-[10px] text-slate-400 font-mono">WIB</span>
                        </div>
                    </div>
                </div>

                <!-- USB / Bluetooth Barcode Laser Indicator & Manual Fallback -->
                <div class="border-t border-white/10 pt-4 space-y-2">
                    <div class="flex items-center justify-between text-xs text-slate-400">
                        <span class="flex items-center gap-1.5 font-medium">
                            <span class="text-blue-400"><i class="fa-solid fa-barcode"></i></span> <strong>Scanner Barcode Laser USB:</strong> Otomatis aktif di latar belakang (cukup tembak barcode pada kartu).
                        </span>
                    </div>

                    <form onsubmit="handleManualSubmit(event)" class="flex gap-2 pt-1">
                        <input type="text" id="manualNisnInput" placeholder="Ketik atau tempelkan NISN siswa secara manual..." class="flex-1 rounded-xl border border-white/10 bg-slate-950 px-3.5 py-2 text-xs sm:text-sm text-white focus:border-blue-500 focus:outline-none font-mono">
                        <button type="submit" class="rounded-xl bg-blue-600 px-4 py-2 text-xs sm:text-sm font-semibold text-white hover:bg-blue-500 transition cursor-pointer">
                            Kirim
                        </button>
                    </form>
                </div>
            </div>

        </div>

        <!-- Right: Real-time Attendance Feed Today (5 Cols) -->
        <div class="lg:col-span-5 space-y-4">
            <div class="rounded-3xl border border-white/10 bg-slate-900/60 p-5 shadow-2xl backdrop-blur flex flex-col h-full">
                <div class="flex items-center justify-between border-b border-white/10 pb-3 mb-4">
                    <div>
                        <h3 class="text-sm font-bold uppercase tracking-wider text-slate-200">Presensi Hari Ini</h3>
                        <p class="text-xs text-slate-400"><?= date('l, d F Y') ?></p>
                    </div>
                    <span id="todayCountBadge" class="rounded-lg bg-emerald-500/10 border border-emerald-500/30 px-2.5 py-1 text-xs font-bold text-emerald-300">
                        <?= count($today_records) ?> Tercatat
                    </span>
                </div>

                <div id="todayRecordsList" class="space-y-2.5 overflow-y-auto max-h-[520px] pr-1">
                    <?php if (empty($today_records)): ?>
                        <div id="noRecordsNotice" class="py-12 text-center text-xs text-slate-500">
                            Belum ada aktivitas presensi yang tercatat hari ini.
                        </div>
                    <?php else: ?>
                        <?php foreach ($today_records as $rec): ?>
                            <div class="rounded-xl border border-white/5 bg-slate-950/60 p-3 hover:border-white/15 transition flex items-center justify-between">
                                <div>
                                    <h4 class="text-sm font-bold text-white"><?= htmlspecialchars($rec['student_name']) ?></h4>
                                    <p class="text-[11px] text-slate-400">NISN: <?= htmlspecialchars($rec['nisn']) ?> • Kelas <?= htmlspecialchars($rec['class_name'] ?: 'Reguler') ?></p>
                                    <p class="text-[10px] text-slate-500 italic mt-0.5"><?= htmlspecialchars($rec['notes'] ?: 'Hadir') ?></p>
                                </div>
                                <div class="text-right">
                                    <span class="rounded-md border px-2 py-0.5 text-[10px] font-bold border-emerald-500/30 bg-emerald-500/10 text-emerald-300">
                                        HADIR
                                    </span>
                                    <p class="text-[10px] text-slate-400 font-mono mt-1">
                                        <?= date('H:i', strtotime($rec['updated_at'])) ?> WIB
                                    </p>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>

    </div>

</div>

<!-- CSRF Token Hidden Holder -->
<input type="hidden" id="csrfToken" value="<?= htmlspecialchars(generateCsrfToken()) ?>">

<script>
let html5QrCode = null;
let isProcessing = false;
let currentCameraMode = "environment"; // default kamera belakang HP
let currentAttendMode = "masuk"; // default masuk

function setAttendMode(mode) {
    currentAttendMode = mode;
    const btnMasuk = document.getElementById('btnModeMasuk');
    const btnPulang = document.getElementById('btnModePulang');
    if (mode === 'masuk') {
        btnMasuk.className = "rounded-lg px-3 py-1 text-xs font-bold bg-emerald-600 text-white shadow transition cursor-pointer";
        btnPulang.className = "rounded-lg px-3 py-1 text-xs font-bold text-slate-400 hover:text-white transition cursor-pointer";
    } else {
        btnPulang.className = "rounded-lg px-3 py-1 text-xs font-bold bg-blue-600 text-white shadow transition cursor-pointer";
        btnMasuk.className = "rounded-lg px-3 py-1 text-xs font-bold text-slate-400 hover:text-white transition cursor-pointer";
    }
}

// Audio Chime Synthesizer via Web Audio API (No external sound file needed)
function playBeep(success = true) {
    try {
        const audioCtx = new (window.AudioContext || window.webkitAudioContext)();
        const osc = audioCtx.createOscillator();
        const gain = audioCtx.createGain();
        osc.connect(gain);
        gain.connect(audioCtx.destination);

        if (success) {
            osc.frequency.setValueAtTime(587.33, audioCtx.currentTime); // D5
            osc.frequency.setValueAtTime(880, audioCtx.currentTime + 0.1); // A5
            gain.gain.setValueAtTime(0.3, audioCtx.currentTime);
            gain.gain.exponentialRampToValueAtTime(0.01, audioCtx.currentTime + 0.35);
            osc.start(audioCtx.currentTime);
            osc.stop(audioCtx.currentTime + 0.35);
        } else {
            osc.frequency.setValueAtTime(220, audioCtx.currentTime); // Low A3
            gain.gain.setValueAtTime(0.3, audioCtx.currentTime);
            gain.gain.exponentialRampToValueAtTime(0.01, audioCtx.currentTime + 0.3);
            osc.start(audioCtx.currentTime);
            osc.stop(audioCtx.currentTime + 0.3);
        }
    } catch (e) {}
}

function startScanner() {
    if (!html5QrCode) {
        html5QrCode = new Html5Qrcode("qr-reader");
    }

    const config = { fps: 12, qrbox: { width: 250, height: 250 } };

    html5QrCode.start(
        { facingMode: currentCameraMode },
        config,
        onScanSuccess
    ).then(() => {
        document.getElementById('btnStartScan').classList.add('hidden');
        document.getElementById('btnStopScan').classList.remove('hidden');
        document.getElementById('btnFlipCamera').classList.remove('hidden');
        document.getElementById('scanStatusIndicator').innerHTML = '<span class="h-2 w-2 rounded-full bg-emerald-400 animate-ping"></span> Kamera Aktif';
    }).catch(err => {
        alert("Tidak dapat menyalakan kamera: " + err + ". Silakan gunakan input manual atau scanner USB laser.");
    });
}

function flipCamera() {
    currentCameraMode = (currentCameraMode === "environment") ? "user" : "environment";
    if (html5QrCode && html5QrCode.isScanning) {
        html5QrCode.stop().then(() => {
            startScanner();
        });
    }
}

function stopScanner() {
    if (html5QrCode) {
        html5QrCode.stop().then(() => {
            document.getElementById('btnStartScan').classList.remove('hidden');
            document.getElementById('btnStopScan').classList.add('hidden');
            document.getElementById('btnFlipCamera').classList.add('hidden');
            document.getElementById('scanStatusIndicator').innerText = 'Kamera Dimatikan';
        });
    }
}

function onScanSuccess(decodedText) {
    if (isProcessing) return;
    isProcessing = true;

    processAttendance(decodedText);

    // Debounce scan berikutnya selama 2.5 detik
    setTimeout(() => {
        isProcessing = false;
    }, 2500);
}

function handleManualSubmit(e) {
    e.preventDefault();
    const val = document.getElementById('manualNisnInput').value.trim();
    if (val) {
        processAttendance(val);
        document.getElementById('manualNisnInput').value = '';
    }
}

function processAttendance(qrCode) {
    const csrfToken = document.getElementById('csrfToken').value;

    fetch('scan_qr.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: new URLSearchParams({
            action: 'scan_attend',
            qr_code: qrCode,
            attend_mode: currentAttendMode,
            csrf_token: csrfToken
        })
    })
    .then(res => res.json())
    .then(data => {
        const card = document.getElementById('grandFeedbackCard');
        const icon = document.getElementById('grandFeedbackIcon');
        const badge = document.getElementById('grandFeedbackBadge');
        const name = document.getElementById('grandFeedbackName');
        const bio = document.getElementById('grandFeedbackBio');
        const time = document.getElementById('grandFeedbackTime');

        card.classList.remove('hidden');

        if (data.success) {
            playBeep(true);
            if (data.is_late) {
                card.className = "rounded-2xl border border-amber-500/40 bg-gradient-to-r from-amber-950/80 to-slate-900 p-5 shadow-2xl transition duration-300";
                icon.className = "flex h-16 w-16 items-center justify-center rounded-2xl bg-amber-500/20 text-amber-300 text-2xl shrink-0";
                icon.innerHTML = '<i class="fa-solid fa-triangle-exclamation"></i>';
                badge.className = "inline-block px-2.5 py-0.5 rounded-full text-[10px] font-black uppercase tracking-wider mb-1 bg-amber-500/20 border border-amber-500/40 text-amber-300";
                badge.innerText = "TERLAMBAT MASUK";
            } else if (data.mode === 'pulang') {
                card.className = "rounded-2xl border border-blue-500/40 bg-gradient-to-r from-blue-950/80 to-slate-900 p-5 shadow-2xl transition duration-300";
                icon.className = "flex h-16 w-16 items-center justify-center rounded-2xl bg-blue-500/20 text-blue-300 text-2xl shrink-0";
                icon.innerHTML = '<i class="fa-solid fa-house"></i>';
                badge.className = "inline-block px-2.5 py-0.5 rounded-full text-[10px] font-black uppercase tracking-wider mb-1 bg-blue-500/20 border border-blue-500/40 text-blue-300";
                badge.innerText = "PULANG SEKOLAH";
            } else {
                card.className = "rounded-2xl border border-emerald-500/40 bg-gradient-to-r from-emerald-950/80 to-slate-900 p-5 shadow-2xl transition duration-300";
                icon.className = "flex h-16 w-16 items-center justify-center rounded-2xl bg-emerald-500/20 text-emerald-300 text-2xl shrink-0";
                icon.innerHTML = '<i class="fa-solid fa-circle-check"></i>';
                badge.className = "inline-block px-2.5 py-0.5 rounded-full text-[10px] font-black uppercase tracking-wider mb-1 bg-emerald-500/20 border border-emerald-500/40 text-emerald-300";
                badge.innerText = "HADIR TEPAT WAKTU";
            }

            name.innerText = data.student_name;
            bio.innerText = `NISN: ${data.nisn} • Kelas ${data.class_name}`;
            time.innerText = data.time;

            prependTodayRecord(data);
        } else {
            playBeep(false);
            card.className = "rounded-2xl border border-rose-500/40 bg-gradient-to-r from-rose-950/80 to-slate-900 p-5 shadow-2xl transition duration-300";
            icon.className = "flex h-16 w-16 items-center justify-center rounded-2xl bg-rose-500/20 text-rose-300 text-2xl shrink-0";
            icon.innerHTML = '<i class="fa-solid fa-circle-xmark"></i>';
            badge.className = "inline-block px-2.5 py-0.5 rounded-full text-[10px] font-black uppercase tracking-wider mb-1 bg-rose-500/20 border border-rose-500/40 text-rose-300";
            badge.innerText = "PEMINDAIAN GAGAL";
            name.innerText = "Data Siswa Tidak Dikenal";
            bio.innerText = "Kode: " + qrCode;
            time.innerText = "--:--";
        }
    })
    .catch(err => {
        playBeep(false);
        console.error(err);
    });
}

function prependTodayRecord(student) {
    const list = document.getElementById('todayRecordsList');
    const notice = document.getElementById('noRecordsNotice');
    if (notice) notice.remove();

    const row = document.createElement('div');
    const badgeColor = student.is_late 
        ? "border-amber-500/30 bg-amber-500/10 text-amber-300"
        : (student.mode === 'pulang' ? "border-blue-500/30 bg-blue-500/10 text-blue-300" : "border-emerald-500/30 bg-emerald-500/10 text-emerald-300");

    row.className = "rounded-xl border border-emerald-500/30 bg-emerald-950/20 p-3 flex items-center justify-between transition animate-pulse";
    row.innerHTML = `
        <div>
            <h4 class="text-sm font-bold text-white">${student.student_name}</h4>
            <p class="text-[11px] text-slate-400">NISN: ${student.nisn} • Kelas ${student.class_name}</p>
            <p class="text-[10px] text-slate-400 italic mt-0.5">${student.message}</p>
        </div>
        <div class="text-right">
            <span class="rounded-md border px-2 py-0.5 text-[10px] font-bold ${badgeColor}">
                HADIR
            </span>
            <p class="text-[10px] text-slate-300 font-mono mt-1">
                ${student.time} WIB
            </p>
        </div>
    `;

    list.prepend(row);
    setTimeout(() => {
        row.classList.remove('animate-pulse');
    }, 1500);
}

// -------------------------------------------------------------
// Global USB / Bluetooth Barcode Laser Scanner Auto-Listener
// -------------------------------------------------------------
let barcodeBuffer = '';
let lastKeyTime = Date.now();

window.addEventListener('keydown', function(e) {
    // Jangan tangkap jika user sedang mengetik di input manual biasa
    if (e.target.tagName === 'INPUT' || e.target.tagName === 'TEXTAREA') return;

    const currentTime = Date.now();
    if (currentTime - lastKeyTime > 150) {
        barcodeBuffer = '';
    }
    lastKeyTime = currentTime;

    if (e.key === 'Enter') {
        if (barcodeBuffer.length >= 3) {
            processAttendance(barcodeBuffer.trim());
            barcodeBuffer = '';
        }
    } else if (e.key.length === 1) {
        barcodeBuffer += e.key;
    }
});
</script>

<?php require_once __DIR__ . "/../includes/footer.php"; ?>
