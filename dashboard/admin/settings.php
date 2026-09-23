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
            'school_website', 'headmaster_name', 'headmaster_nip', 'academic_year', 'school_logo'
        ];

        foreach ($fields as $f) {
            if (isset($_POST[$f])) {
                updateSchoolSetting($pdo, $f, trim($_POST[$f]));
            }
        }

        logActivity($pdo, 'UPDATE_SETTINGS', 'Memperbarui profil lembaga dan tahun ajaran aktif');
        $message = "Pengaturan identitas sekolah dan semester aktif berhasil disimpan!";
        $message_type = "success";
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
                <h1 class="text-2xl font-bold text-white tracking-tight">Pengaturan Profil Lembaga & Semester</h1>
                <p class="text-sm text-slate-400">Identitas sekolah ini otomatis digunakan pada kop surat resmi, kartu ujian, dan rapor</p>
            </div>
        </div>
    </div>

    <a href="audit_logs.php" class="rounded-xl border border-white/10 bg-white/5 hover:bg-white/10 px-4 py-2 text-xs font-semibold text-slate-300 transition flex items-center gap-2">
        <i class="fa-solid fa-shield-halved text-blue-400"></i> Lihat Audit Log
    </a>
</div>

<?php if ($message): ?>
    <div class="mb-6 rounded-2xl border p-4 text-sm flex items-center justify-between <?= $message_type === 'success' ? 'border-emerald-500/30 bg-emerald-500/10 text-emerald-300' : 'border-rose-500/30 bg-rose-500/10 text-rose-300' ?>">
        <span><?= htmlspecialchars($message) ?></span>
        <button onclick="this.parentElement.remove()" class="text-xs font-semibold opacity-70 hover:opacity-100 cursor-pointer"><i class="fa-solid fa-xmark"></i></button>
    </div>
<?php endif; ?>

<div class="max-w-3xl rounded-3xl border border-white/10 bg-slate-900/60 p-6 sm:p-8 shadow-xl backdrop-blur">
    <form method="POST" class="space-y-6">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="save_settings">

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

            <div class="mt-4">
                <label class="block text-xs font-semibold text-slate-300 mb-1.5">Tahun Ajaran & Semester Aktif *</label>
                <input type="text" name="academic_year" required value="<?= htmlspecialchars($school_info['academic_year']) ?>" placeholder="Contoh: 2026/2027 Ganjil" 
                       class="w-full rounded-xl border border-white/10 bg-slate-950 px-4 py-2.5 text-sm text-white focus:border-blue-500 focus:outline-none">
            </div>
        </div>

        <div class="pt-6 border-t border-white/10 flex justify-end">
            <button type="submit" class="rounded-xl bg-gradient-to-r from-blue-600 to-indigo-600 px-6 py-2.5 text-sm font-bold text-white shadow-lg shadow-blue-500/25 hover:from-blue-500 hover:to-indigo-500 transition cursor-pointer flex items-center gap-2">
                <i class="fa-solid fa-floppy-disk"></i> Simpan Seluruh Pengaturan
            </button>
        </div>
    </form>
</div>

<?php require_once __DIR__ . "/../includes/footer.php"; ?>
