<?php
session_start();
require_once __DIR__ . "/../../config/database.php";

requireRole(['administrator']);

$message = "";
$message_type = "";

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_settings') {
    if (!validateCsrfToken()) {
        $message = "Token keamanan tidak valid.";
        $message_type = "error";
    } else {
        $fields = [
            'school_name', 'school_address', 'school_phone', 'school_email',
            'school_website', 'headmaster_name', 'headmaster_nip', 'headmaster_title', 'academic_year', 'academic_semester', 'school_logo',
            'ppdb_status', 'ppdb_wave_name', 'ppdb_quota', 'ppdb_start_date', 'ppdb_end_date', 'ppdb_closed_message',
            'ppdb_next_wave_name', 'ppdb_next_open_date', 'ppdb_announcement_date', 'ppdb_re_registration_date', 'ppdb_contact_phone',
            'school_latitude', 'school_longitude', 'school_radius_meters',
            'tu_head_name', 'tu_head_nip', 'tu_head_title',
            'bk_coordinator_name', 'bk_coordinator_nip', 'bk_coordinator_title',
            'treasurer_name', 'treasurer_nip', 'treasurer_title',
            'curriculum_head_name', 'curriculum_head_nip', 'curriculum_head_title',
            'library_head_name', 'library_head_nip', 'library_head_title',
            'signature_mode', 'doc_city', 'doc_verification_enabled', 'show_school_stamp'
        ];

        foreach ($fields as $f) {
            if (isset($_POST[$f])) {
                updateSchoolSetting($pdo, $f, trim($_POST[$f]));
            }
        }

        // Upload Berkas Gambar TTD & Stempel
        $file_mappings = [
            'headmaster_signature_file' => 'headmaster_signature_image',
            'tu_head_signature_file' => 'tu_head_signature_image',
            'bk_coordinator_signature_file' => 'bk_coordinator_signature_image',
            'treasurer_signature_file' => 'treasurer_signature_image',
            'curriculum_head_signature_file' => 'curriculum_head_signature_image',
            'library_head_signature_file' => 'library_head_signature_image',
            'school_stamp_file' => 'school_stamp_image',
        ];

        $upload_dir = __DIR__ . '/../../uploads/signatures/';
        if (!is_dir($upload_dir)) {
            mkdir($upload_dir, 0755, true);
        }

        foreach ($file_mappings as $input_name => $setting_key) {
            if (isset($_FILES[$input_name]) && $_FILES[$input_name]['error'] === UPLOAD_ERR_OK) {
                $file_ext = strtolower(pathinfo($_FILES[$input_name]['name'], PATHINFO_EXTENSION));
                if (in_array($file_ext, ['png', 'jpg', 'jpeg', 'svg', 'webp'], true)) {
                    // Sanitasi berkas SVG untuk mencegah Stored XSS via <script> atau event handlers
                    if ($file_ext === 'svg') {
                        $svg_content = (string) file_get_contents($_FILES[$input_name]['tmp_name']);
                        $svg_content = preg_replace('/<script\b[^>]*>(.*?)<\/script>/is', '', $svg_content);
                        $svg_content = preg_replace('/\bon\w+\s*=\s*(["\']).*?\1/is', '', $svg_content);
                        $svg_content = preg_replace('/\bon\w+\s*=\s*[^>\s]+/is', '', $svg_content);
                        $svg_content = preg_replace('/javascript:/i', 'blocked:', $svg_content);
                        $svg_content = preg_replace('/<foreignObject\b[^>]*>(.*?)<\/foreignObject>/is', '', $svg_content);
                        file_put_contents($_FILES[$input_name]['tmp_name'], $svg_content);
                    }

                    $new_filename = $setting_key . '_' . time() . '_' . bin2hex(random_bytes(3)) . '.' . $file_ext;
                    $destination = $upload_dir . $new_filename;
                    if (move_uploaded_file($_FILES[$input_name]['tmp_name'], $destination)) {
                        updateSchoolSetting($pdo, $setting_key, 'uploads/signatures/' . $new_filename);
                    }
                }
            }
        }

        if (!empty($_POST['reset_target'])) {
            $target = $_POST['reset_target'];
            $defaults_map = [
                'headmaster_signature_image' => 'uploads/signatures/default_headmaster_ttd.svg',
                'tu_head_signature_image' => 'uploads/signatures/default_tu_ttd.svg',
                'bk_coordinator_signature_image' => 'uploads/signatures/default_bk_ttd.svg',
                'treasurer_signature_image' => 'uploads/signatures/default_tu_ttd.svg',
                'curriculum_head_signature_image' => 'uploads/signatures/default_headmaster_ttd.svg',
                'library_head_signature_image' => 'uploads/signatures/default_library_ttd.svg',
                'school_stamp_image' => 'uploads/signatures/default_stamp.svg',
            ];
            if (isset($defaults_map[$target])) {
                updateSchoolSetting($pdo, $target, $defaults_map[$target]);
                logActivity($pdo, 'RESET_SIGNATURE', "Mereset berkas tanda tangan/stempel $target ke bawaan");
            }
        }

        logActivity($pdo, 'UPDATE_SETTINGS', 'Memperbarui profil lembaga, TTD Digital & Stempel, PPDB Online, dan Geofencing GPS');
        $message = "Pengaturan identitas sekolah, TTD Digital & Stempel, PPDB, dan radius GPS berhasil disimpan!";
        $message_type = "success";
    }
}

// Handler Reset Berkas TTD / Stempel ke Standar Bawaan
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'reset_signature') {
    if (!validateCsrfToken()) {
        $message = "Token keamanan tidak valid.";
        $message_type = "error";
    } else {
        $target = $_POST['target_key'] ?? '';
        $defaults_map = [
            'headmaster_signature_image' => 'uploads/signatures/default_headmaster_ttd.svg',
            'tu_head_signature_image' => 'uploads/signatures/default_tu_ttd.svg',
            'bk_coordinator_signature_image' => 'uploads/signatures/default_bk_ttd.svg',
            'treasurer_signature_image' => 'uploads/signatures/default_tu_ttd.svg',
            'curriculum_head_signature_image' => 'uploads/signatures/default_headmaster_ttd.svg',
            'library_head_signature_image' => 'uploads/signatures/default_library_ttd.svg',
            'school_stamp_image' => 'uploads/signatures/default_stamp.svg',
        ];
        if (isset($defaults_map[$target])) {
            updateSchoolSetting($pdo, $target, $defaults_map[$target]);
            logActivity($pdo, 'RESET_SIGNATURE', "Mereset berkas tanda tangan/stempel $target ke bawaan");
            $message = "Berkas tanda tangan/stempel berhasil dikembalikan ke grafis bawaan resmi!";
            $message_type = "success";
        }
    }
}

$school_info = getSchoolSettings($pdo);

$page_title = "Pengaturan Sekolah & Lembaga";
require_once __DIR__ . "/../includes/header.php";
?>

<div class="mb-8 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
    <div>
        <div class="flex items-center gap-3">
            <span class="flex h-10 w-10 items-center justify-center rounded-2xl bg-slate-800 text-slate-200 text-lg border border-white/10 shadow-lg">
                <i class="fa-solid fa-gear"></i>
            </span>
            <div>
                <h1 class="text-2xl font-bold text-white tracking-tight">Pengaturan Profil Lembaga & PPDB</h1>
                <p class="text-sm text-slate-400">Identitas sekolah, periode akademik, serta kontrol buka/tutup dan kuota pendaftaran siswa baru</p>
            </div>
        </div>
    </div>

    <div class="flex items-center gap-3">
        <a href="external_apis.php" class="rounded-xl border border-cyan-500/20 bg-cyan-500/10 hover:bg-cyan-500/20 px-4 py-2 text-xs font-semibold text-cyan-300 transition flex items-center gap-2">
            <i class="fa-solid fa-cloud-arrow-up"></i> Kelola API Luar
        </a>
        <a href="ppdb.php" class="rounded-xl border border-emerald-500/20 bg-emerald-500/10 hover:bg-emerald-500/20 px-4 py-2 text-xs font-semibold text-emerald-300 transition flex items-center gap-2">
            <i class="fa-solid fa-graduation-cap"></i> Panel PPDB
        </a>
        <a href="audit_logs.php" class="rounded-xl border border-white/10 bg-white/5 hover:bg-white/10 px-4 py-2 text-xs font-semibold text-slate-300 transition flex items-center gap-2">
            <i class="fa-solid fa-shield-halved text-blue-400"></i> Audit Log
        </a>
    </div>
</div>

<?php if ($message): ?>
    <div class="mb-6 rounded-2xl border p-4 text-sm flex items-center justify-between <?= $message_type === 'success' ? 'border-emerald-500/30 bg-emerald-500/10 text-emerald-300' : 'border-rose-500/30 bg-rose-500/10 text-rose-300' ?>">
        <span><?= htmlspecialchars($message) ?></span>
        <button onclick="this.parentElement.remove()" class="text-xs font-semibold opacity-70 hover:opacity-100 cursor-pointer"><i class="fa-solid fa-xmark"></i></button>
    </div>
<?php endif; ?>

<div class="max-w-4xl rounded-3xl border border-white/10 bg-slate-900/60 p-6 sm:p-8 shadow-xl backdrop-blur">
    <form method="POST" enctype="multipart/form-data" class="space-y-6">
        <?= csrfField() ?>
        <input type="hidden" id="formActionField" name="action" value="save_settings">
        <input type="hidden" id="resetTargetKey" name="target_key" value="">

        <div>
            <h3 class="text-sm font-bold uppercase tracking-wider text-blue-400 mb-4 border-b border-white/10 pb-2">
                1. Identitas Sekolah
            </h3>
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                <div class="sm:col-span-2">
                    <label class="block text-xs font-semibold text-slate-300 mb-1.5">Nama Resmi Lembaga / Sekolah *</label>
                    <input type="text" name="school_name" required value="<?= htmlspecialchars($school_info['school_name']) ?>" 
                           class="w-full rounded-xl border border-white/10 bg-slate-950 px-4 py-2.5 text-sm text-white focus:border-blue-500 focus:outline-none">
                </div>
                <div>
                    <label class="block text-xs font-semibold text-slate-300 mb-1.5">Simbol / Logo Singkat</label>
                    <input type="text" name="school_logo" value="<?= htmlspecialchars($school_info['school_logo']) ?>" 
                           class="w-full rounded-xl border border-white/10 bg-slate-950 px-4 py-2.5 text-sm text-white focus:border-blue-500 focus:outline-none text-center">
                </div>
            </div>

            <div class="mt-4">
                <label class="block text-xs font-semibold text-slate-300 mb-1.5">Alamat Lengkap Lembaga *</label>
                <textarea name="school_address" rows="2" required 
                          class="w-full rounded-xl border border-white/10 bg-slate-950 px-4 py-2 text-sm text-white focus:border-blue-500 focus:outline-none"><?= htmlspecialchars($school_info['school_address']) ?></textarea>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 mt-4">
                <div>
                    <label class="block text-xs font-semibold text-slate-300 mb-1.5">No. Telepon Sekolah</label>
                    <input type="text" name="school_phone" value="<?= htmlspecialchars($school_info['school_phone']) ?>" 
                           class="w-full rounded-xl border border-white/10 bg-slate-950 px-4 py-2 text-sm text-white focus:border-blue-500 focus:outline-none">
                </div>
                <div>
                    <label class="block text-xs font-semibold text-slate-300 mb-1.5">Email Resmi</label>
                    <input type="email" name="school_email" value="<?= htmlspecialchars($school_info['school_email']) ?>" 
                           class="w-full rounded-xl border border-white/10 bg-slate-950 px-4 py-2 text-sm text-white focus:border-blue-500 focus:outline-none">
                </div>
                <div>
                    <label class="block text-xs font-semibold text-slate-300 mb-1.5">Website Sekolah</label>
                    <input type="text" name="school_website" value="<?= htmlspecialchars($school_info['school_website']) ?>" 
                           class="w-full rounded-xl border border-white/10 bg-slate-950 px-4 py-2 text-sm text-white focus:border-blue-500 focus:outline-none">
                </div>
            </div>
        </div>

        <div class="pt-4">
            <h3 class="text-sm font-bold uppercase tracking-wider text-purple-400 mb-4 border-b border-white/10 pb-2">
                2. Pimpinan & Periode Akademik
            </h3>
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                    <label class="block text-xs font-semibold text-slate-300 mb-1.5">Nama Kepala Sekolah *</label>
                    <input type="text" name="headmaster_name" required value="<?= htmlspecialchars($school_info['headmaster_name']) ?>" 
                           class="w-full rounded-xl border border-white/10 bg-slate-950 px-4 py-2.5 text-sm text-white focus:border-blue-500 focus:outline-none">
                </div>
                <div>
                    <label class="block text-xs font-semibold text-slate-300 mb-1.5">NIP Kepala Sekolah</label>
                    <input type="text" name="headmaster_nip" value="<?= htmlspecialchars($school_info['headmaster_nip']) ?>" 
                           class="w-full rounded-xl border border-white/10 bg-slate-950 px-4 py-2.5 text-sm text-white focus:border-blue-500 focus:outline-none font-mono">
                </div>
            </div>

            <!-- Pengaturan Tahun Ajaran & Semester (Preset Dropdown + Manual Input) -->
            <div class="mt-5 pt-4 border-t border-white/10 space-y-4">
                <div class="flex items-center justify-between">
                    <label class="block text-xs font-bold uppercase tracking-wider text-purple-400">
                        <i class="fa-solid fa-calendar-days mr-1"></i> Periode Tahun Ajaran & Semester Aktif
                    </label>
                    <span class="text-[11px] text-slate-400 italic">Pilih via opsi cepat atau ketik manual bebas</span>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <!-- Kolom 1: Tahun Ajaran -->
                    <div class="space-y-2 p-3.5 rounded-2xl bg-slate-950/60 border border-white/5">
                        <div class="flex items-center justify-between">
                            <label class="text-xs font-semibold text-slate-300">Tahun Ajaran *</label>
                            <span class="text-[10px] text-blue-400 font-mono">Tersimpan: <?= htmlspecialchars($school_info['academic_year'] ?? '2026/2027') ?></span>
                        </div>
                        
                        <!-- Pilihan Cepat Dropdown -->
                        <div class="space-y-1">
                            <span class="text-[10px] text-slate-400 block">Pilihan Cepat:</span>
                            <select id="select_year_preset" onchange="syncAcademicYear(this.value)" class="w-full rounded-xl border border-white/10 bg-slate-900 px-3 py-2 text-xs text-white focus:outline-none focus:border-purple-500">
                                <?php 
                                $cur_y = $school_info['academic_year'] ?? '2026/2027';
                                $years_preset = ['2023/2024', '2024/2025', '2025/2026', '2026/2027', '2027/2028', '2028/2029', '2029/2030'];
                                $is_custom_year = !in_array($cur_y, $years_preset);
                                ?>
                                <?php foreach ($years_preset as $yp): ?>
                                    <option value="<?= $yp ?>" <?= ($cur_y === $yp) ? 'selected' : '' ?>>T.A. <?= $yp ?></option>
                                <?php endforeach; ?>
                                <option value="custom" <?= $is_custom_year ? 'selected' : '' ?>>[Ketik Manual Sendiri...]</option>
                            </select>
                        </div>

                        <!-- Input Manual Bebas (Selalu Ada & Bisa Diedit) -->
                        <div class="space-y-1 pt-1">
                            <span class="text-[10px] text-slate-400 flex items-center justify-between">
                                <span>Input Teks Manual:</span>
                                <span class="text-[10px] text-emerald-400"><i class="fa-solid fa-pen"></i> Bisa ketik manual bebas</span>
                            </span>
                            <input type="text" id="input_academic_year" name="academic_year" required 
                                   value="<?= htmlspecialchars($school_info['academic_year'] ?? '2026/2027') ?>" 
                                   placeholder="Contoh: 2026/2027 atau format khusus" 
                                   class="w-full rounded-xl border border-white/10 bg-slate-900 px-3.5 py-2 text-xs text-white focus:border-purple-500 focus:outline-none font-mono">
                        </div>
                    </div>

                    <!-- Kolom 2: Semester -->
                    <div class="space-y-2 p-3.5 rounded-2xl bg-slate-950/60 border border-white/5">
                        <div class="flex items-center justify-between">
                            <label class="text-xs font-semibold text-slate-300">Semester Berjalan *</label>
                            <span class="text-[10px] text-purple-400 font-mono">Tersimpan: <?= htmlspecialchars($school_info['academic_semester'] ?? 'Ganjil') ?></span>
                        </div>

                        <!-- Pilihan Cepat Dropdown -->
                        <div class="space-y-1">
                            <span class="text-[10px] text-slate-400 block">Pilihan Cepat:</span>
                            <select id="select_sem_preset" onchange="syncAcademicSemester(this.value)" class="w-full rounded-xl border border-white/10 bg-slate-900 px-3 py-2 text-xs text-white focus:outline-none focus:border-purple-500">
                                <?php 
                                $cur_sem = $school_info['academic_semester'] ?? 'Ganjil';
                                $sem_presets = ['Ganjil', 'Genap'];
                                $is_custom_sem = !in_array($cur_sem, $sem_presets);
                                ?>
                                <option value="Ganjil" <?= ($cur_sem === 'Ganjil') ? 'selected' : '' ?>>Semester Ganjil (1)</option>
                                <option value="Genap" <?= ($cur_sem === 'Genap') ? 'selected' : '' ?>>Semester Genap (2)</option>
                                <option value="custom" <?= $is_custom_sem ? 'selected' : '' ?>>[Ketik Manual Sendiri...]</option>
                            </select>
                        </div>

                        <!-- Input Manual Bebas (Selalu Ada & Bisa Diedit) -->
                        <div class="space-y-1 pt-1">
                            <span class="text-[10px] text-slate-400 flex items-center justify-between">
                                <span>Input Teks Manual:</span>
                                <span class="text-[10px] text-emerald-400"><i class="fa-solid fa-pen"></i> Bisa ketik manual bebas</span>
                            </span>
                            <input type="text" id="input_academic_semester" name="academic_semester" required 
                                   value="<?= htmlspecialchars($school_info['academic_semester'] ?? 'Ganjil') ?>" 
                                   placeholder="Contoh: Ganjil, Genap, atau Semester Pendek" 
                                   class="w-full rounded-xl border border-white/10 bg-slate-900 px-3.5 py-2 text-xs text-white focus:border-purple-500 focus:outline-none font-mono">
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <script>
        function syncAcademicYear(val) {
            const inputYear = document.getElementById('input_academic_year');
            if (val !== 'custom') {
                inputYear.value = val;
            } else {
                inputYear.focus();
                inputYear.select();
            }
        }

        function syncAcademicSemester(val) {
            const inputSem = document.getElementById('input_academic_semester');
            if (val !== 'custom') {
                inputSem.value = val;
            } else {
                inputSem.focus();
                inputSem.select();
            }
        }
        </script>

        <!-- 3. PENGATURAN PPDB ONLINE -->
        <div class="pt-4">
            <div class="flex items-center justify-between border-b border-white/10 pb-2 mb-4">
                <h3 class="text-sm font-bold uppercase tracking-wider text-emerald-400 flex items-center gap-2">
                    <i class="fa-solid fa-graduation-cap"></i> 3. Kontrol Buka / Tutup & Kuota PPDB Online
                </h3>
                <span class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-bold <?= ($school_info['ppdb_status'] ?? 'buka') === 'buka' ? 'bg-emerald-500/20 text-emerald-400 border border-emerald-500/30' : 'bg-rose-500/20 text-rose-400 border border-rose-500/30' ?>">
                    ● Status: <?= ($school_info['ppdb_status'] ?? 'buka') === 'buka' ? 'Pendaftaran DIBUKA' : 'Pendaftaran DITUTUP' ?>
                </span>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                <div>
                    <label class="block text-xs font-semibold text-slate-300 mb-1.5">Status Pendaftaran PPDB *</label>
                    <select name="ppdb_status" required
                            class="w-full rounded-xl border border-white/10 bg-slate-950 px-4 py-2.5 text-sm text-white focus:border-emerald-500 focus:outline-none font-medium">
                        <option value="buka" <?= ($school_info['ppdb_status'] ?? 'buka') === 'buka' ? 'selected' : '' ?>>🟢 Buka Pendaftaran</option>
                        <option value="tutup" <?= ($school_info['ppdb_status'] ?? 'buka') === 'tutup' ? 'selected' : '' ?>>🔴 Tutup Pendaftaran</option>
                    </select>
                </div>

                <div>
                    <label class="block text-xs font-semibold text-slate-300 mb-1.5">Nama Gelombang Aktif *</label>
                    <input type="text" name="ppdb_wave_name" required value="<?= htmlspecialchars($school_info['ppdb_wave_name'] ?? 'Gelombang 1') ?>" placeholder="Contoh: Gelombang 1"
                           class="w-full rounded-xl border border-white/10 bg-slate-950 px-4 py-2.5 text-sm text-white focus:border-emerald-500 focus:outline-none">
                </div>

                <div>
                    <label class="block text-xs font-semibold text-slate-300 mb-1.5">Batas Kuota Pendaftar *</label>
                    <input type="number" min="1" max="10000" name="ppdb_quota" required value="<?= htmlspecialchars((string)($school_info['ppdb_quota'] ?? 150)) ?>" placeholder="Contoh: 150"
                           class="w-full rounded-xl border border-white/10 bg-slate-950 px-4 py-2.5 text-sm text-white focus:border-emerald-500 focus:outline-none font-mono">
                    <span class="text-[10px] text-slate-400 mt-1 block">Formulir otomatis terkunci saat kuota tercapai.</span>
                </div>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 mt-4">
                <div>
                    <label class="block text-xs font-semibold text-slate-300 mb-1.5">Tanggal Buka Pendaftaran (Opsional)</label>
                    <input type="date" name="ppdb_start_date" value="<?= htmlspecialchars($school_info['ppdb_start_date'] ?? '') ?>"
                           class="w-full rounded-xl border border-white/10 bg-slate-950 px-4 py-2 text-sm text-white focus:border-emerald-500 focus:outline-none">
                </div>

                <div>
                    <label class="block text-xs font-semibold text-slate-300 mb-1.5">Tanggal Berakhir Pendaftaran (Opsional)</label>
                    <input type="date" name="ppdb_end_date" value="<?= htmlspecialchars($school_info['ppdb_end_date'] ?? '') ?>"
                           class="w-full rounded-xl border border-white/10 bg-slate-950 px-4 py-2 text-sm text-white focus:border-emerald-500 focus:outline-none">
                </div>
            </div>

            <div class="mt-4">
                <label class="block text-xs font-semibold text-slate-300 mb-1.5">Pesan Pengumuman Saat Pendaftaran Ditutup</label>
                <textarea name="ppdb_closed_message" rows="2" 
                          placeholder="Pesan yang akan ditampilkan kepada calon pendaftar jika pendaftaran sedang ditutup atau kuota penuh..."
                          class="w-full rounded-xl border border-white/10 bg-slate-950 px-4 py-2 text-sm text-white focus:border-emerald-500 focus:outline-none"><?= htmlspecialchars($school_info['ppdb_closed_message'] ?? 'Pendaftaran Peserta Didik Baru (PPDB) saat ini sedang ditutup atau batas kuota telah terpenuhi.') ?></textarea>
            </div>

            <!-- Informasi Gelombang Berikutnya & Hotline Seleksi -->
            <div class="mt-4 p-4 rounded-2xl bg-white/[0.02] border border-white/5 space-y-4">
                <span class="text-xs font-bold text-amber-400 uppercase tracking-wider flex items-center gap-1.5">
                    <i class="fa-solid fa-calendar-plus"></i> Informasi Gelombang Berikutnya & Jadwal Seleksi
                </span>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-xs font-semibold text-slate-300 mb-1.5">Nama Gelombang Berikutnya</label>
                        <input type="text" name="ppdb_next_wave_name" value="<?= htmlspecialchars($school_info['ppdb_next_wave_name'] ?? 'Gelombang 2 (Jalur Reguler & Zonasi)') ?>" placeholder="Contoh: Gelombang 2 (Jalur Reguler & Zonasi)"
                               class="w-full rounded-xl border border-white/10 bg-slate-950 px-4 py-2 text-sm text-white focus:border-emerald-500 focus:outline-none">
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-slate-300 mb-1.5">Tanggal Buka Gelombang Berikutnya</label>
                        <input type="date" name="ppdb_next_open_date" value="<?= htmlspecialchars($school_info['ppdb_next_open_date'] ?? '2026-07-01') ?>"
                               class="w-full rounded-xl border border-white/10 bg-slate-950 px-4 py-2 text-sm text-white focus:border-emerald-500 focus:outline-none">
                    </div>
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                    <div>
                        <label class="block text-xs font-semibold text-slate-300 mb-1.5">Tanggal Pengumuman Kelulusan</label>
                        <input type="text" name="ppdb_announcement_date" value="<?= htmlspecialchars($school_info['ppdb_announcement_date'] ?? '18 Juli 2026') ?>" placeholder="Contoh: 18 Juli 2026"
                               class="w-full rounded-xl border border-white/10 bg-slate-950 px-4 py-2 text-sm text-white focus:border-emerald-500 focus:outline-none">
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-slate-300 mb-1.5">Jadwal Daftar Ulang</label>
                        <input type="text" name="ppdb_re_registration_date" value="<?= htmlspecialchars($school_info['ppdb_re_registration_date'] ?? '20 - 25 Juli 2026') ?>" placeholder="Contoh: 20 - 25 Juli 2026"
                               class="w-full rounded-xl border border-white/10 bg-slate-950 px-4 py-2 text-sm text-white focus:border-emerald-500 focus:outline-none">
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-slate-300 mb-1.5">No. WhatsApp Panitia PPDB (Hotline)</label>
                        <input type="text" name="ppdb_contact_phone" value="<?= htmlspecialchars($school_info['ppdb_contact_phone'] ?? '081234567890') ?>" placeholder="Contoh: 081234567890"
                               class="w-full rounded-xl border border-white/10 bg-slate-950 px-4 py-2 text-sm text-white focus:border-emerald-500 focus:outline-none font-mono">
                    </div>
                </div>
            </div>
        </div>

        <!-- 4. GEOFENCING GPS & RADIUS PRESENSI GURU -->
        <div class="pt-4">
            <div class="flex items-center justify-between border-b border-white/10 pb-2 mb-4">
                <h3 class="text-sm font-bold uppercase tracking-wider text-cyan-400 flex items-center gap-2">
                    <i class="fa-solid fa-location-dot"></i> 4. Titik Koordinat GPS & Radius Presensi (Geofencing)
                </h3>
                <button type="button" onclick="detectCurrentLocation()" 
                        class="inline-flex items-center gap-1.5 rounded-lg border border-cyan-500/30 bg-cyan-500/10 px-2.5 py-1 text-xs font-semibold text-cyan-300 hover:bg-cyan-500/20 transition cursor-pointer">
                    <i class="fa-solid fa-crosshairs"></i> Deteksi Lokasi Saya Saat Ini
                </button>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                <div>
                    <label class="block text-xs font-semibold text-slate-300 mb-1.5">Latitude Gerbang Sekolah *</label>
                    <input type="text" name="school_latitude" id="school_latitude" required 
                           value="<?= htmlspecialchars($school_info['school_latitude'] ?? '-6.208800') ?>" 
                           placeholder="-6.208800"
                           class="w-full rounded-xl border border-white/10 bg-slate-950 px-4 py-2.5 text-sm text-white focus:border-cyan-500 focus:outline-none font-mono">
                </div>

                <div>
                    <label class="block text-xs font-semibold text-slate-300 mb-1.5">Longitude Gerbang Sekolah *</label>
                    <input type="text" name="school_longitude" id="school_longitude" required 
                           value="<?= htmlspecialchars($school_info['school_longitude'] ?? '106.845600') ?>" 
                           placeholder="106.845600"
                           class="w-full rounded-xl border border-white/10 bg-slate-950 px-4 py-2.5 text-sm text-white focus:border-cyan-500 focus:outline-none font-mono">
                </div>

                <div>
                    <label class="block text-xs font-semibold text-slate-300 mb-1.5">Radius Maksimal (Meter) *</label>
                    <input type="number" min="10" max="5000" name="school_radius_meters" required 
                           value="<?= htmlspecialchars((string)($school_info['school_radius_meters'] ?? 150)) ?>" 
                           placeholder="150"
                           class="w-full rounded-xl border border-white/10 bg-slate-950 px-4 py-2.5 text-sm text-white focus:border-cyan-500 focus:outline-none font-mono">
                    <span class="text-[10px] text-slate-400 mt-1 block">Guru hanya dapat presensi jika berada dalam batas radius ini.</span>
                </div>
            </div>

            <div id="gps-status-info" class="hidden mt-3 p-3 rounded-xl bg-cyan-950/40 border border-cyan-500/30 text-xs text-cyan-300 flex items-center gap-2">
                <i class="fa-solid fa-satellite-dish text-cyan-400"></i>
                <span id="gps-status-text">Mendeteksi koordinat...</span>
            </div>
        </div>

        <!-- 5. MANAJEMEN TANDA TANGAN DIGITAL (TTE), STEMPEL & QR CODE -->
        <div class="pt-6 border-t border-white/10">
            <div class="flex flex-col sm:flex-row sm:items-center justify-between border-b border-white/10 pb-3 mb-6 gap-2">
                <div>
                    <h3 class="text-sm font-bold uppercase tracking-wider text-amber-400 flex items-center gap-2">
                        <i class="fa-solid fa-signature"></i> 5. Manajemen Pengesahan Dokumen, Stempel & Tanda Tangan Digital (TTE)
                    </h3>
                    <p class="text-xs text-slate-400 mt-0.5">
                        Konfigurasi mode tanda tangan (manual, gambar scan, QR TTE, atau hybrid), stempel sekolah, serta pejabat berwenang.
                    </p>
                </div>
                <a href="../../verify_document.php?doc_type=SKBB&doc_num=421.3/DEMO-VERIFIKASI/2026&student_name=Budi+Santoso" target="_blank" 
                   class="inline-flex items-center gap-1.5 rounded-xl border border-amber-500/30 bg-amber-500/10 px-3 py-1.5 text-xs font-semibold text-amber-300 hover:bg-amber-500/20 transition">
                    <i class="fa-solid fa-qrcode text-xs"></i> Uji Halaman Verifikasi QR
                </a>
            </div>

            <!-- Pengaturan Global Mode Pengesahan & Stempel -->
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 mb-6">
                <div>
                    <label class="block text-xs font-semibold text-slate-300 mb-1.5">Mode Pengesahan Dokumen *</label>
                    <select name="signature_mode" required class="w-full rounded-xl border border-white/10 bg-slate-950 px-3 py-2.5 text-xs sm:text-sm text-white focus:border-amber-500 focus:outline-none">
                        <option value="hybrid" <?= ($school_info['signature_mode'] ?? 'hybrid') === 'hybrid' ? 'selected' : '' ?>>
                            ⭐ Hybrid (TTD Scan + Stempel + Mini QR TTE)
                        </option>
                        <option value="digital_qr" <?= ($school_info['signature_mode'] ?? '') === 'digital_qr' ? 'selected' : '' ?>>
                            📱 Digital QR (Hanya QR TTE Resmi + Hash)
                        </option>
                        <option value="digital_image" <?= ($school_info['signature_mode'] ?? '') === 'digital_image' ? 'selected' : '' ?>>
                            🖋️ Digital Image (Hanya Gambar TTD & Stempel)
                        </option>
                        <option value="manual" <?= ($school_info['signature_mode'] ?? '') === 'manual' ? 'selected' : '' ?>>
                            📄 Manual (Kolom Kosong untuk Paraf & Cap Fisik)
                        </option>
                    </select>
                </div>

                <div>
                    <label class="block text-xs font-semibold text-slate-300 mb-1.5">Kota Penerbitan Surat *</label>
                    <input type="text" name="doc_city" required value="<?= htmlspecialchars($school_info['doc_city'] ?? 'Jakarta') ?>" placeholder="Contoh: Jakarta"
                           class="w-full rounded-xl border border-white/10 bg-slate-950 px-4 py-2.5 text-xs sm:text-sm text-white focus:border-amber-500 focus:outline-none">
                </div>

                <div>
                    <label class="block text-xs font-semibold text-slate-300 mb-1.5">Tampilkan Stempel Resmi *</label>
                    <select name="show_school_stamp" required class="w-full rounded-xl border border-white/10 bg-slate-950 px-3 py-2.5 text-xs sm:text-sm text-white focus:border-amber-500 focus:outline-none">
                        <option value="1" <?= ($school_info['show_school_stamp'] ?? '1') === '1' ? 'selected' : '' ?>>🔵 Tampilkan Stempel Digital</option>
                        <option value="0" <?= ($school_info['show_school_stamp'] ?? '1') === '0' ? 'selected' : '' ?>>⚪ Tanpa Stempel Digital</option>
                    </select>
                </div>
            </div>

            <!-- Upload Berkas Stempel Resmi Sekolah -->
            <div class="p-4 rounded-2xl bg-slate-950/70 border border-white/10 mb-6 flex flex-col sm:flex-row items-center justify-between gap-4">
                <div class="flex items-center gap-3.5">
                    <div class="h-16 w-16 shrink-0 rounded-2xl border border-blue-500/30 bg-white/5 flex items-center justify-center p-1.5 overflow-hidden">
                        <?php 
                        $stamp_prev = $school_info['school_stamp_image'] ?? 'uploads/signatures/default_stamp.svg';
                        ?>
                        <img src="../../<?= ltrim($stamp_prev, '/') ?>" alt="Stempel Sekolah" class="max-h-full max-w-full object-contain">
                    </div>
                    <div>
                        <h4 class="text-xs sm:text-sm font-bold text-white flex items-center gap-1.5">
                            <i class="fa-solid fa-stamp text-blue-400"></i> Stempel Resmi Lembaga (Cap Sekolah)
                        </h4>
                        <p class="text-[11px] text-slate-400 mt-0.5">
                            Format berkas: PNG transparan, SVG, atau WEBP. Ukuran rekomendasi: 200x200 px.
                        </p>
                        <button type="submit" formnovalidate onclick="document.getElementById('formActionField').value='reset_signature'; document.getElementById('resetTargetKey').value='school_stamp_image'; return confirm('Kembalikan stempel ke grafis stempel resmi bawaan sistem?');" 
                                class="mt-1 text-[10px] text-amber-400 hover:text-amber-300 inline-flex items-center gap-1 cursor-pointer">
                            <i class="fa-solid fa-rotate-left"></i> Reset ke Grafis Bawaan
                        </button>
                    </div>
                </div>
                <div class="w-full sm:w-auto">
                    <input type="file" name="school_stamp_file" accept=".png,.svg,.jpg,.jpeg,.webp" 
                           class="w-full text-xs text-slate-300 file:mr-2 file:py-1.5 file:px-3 file:rounded-xl file:border-0 file:text-xs file:font-semibold file:bg-blue-600 file:text-white hover:file:bg-blue-500 cursor-pointer">
                </div>
            </div>

            <!-- Daftar Pejabat Berwenang Menandatangani -->
            <h4 class="text-xs font-bold uppercase tracking-wider text-slate-300 mb-4 flex items-center gap-1.5">
                <i class="fa-solid fa-users-gear text-amber-400"></i> Data Pejabat Penandatangan & Berkas TTD
            </h4>

            <div class="space-y-4">

                <!-- 1. Kepala Sekolah -->
                <div class="p-4 rounded-2xl bg-slate-950/50 border border-white/10">
                    <div class="flex items-center justify-between mb-3 border-b border-white/5 pb-2">
                        <span class="text-xs font-bold text-amber-300 flex items-center gap-1.5">
                            <i class="fa-solid fa-award"></i> 1. Kepala Sekolah (Pimpinan Lembaga)
                        </span>
                        <span class="text-[10px] text-slate-500 font-mono">Digunakan pada: Rapor, SKL, SKBB, Kartu Ujian, dll</span>
                    </div>
                    <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
                        <div>
                            <label class="block text-[11px] font-semibold text-slate-400 mb-1">Nama Lengkap & Gelar *</label>
                            <input type="text" name="headmaster_name" required value="<?= htmlspecialchars($school_info['headmaster_name']) ?>" 
                                    class="w-full rounded-xl border border-white/10 bg-slate-900 px-3 py-2 text-xs text-white focus:border-amber-500 focus:outline-none">
                        </div>
                        <div>
                            <label class="block text-[11px] font-semibold text-slate-400 mb-1">NIP Kepala Sekolah</label>
                            <input type="text" name="headmaster_nip" value="<?= htmlspecialchars($school_info['headmaster_nip']) ?>" 
                                    class="w-full rounded-xl border border-white/10 bg-slate-900 px-3 py-2 text-xs text-white focus:border-amber-500 focus:outline-none font-mono">
                        </div>
                        <div>
                            <label class="block text-[11px] font-semibold text-slate-400 mb-1">Sebutan Jabatan</label>
                            <input type="text" name="headmaster_title" value="<?= htmlspecialchars($school_info['headmaster_title'] ?? 'Kepala Sekolah') ?>" 
                                    class="w-full rounded-xl border border-white/10 bg-slate-900 px-3 py-2 text-xs text-white focus:border-amber-500 focus:outline-none">
                        </div>
                    </div>
                    <div class="mt-3 pt-3 border-t border-white/5 flex flex-col sm:flex-row items-center justify-between gap-3">
                        <div class="flex items-center gap-3">
                            <span class="text-[11px] text-slate-400">TTD Saat Ini:</span>
                            <div class="h-10 w-28 bg-white/5 border border-white/10 rounded-lg p-1 flex items-center justify-center">
                                <img src="../../<?= ltrim($school_info['headmaster_signature_image'] ?? 'uploads/signatures/default_headmaster_ttd.svg', '/') ?>" alt="TTD KS" class="max-h-full max-w-full object-contain">
                            </div>
                            <button type="submit" formnovalidate onclick="document.getElementById('formActionField').value='reset_signature'; document.getElementById('resetTargetKey').value='headmaster_signature_image'; return confirm('Kembalikan tanda tangan Kepala Sekolah ke grafis SVG bawaan?');" 
                                    class="text-[11px] text-amber-400 hover:text-amber-300 inline-flex items-center gap-1 cursor-pointer">
                                <i class="fa-solid fa-rotate-left"></i> Reset
                            </button>
                        </div>
                        <div>
                            <label class="text-[11px] text-slate-400 block mb-1">Ganti Berkas TTD (Scan PNG/SVG Transparan):</label>
                            <input type="file" name="headmaster_signature_file" accept=".png,.svg,.jpg,.jpeg,.webp" 
                                    class="text-xs text-slate-300 file:mr-2 file:py-1 file:px-2.5 file:rounded-lg file:border-0 file:text-[11px] file:font-semibold file:bg-amber-600 file:text-white hover:file:bg-amber-500 cursor-pointer">
                        </div>
                    </div>
                </div>

                <!-- 2. Kepala Bagian Tata Usaha -->
                <div class="p-4 rounded-2xl bg-slate-950/50 border border-white/10">
                    <div class="flex items-center justify-between mb-3 border-b border-white/5 pb-2">
                        <span class="text-xs font-bold text-blue-300 flex items-center gap-1.5">
                            <i class="fa-solid fa-building-user"></i> 2. Kepala Bagian Tata Usaha (TU)
                        </span>
                        <span class="text-[10px] text-slate-500 font-mono">Digunakan pada: Surat Pengantar, Arsip TU, Biodata Siswa</span>
                    </div>
                    <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
                        <div>
                            <label class="block text-[11px] font-semibold text-slate-400 mb-1">Nama Lengkap & Gelar *</label>
                            <input type="text" name="tu_head_name" required value="<?= htmlspecialchars($school_info['tu_head_name'] ?? 'Hj. Siti Rahmawati, S.Sos') ?>" 
                                    class="w-full rounded-xl border border-white/10 bg-slate-900 px-3 py-2 text-xs text-white focus:border-blue-500 focus:outline-none">
                        </div>
                        <div>
                            <label class="block text-[11px] font-semibold text-slate-400 mb-1">NIP Kepala TU</label>
                            <input type="text" name="tu_head_nip" value="<?= htmlspecialchars($school_info['tu_head_nip'] ?? '19800415 200501 2 007') ?>" 
                                    class="w-full rounded-xl border border-white/10 bg-slate-900 px-3 py-2 text-xs text-white focus:border-blue-500 focus:outline-none font-mono">
                        </div>
                        <div>
                            <label class="block text-[11px] font-semibold text-slate-400 mb-1">Sebutan Jabatan</label>
                            <input type="text" name="tu_head_title" value="<?= htmlspecialchars($school_info['tu_head_title'] ?? 'Kepala Bagian Tata Usaha') ?>" 
                                    class="w-full rounded-xl border border-white/10 bg-slate-900 px-3 py-2 text-xs text-white focus:border-blue-500 focus:outline-none">
                        </div>
                    </div>
                    <div class="mt-3 pt-3 border-t border-white/5 flex flex-col sm:flex-row items-center justify-between gap-3">
                        <div class="flex items-center gap-3">
                            <span class="text-[11px] text-slate-400">TTD Saat Ini:</span>
                            <div class="h-10 w-28 bg-white/5 border border-white/10 rounded-lg p-1 flex items-center justify-center">
                                <img src="../../<?= ltrim($school_info['tu_head_signature_image'] ?? 'uploads/signatures/default_tu_ttd.svg', '/') ?>" alt="TTD TU" class="max-h-full max-w-full object-contain">
                            </div>
                            <button type="submit" formnovalidate onclick="document.getElementById('formActionField').value='reset_signature'; document.getElementById('resetTargetKey').value='tu_head_signature_image'; return confirm('Kembalikan tanda tangan Kepala TU ke grafis SVG bawaan?');" 
                                    class="text-[11px] text-blue-400 hover:text-blue-300 inline-flex items-center gap-1 cursor-pointer">
                                <i class="fa-solid fa-rotate-left"></i> Reset
                            </button>
                        </div>
                        <div>
                            <label class="text-[11px] text-slate-400 block mb-1">Ganti Berkas TTD (Scan PNG/SVG Transparan):</label>
                            <input type="file" name="tu_head_signature_file" accept=".png,.svg,.jpg,.jpeg,.webp" 
                                    class="text-xs text-slate-300 file:mr-2 file:py-1 file:px-2.5 file:rounded-lg file:border-0 file:text-[11px] file:font-semibold file:bg-blue-600 file:text-white hover:file:bg-blue-500 cursor-pointer">
                        </div>
                    </div>
                </div>

                <!-- 3. Koordinator Bimbingan Konseling (BK) -->
                <div class="p-4 rounded-2xl bg-slate-950/50 border border-white/10">
                    <div class="flex items-center justify-between mb-3 border-b border-white/5 pb-2">
                        <span class="text-xs font-bold text-rose-300 flex items-center gap-1.5">
                            <i class="fa-solid fa-scale-balanced"></i> 3. Koordinator Bimbingan Konseling (BK)
                        </span>
                        <span class="text-[10px] text-slate-500 font-mono">Digunakan pada: Surat Panggilan Orang Tua, SP 1/2/3, SK DO</span>
                    </div>
                    <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
                        <div>
                            <label class="block text-[11px] font-semibold text-slate-400 mb-1">Nama Lengkap & Gelar *</label>
                            <input type="text" name="bk_coordinator_name" required value="<?= htmlspecialchars($school_info['bk_coordinator_name'] ?? 'Ahmad Rizky, S.Psi., M.Pd') ?>" 
                                    class="w-full rounded-xl border border-white/10 bg-slate-900 px-3 py-2 text-xs text-white focus:border-rose-500 focus:outline-none">
                        </div>
                        <div>
                            <label class="block text-[11px] font-semibold text-slate-400 mb-1">NIP Koordinator BK</label>
                            <input type="text" name="bk_coordinator_nip" value="<?= htmlspecialchars($school_info['bk_coordinator_nip'] ?? '19850612 201001 1 018') ?>" 
                                    class="w-full rounded-xl border border-white/10 bg-slate-900 px-3 py-2 text-xs text-white focus:border-rose-500 focus:outline-none font-mono">
                        </div>
                        <div>
                            <label class="block text-[11px] font-semibold text-slate-400 mb-1">Sebutan Jabatan</label>
                            <input type="text" name="bk_coordinator_title" value="<?= htmlspecialchars($school_info['bk_coordinator_title'] ?? 'Koordinator Bimbingan Konseling') ?>" 
                                    class="w-full rounded-xl border border-white/10 bg-slate-900 px-3 py-2 text-xs text-white focus:border-rose-500 focus:outline-none">
                        </div>
                    </div>
                    <div class="mt-3 pt-3 border-t border-white/5 flex flex-col sm:flex-row items-center justify-between gap-3">
                        <div class="flex items-center gap-3">
                            <span class="text-[11px] text-slate-400">TTD Saat Ini:</span>
                            <div class="h-10 w-28 bg-white/5 border border-white/10 rounded-lg p-1 flex items-center justify-center">
                                <img src="../../<?= ltrim($school_info['bk_coordinator_signature_image'] ?? 'uploads/signatures/default_bk_ttd.svg', '/') ?>" alt="TTD BK" class="max-h-full max-w-full object-contain">
                            </div>
                            <button type="submit" formnovalidate onclick="document.getElementById('formActionField').value='reset_signature'; document.getElementById('resetTargetKey').value='bk_coordinator_signature_image'; return confirm('Kembalikan tanda tangan BK ke grafis SVG bawaan?');" 
                                    class="text-[11px] text-rose-400 hover:text-rose-300 inline-flex items-center gap-1 cursor-pointer">
                                <i class="fa-solid fa-rotate-left"></i> Reset
                            </button>
                        </div>
                        <div>
                            <label class="text-[11px] text-slate-400 block mb-1">Ganti Berkas TTD (Scan PNG/SVG Transparan):</label>
                            <input type="file" name="bk_coordinator_signature_file" accept=".png,.svg,.jpg,.jpeg,.webp" 
                                    class="text-xs text-slate-300 file:mr-2 file:py-1 file:px-2.5 file:rounded-lg file:border-0 file:text-[11px] file:font-semibold file:bg-rose-600 file:text-white hover:file:bg-rose-500 cursor-pointer">
                        </div>
                    </div>
                </div>

                <!-- 4. Bendahara Sekolah & Keuangan -->
                <div class="p-4 rounded-2xl bg-slate-950/50 border border-white/10">
                    <div class="flex items-center justify-between mb-3 border-b border-white/5 pb-2">
                        <span class="text-xs font-bold text-emerald-300 flex items-center gap-1.5">
                            <i class="fa-solid fa-wallet"></i> 4. Bendahara Sekolah / Keuangan
                        </span>
                        <span class="text-[10px] text-slate-500 font-mono">Digunakan pada: Slip Gaji Guru, Kuitansi SPP, Laporan BKU</span>
                    </div>
                    <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
                        <div>
                            <label class="block text-[11px] font-semibold text-slate-400 mb-1">Nama Bendahara *</label>
                            <input type="text" name="treasurer_name" required value="<?= htmlspecialchars($school_info['treasurer_name'] ?? 'Dewi Lestari, S.E') ?>" 
                                    class="w-full rounded-xl border border-white/10 bg-slate-900 px-3 py-2 text-xs text-white focus:border-emerald-500 focus:outline-none">
                        </div>
                        <div>
                            <label class="block text-[11px] font-semibold text-slate-400 mb-1">NIP Bendahara</label>
                            <input type="text" name="treasurer_nip" value="<?= htmlspecialchars($school_info['treasurer_nip'] ?? '19880920 201201 2 015') ?>" 
                                    class="w-full rounded-xl border border-white/10 bg-slate-900 px-3 py-2 text-xs text-white focus:border-emerald-500 focus:outline-none font-mono">
                        </div>
                        <div>
                            <label class="block text-[11px] font-semibold text-slate-400 mb-1">Sebutan Jabatan</label>
                            <input type="text" name="treasurer_title" value="<?= htmlspecialchars($school_info['treasurer_title'] ?? 'Bendahara Sekolah') ?>" 
                                    class="w-full rounded-xl border border-white/10 bg-slate-900 px-3 py-2 text-xs text-white focus:border-emerald-500 focus:outline-none">
                        </div>
                    </div>
                    <div class="mt-3 pt-3 border-t border-white/5 flex flex-col sm:flex-row items-center justify-between gap-3">
                        <div class="flex items-center gap-3">
                            <span class="text-[11px] text-slate-400">TTD Saat Ini:</span>
                            <div class="h-10 w-28 bg-white/5 border border-white/10 rounded-lg p-1 flex items-center justify-center">
                                <img src="../../<?= ltrim($school_info['treasurer_signature_image'] ?? 'uploads/signatures/default_tu_ttd.svg', '/') ?>" alt="TTD Bendahara" class="max-h-full max-w-full object-contain">
                            </div>
                            <button type="submit" formnovalidate onclick="document.getElementById('formActionField').value='reset_signature'; document.getElementById('resetTargetKey').value='treasurer_signature_image'; return confirm('Kembalikan tanda tangan Bendahara ke grafis SVG bawaan?');" 
                                    class="text-[11px] text-emerald-400 hover:text-emerald-300 inline-flex items-center gap-1 cursor-pointer">
                                <i class="fa-solid fa-rotate-left"></i> Reset
                            </button>
                        </div>
                        <div>
                            <label class="text-[11px] text-slate-400 block mb-1">Ganti Berkas TTD (Scan PNG/SVG Transparan):</label>
                            <input type="file" name="treasurer_signature_file" accept=".png,.svg,.jpg,.jpeg,.webp" 
                                    class="text-xs text-slate-300 file:mr-2 file:py-1 file:px-2.5 file:rounded-lg file:border-0 file:text-[11px] file:font-semibold file:bg-emerald-600 file:text-white hover:file:bg-emerald-500 cursor-pointer">
                        </div>
                    </div>
                </div>

                <!-- 5. Waka Bidang Kurikulum -->
                <div class="p-4 rounded-2xl bg-slate-950/50 border border-white/10">
                    <div class="flex items-center justify-between mb-3 border-b border-white/5 pb-2">
                        <span class="text-xs font-bold text-purple-300 flex items-center gap-1.5">
                            <i class="fa-solid fa-book-open-reader"></i> 5. Waka Bidang Kurikulum
                        </span>
                        <span class="text-[10px] text-slate-500 font-mono">Digunakan pada: Jadwal Pelajaran, Dokumen Ujian, Leger Nilai</span>
                    </div>
                    <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
                        <div>
                            <label class="block text-[11px] font-semibold text-slate-400 mb-1">Nama Waka Kurikulum *</label>
                            <input type="text" name="curriculum_head_name" required value="<?= htmlspecialchars($school_info['curriculum_head_name'] ?? 'Muhammad Fauzi, M.Pd') ?>" 
                                    class="w-full rounded-xl border border-white/10 bg-slate-900 px-3 py-2 text-xs text-white focus:border-purple-500 focus:outline-none">
                        </div>
                        <div>
                            <label class="block text-[11px] font-semibold text-slate-400 mb-1">NIP Waka Kurikulum</label>
                            <input type="text" name="curriculum_head_nip" value="<?= htmlspecialchars($school_info['curriculum_head_nip'] ?? '19820311 200801 1 012') ?>" 
                                    class="w-full rounded-xl border border-white/10 bg-slate-900 px-3 py-2 text-xs text-white focus:border-purple-500 focus:outline-none font-mono">
                        </div>
                        <div>
                            <label class="block text-[11px] font-semibold text-slate-400 mb-1">Sebutan Jabatan</label>
                            <input type="text" name="curriculum_head_title" value="<?= htmlspecialchars($school_info['curriculum_head_title'] ?? 'Waka Bidang Kurikulum') ?>" 
                                    class="w-full rounded-xl border border-white/10 bg-slate-900 px-3 py-2 text-xs text-white focus:border-purple-500 focus:outline-none">
                        </div>
                    </div>
                    <div class="mt-3 pt-3 border-t border-white/5 flex flex-col sm:flex-row items-center justify-between gap-3">
                        <div class="flex items-center gap-3">
                            <span class="text-[11px] text-slate-400">TTD Saat Ini:</span>
                            <div class="h-10 w-28 bg-white/5 border border-white/10 rounded-lg p-1 flex items-center justify-center">
                                <img src="../../<?= ltrim($school_info['curriculum_head_signature_image'] ?? 'uploads/signatures/default_headmaster_ttd.svg', '/') ?>" alt="TTD Kurikulum" class="max-h-full max-w-full object-contain">
                            </div>
                            <button type="submit" formnovalidate onclick="document.getElementById('formActionField').value='reset_signature'; document.getElementById('resetTargetKey').value='curriculum_head_signature_image'; return confirm('Kembalikan tanda tangan Waka Kurikulum ke grafis SVG bawaan?');" 
                                    class="text-[11px] text-purple-400 hover:text-purple-300 inline-flex items-center gap-1 cursor-pointer">
                                <i class="fa-solid fa-rotate-left"></i> Reset
                            </button>
                        </div>
                        <div>
                            <label class="text-[11px] text-slate-400 block mb-1">Ganti Berkas TTD (Scan PNG/SVG Transparan):</label>
                            <input type="file" name="curriculum_head_signature_file" accept=".png,.svg,.jpg,.jpeg,.webp" 
                                    class="text-xs text-slate-300 file:mr-2 file:py-1 file:px-2.5 file:rounded-lg file:border-0 file:text-[11px] file:font-semibold file:bg-purple-600 file:text-white hover:file:bg-purple-500 cursor-pointer">
                        </div>
                    </div>
                </div>

                <!-- 6. Kepala Perpustakaan Sekolah -->
                <div class="p-4 rounded-2xl bg-slate-950/50 border border-white/10">
                    <div class="flex items-center justify-between mb-3 border-b border-white/5 pb-2">
                        <span class="text-xs font-bold text-teal-300 flex items-center gap-1.5">
                            <i class="fa-solid fa-book-bookmark"></i> 6. Kepala Perpustakaan Sekolah
                        </span>
                        <span class="text-[10px] text-slate-500 font-mono">Digunakan pada: Surat Bebas Perpustakaan, Kartu Anggota</span>
                    </div>
                    <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
                        <div>
                            <label class="block text-[11px] font-semibold text-slate-400 mb-1">Nama Kepala Perpustakaan *</label>
                            <input type="text" name="library_head_name" required value="<?= htmlspecialchars($school_info['library_head_name'] ?? 'Dra. Hj. Nurul Hidayati, M.Pd') ?>" 
                                    class="w-full rounded-xl border border-white/10 bg-slate-900 px-3 py-2 text-xs text-white focus:border-teal-500 focus:outline-none">
                        </div>
                        <div>
                            <label class="block text-[11px] font-semibold text-slate-400 mb-1">NIP Kepala Perpustakaan</label>
                            <input type="text" name="library_head_nip" value="<?= htmlspecialchars($school_info['library_head_nip'] ?? '19780512 200501 2 006') ?>" 
                                    class="w-full rounded-xl border border-white/10 bg-slate-900 px-3 py-2 text-xs text-white focus:border-teal-500 focus:outline-none font-mono">
                        </div>
                        <div>
                            <label class="block text-[11px] font-semibold text-slate-400 mb-1">Sebutan Jabatan</label>
                            <input type="text" name="library_head_title" value="<?= htmlspecialchars($school_info['library_head_title'] ?? 'Kepala Perpustakaan Sekolah') ?>" 
                                    class="w-full rounded-xl border border-white/10 bg-slate-900 px-3 py-2 text-xs text-white focus:border-teal-500 focus:outline-none">
                        </div>
                    </div>
                    <div class="mt-3 pt-3 border-t border-white/5 flex flex-col sm:flex-row items-center justify-between gap-3">
                        <div class="flex items-center gap-3">
                            <span class="text-[11px] text-slate-400">TTD Saat Ini:</span>
                            <div class="h-10 w-28 bg-white/5 border border-white/10 rounded-lg p-1 flex items-center justify-center">
                                <img src="../../<?= ltrim($school_info['library_head_signature_image'] ?? 'uploads/signatures/default_library_ttd.svg', '/') ?>" alt="TTD Perpustakaan" class="max-h-full max-w-full object-contain">
                            </div>
                            <button type="submit" formnovalidate onclick="document.getElementById('formActionField').value='reset_signature'; document.getElementById('resetTargetKey').value='library_head_signature_image'; return confirm('Kembalikan tanda tangan Kepala Perpustakaan ke grafis SVG bawaan?');" 
                                    class="text-[11px] text-teal-400 hover:text-teal-300 inline-flex items-center gap-1 cursor-pointer">
                                <i class="fa-solid fa-rotate-left"></i> Reset
                            </button>
                        </div>
                        <div>
                            <label class="text-[11px] text-slate-400 block mb-1">Ganti Berkas TTD (Scan PNG/SVG Transparan):</label>
                            <input type="file" name="library_head_signature_file" accept=".png,.svg,.jpg,.jpeg,.webp" 
                                    class="text-xs text-slate-300 file:mr-2 file:py-1 file:px-2.5 file:rounded-lg file:border-0 file:text-[11px] file:font-semibold file:bg-teal-600 file:text-white hover:file:bg-teal-500 cursor-pointer">
                        </div>
                    </div>
                </div>

            </div>

            <!-- Preview Langsung Hasil TTD Dokumen -->
            <div class="mt-6 p-4 rounded-2xl bg-white text-slate-900 border border-white/20 shadow-lg">
                <span class="text-[11px] font-bold uppercase tracking-wider text-slate-500 block mb-2">
                    <i class="fa-solid fa-eye text-blue-600 mr-1"></i> Simulasi Tampilan Tanda Tangan pada Lembar Cetak Dokumen:
                </span>
                <div class="border-t border-dashed border-slate-300 pt-3 flex justify-end">
                    <?= renderOfficialSignatureHtml($pdo, 'headmaster', ['doc_type' => 'SURAT_KETERANGAN', 'doc_num' => '421.3 / SAMPLE / ' . date('Y')]) ?>
                </div>
            </div>

        </div>

        <div class="pt-6 border-t border-white/10 flex justify-end">
            <button type="submit" class="rounded-xl bg-gradient-to-r from-blue-600 to-indigo-600 px-6 py-2.5 text-sm font-bold text-white shadow-lg shadow-blue-500/25 hover:from-blue-500 hover:to-indigo-500 transition cursor-pointer flex items-center gap-2">
                <i class="fa-solid fa-floppy-disk"></i> Simpan Seluruh Pengaturan
            </button>
        </div>
    </form>
</div>

<!-- Card Promosi Integrasi API Luar -->
<div class="max-w-3xl mt-6 rounded-3xl border border-cyan-500/20 bg-gradient-to-r from-slate-900/90 via-slate-900/60 to-cyan-950/30 p-6 shadow-xl backdrop-blur">
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
        <div class="flex items-start gap-3.5">
            <div class="flex h-11 w-11 shrink-0 items-center justify-center rounded-2xl bg-cyan-500/10 text-cyan-400 border border-cyan-500/20 text-xl shadow-inner">
                <i class="fa-solid fa-cloud-arrow-up"></i>
            </div>
            <div>
                <h3 class="text-sm font-bold text-white tracking-tight flex items-center gap-2">
                    Pusat Pengaturan Integrasi API Luar
                    <span class="rounded-md bg-emerald-500/10 px-2 py-0.5 text-[10px] font-semibold text-emerald-400 border border-emerald-500/20">Auto-Save DB</span>
                </h3>
                <p class="text-xs text-slate-300 mt-1">
                    Kelola endpoint eksternal, kunci API (WhatsApp, Midtrans, Google Gemini AI, Brevo, Open-Meteo), serta pengujian koneksi live langsung ke server.
                </p>
            </div>
        </div>
        <a href="external_apis.php" class="shrink-0 rounded-xl bg-cyan-600 hover:bg-cyan-500 text-slate-950 font-bold px-4 py-2.5 text-xs transition flex items-center gap-2 shadow-lg shadow-cyan-600/20">
            <i class="fa-solid fa-sliders"></i> Buka Pengaturan API
        </a>
    </div>
</div>

<script>
function detectCurrentLocation() {
    const info = document.getElementById('gps-status-info');
    const text = document.getElementById('gps-status-text');
    info.classList.remove('hidden');
    text.innerText = 'Mengakses satelit GPS browser... Mohon izinkan akses lokasi.';

    if (!navigator.geolocation) {
        text.innerText = 'Perangkat / browser Anda tidak mendukung Geolocation API.';
        return;
    }

    navigator.geolocation.getCurrentPosition(
        (pos) => {
            const lat = pos.coords.latitude.toFixed(6);
            const lng = pos.coords.longitude.toFixed(6);
            document.getElementById('school_latitude').value = lat;
            document.getElementById('school_longitude').value = lng;
            text.innerHTML = `Berhasil mendeteksi lokasi saat ini: <strong>${lat}, ${lng}</strong> (Akurasi: ±${Math.round(pos.coords.accuracy)}m)`;
        },
        (err) => {
            text.innerText = 'Gagal mengambil koordinat: ' + err.message + '. Pastikan GPS dan izin lokasi aktif.';
        },
        { enableHighAccuracy: true, timeout: 10000, maximumAge: 0 }
    );
}
</script>

<?php require_once __DIR__ . "/../includes/footer.php"; ?>
