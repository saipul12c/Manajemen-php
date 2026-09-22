<?php
session_start();
require_once __DIR__ . "/../config/database.php";

requireLogin();

$user_id = (int) $_SESSION['user_id'];
$user_role = $_SESSION['user_role'] ?? 'siswa';
$is_staff = in_array($user_role, ['staf', 'administrator'], true);

$message = "";
$message_type = "";

// Buat direktori upload lampiran surat jika belum ada
$upload_letter_dir = __DIR__ . "/../uploads/letters";
if (!is_dir($upload_letter_dir)) {
    @mkdir($upload_letter_dir, 0777, true);
}

// -------------------------------------------------------------
// 1. SISWA / ORANG TUA: AJUKAN PERMOHONAN SURAT BARU
// -------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'submit_request') {
    $request_type = trim($_POST['request_type'] ?? '');
    $notes = trim($_POST['notes'] ?? '');
    $target_date = !empty($_POST['target_date']) ? $_POST['target_date'] : date('Y-m-d');
    $attachment_url = null;

    // Handle Upload Berkas / Surat Dokter
    if (isset($_FILES['attachment_file']) && $_FILES['attachment_file']['error'] === UPLOAD_ERR_OK) {
        $file_tmp  = $_FILES['attachment_file']['tmp_name'];
        $file_name = $_FILES['attachment_file']['name'];
        $file_ext  = strtolower(pathinfo($file_name, PATHINFO_EXTENSION));
        $allowed   = ['pdf', 'jpg', 'jpeg', 'png'];

        if (in_array($file_ext, $allowed, true)) {
            $new_filename = 'surat_' . $user_id . '_' . time() . '.' . $file_ext;
            $destination  = $upload_letter_dir . '/' . $new_filename;
            if (move_uploaded_file($file_tmp, $destination)) {
                $attachment_url = '../uploads/letters/' . $new_filename;
            }
        }
    }

    if ($request_type === '') {
        $message = "Jenis surat permohonan wajib dipilih.";
        $message_type = "error";
    } else {
        $stmt_in = $pdo->prepare("
            INSERT INTO service_requests (user_id, request_type, target_date, notes, attachment_url, status) 
            VALUES (?, ?, ?, ?, ?, 'menunggu')
        ");
        $stmt_in->execute([$user_id, $request_type, $target_date, $notes, $attachment_url]);
        logActivity($pdo, 'SUBMIT_REQUEST', "Mengajukan permohonan: $request_type");
        $message = "Permohonan surat berhasil dikirim ke bagian Tata Usaha.";
        $message_type = "success";
    }
}

// -------------------------------------------------------------
// 2. STAF / ADMIN: PERBARUI STATUS PERMOHONAN & INTEGRASI PRESENSI
// -------------------------------------------------------------
if (isset($_GET['update_id']) && isset($_GET['new_status']) && $is_staff) {
    $update_id = (int) $_GET['update_id'];
    $new_status = $_GET['new_status'];

    $valid_statuses = ['menunggu', 'diproses', 'selesai', 'ditolak'];
    if (in_array($new_status, $valid_statuses, true)) {
        // Ambil data permohonan
        $stmt_get = $pdo->prepare("
            SELECT r.*, u.role as applicant_role 
            FROM service_requests r 
            JOIN users u ON r.user_id = u.id 
            WHERE r.id = ?
        ");
        $stmt_get->execute([$update_id]);
        $req_data = $stmt_get->fetch();

        if ($req_data) {
            $stmt_up = $pdo->prepare("UPDATE service_requests SET status = ?, processed_by = ? WHERE id = ?");
            $stmt_up->execute([$new_status, $user_id, $update_id]);

            // Jika status disetujui ('selesai') dan berupa surat izin/sakit, sinkron otomatis ke tabel student_attendance!
            if ($new_status === 'selesai') {
                $rtype = $req_data['request_type'];
                $is_leave = (stripos($rtype, 'Izin') !== false || stripos($rtype, 'Sakit') !== false || stripos($rtype, 'Dispensasi') !== false);

                if ($is_leave) {
                    $stu_id = null;
                    if ($req_data['applicant_role'] === 'siswa') {
                        $stu_id = (int) $req_data['user_id'];
                    } elseif ($req_data['applicant_role'] === 'orang_tua') {
                        $c_id = $pdo->prepare("SELECT student_id FROM parent_students WHERE parent_id = ? LIMIT 1");
                        $c_id->execute([$req_data['user_id']]);
                        $stu_id = $c_id->fetchColumn();
                    }

                    if ($stu_id) {
                        $att_date = $req_data['target_date'] ?: date('Y-m-d');
                        $att_status = (stripos($rtype, 'Sakit') !== false) ? 'sakit' : 'izin';
                        $att_notes  = "Disetujui TU via Permohonan #" . $req_data['id'] . " (" . $rtype . ")";

                        $stmt_att_sync = $pdo->prepare("
                            INSERT INTO student_attendance (student_id, date, status, notes, recorded_by)
                            VALUES (?, ?, ?, ?, ?)
                            ON DUPLICATE KEY UPDATE
                                status = VALUES(status),
                                notes = VALUES(notes),
                                recorded_by = VALUES(recorded_by)
                        ");
                        $stmt_att_sync->execute([$stu_id, $att_date, $att_status, $att_notes, $user_id]);
                    }
                }
            }

            logActivity($pdo, 'UPDATE_REQUEST', "Mengubah status permohonan #$update_id menjadi: $new_status");
            $message = "Status permohonan surat berhasil diubah menjadi: " . strtoupper($new_status);
            $message_type = "success";
        }
    }
}

// -------------------------------------------------------------
// 3. QUERY PERMOHONAN SURAT
// -------------------------------------------------------------
if ($is_staff) {
    $stmt = $pdo->query("
        SELECT r.*, u.name as applicant_name, u.role as applicant_role, u.email as applicant_email, p.name as processor_name
        FROM service_requests r
        JOIN users u ON r.user_id = u.id
        LEFT JOIN users p ON r.processed_by = p.id
        ORDER BY r.id DESC
    ");
} else {
    $stmt = $pdo->prepare("
        SELECT r.*, u.name as applicant_name, u.role as applicant_role, u.email as applicant_email, p.name as processor_name
        FROM service_requests r
        JOIN users u ON r.user_id = u.id
        LEFT JOIN users p ON r.processed_by = p.id
        WHERE r.user_id = ?
        ORDER BY r.id DESC
    ");
    $stmt->execute([$user_id]);
}
$requests_list = $stmt->fetchAll();

$page_title = "Layanan Surat Tata Usaha";
require_once __DIR__ . "/includes/header.php";
?>

<div class="mb-8 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
    <div>
        <h1 class="text-2xl sm:text-3xl font-extrabold text-white flex items-center gap-2">
            <span>📋</span> Layanan Surat Tata Usaha
        </h1>
        <p class="mt-1 text-sm text-slate-400">
            <?= $is_staff ? 'Kelola dan proses permohonan surat keterangan, izin sakit, dan cetak dokumen resmi.' : 'Ajukan surat keterangan aktif, permohonan dispensasi, dan izin sakit resmi sekolah.' ?>
        </p>
    </div>

    <div class="flex items-center gap-2">
        <?php if ($is_staff): ?>
            <a href="attendance_report.php" class="rounded-xl border border-white/10 bg-white/5 hover:bg-white/10 px-4 py-2.5 text-sm font-semibold text-slate-300 transition flex items-center gap-1.5">
                <span>📑</span> Laporan Presensi TU
            </a>
        <?php else: ?>
            <button onclick="document.getElementById('modalCreateRequest').classList.remove('hidden')" 
                    class="inline-flex items-center gap-2 rounded-xl bg-blue-600 hover:bg-blue-500 px-4 py-2.5 text-sm font-semibold text-white shadow-lg shadow-blue-500/20 transition">
                <span>➕</span> Ajukan Permohonan Surat
            </button>
        <?php endif; ?>
    </div>
</div>

<!-- Alert Notifikasi -->
<?php if ($message !== ''): ?>
    <div class="mb-6 rounded-2xl border p-4 text-sm flex items-center justify-between <?= $message_type === 'success' ? 'border-emerald-500/30 bg-emerald-500/10 text-emerald-300' : 'border-rose-500/30 bg-rose-500/10 text-rose-300' ?>">
        <div class="flex items-center gap-3">
            <span><?= $message_type === 'success' ? '✅' : '⚠️' ?></span>
            <span><?= htmlspecialchars($message) ?></span>
        </div>
        <button onclick="this.parentElement.remove()" class="text-xs font-semibold opacity-70 hover:opacity-100">✕</button>
    </div>
<?php endif; ?>

<!-- Tabel Permohonan Layanan -->
<div class="overflow-hidden rounded-3xl border border-white/10 bg-slate-900/60 shadow-2xl backdrop-blur">
    <div class="overflow-x-auto">
        <table class="w-full text-left text-sm text-slate-300">
            <thead class="border-b border-white/10 bg-white/5 text-xs uppercase tracking-wider text-slate-400">
                <tr>
                    <th class="px-6 py-4 font-semibold">Jenis Surat</th>
                    <th class="px-6 py-4 font-semibold">Pemohon</th>
                    <th class="px-4 py-4 font-semibold">Tanggal Diperlukan</th>
                    <th class="px-6 py-4 font-semibold text-center">Status Layanan</th>
                    <th class="px-6 py-4 font-semibold">Diajukan</th>
                    <th class="px-6 py-4 font-semibold text-right">Aksi / Cetak</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-white/5">
                <?php if (empty($requests_list)): ?>
                    <tr>
                        <td colspan="6" class="py-12 text-center text-slate-400 text-sm">
                            Belum ada riwayat permohonan surat.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($requests_list as $r): ?>
                        <tr class="hover:bg-white/[0.02] transition">
                            <td class="px-6 py-4">
                                <div class="font-semibold text-white"><?= htmlspecialchars($r['request_type']) ?></div>
                                <?php if (!empty($r['notes'])): ?>
                                    <div class="text-xs text-slate-400 mt-1 italic">"<?= htmlspecialchars($r['notes']) ?>"</div>
                                <?php endif; ?>
                                <?php if (!empty($r['attachment_url'])): ?>
                                    <a href="<?= htmlspecialchars($r['attachment_url']) ?>" target="_blank" class="inline-flex items-center gap-1 text-[11px] text-blue-400 hover:underline mt-1">
                                        <span>📎</span> Lampiran Berkas ↗
                                    </a>
                                <?php endif; ?>
                            </td>
                            <td class="px-6 py-4">
                                <div class="font-medium text-slate-200"><?= htmlspecialchars($r['applicant_name']) ?></div>
                                <div class="text-xs text-slate-400">
                                    <span class="inline-flex items-center rounded border px-1.5 py-0.2 text-[10px] <?= getRoleBadge($r['applicant_role']) ?>">
                                        <?= htmlspecialchars(getRoleLabel($r['applicant_role'])) ?>
                                    </span>
                                </div>
                            </td>
                            <td class="px-4 py-4 text-xs font-mono text-slate-400">
                                <?= $r['target_date'] ? date('d M Y', strtotime($r['target_date'])) : '-' ?>
                            </td>
                            <td class="px-6 py-4 text-center">
                                <span class="inline-flex items-center rounded-lg border px-2.5 py-1 text-xs font-semibold uppercase tracking-wider <?= getStatusBadge($r['status']) ?>">
                                    <?= htmlspecialchars($r['status']) ?>
                                </span>
                                <?php if (!empty($r['processor_name'])): ?>
                                    <div class="text-[10px] text-slate-500 mt-1 font-mono">Oleh: <?= htmlspecialchars($r['processor_name']) ?></div>
                                <?php endif; ?>
                            </td>
                            <td class="px-6 py-4 text-xs text-slate-400 font-mono">
                                <?= date('d M Y, H:i', strtotime($r['created_at'])) ?>
                            </td>
                            <td class="px-6 py-4 text-right">
                                <div class="flex items-center justify-end gap-1.5">
                                    <?php if ($r['status'] === 'selesai'): ?>
                                        <a href="request_print.php?id=<?= $r['id'] ?>" target="_blank" 
                                           class="rounded-xl border border-emerald-500/30 bg-emerald-500/10 hover:bg-emerald-500/20 px-3 py-1.5 text-xs font-bold text-emerald-300 transition flex items-center gap-1">
                                            <span>🖨️</span> Cetak Surat
                                        </a>
                                    <?php endif; ?>

                                    <?php if ($is_staff): ?>
                                        <?php if ($r['status'] !== 'diproses'): ?>
                                            <a href="requests.php?update_id=<?= $r['id'] ?>&new_status=diproses" 
                                               class="rounded-xl border border-blue-500/30 bg-blue-500/10 px-2.5 py-1.5 text-xs font-medium text-blue-300 hover:bg-blue-500/20 transition">
                                                Proses
                                            </a>
                                        <?php endif; ?>
                                        <?php if ($r['status'] !== 'selesai'): ?>
                                            <a href="requests.php?update_id=<?= $r['id'] ?>&new_status=selesai" 
                                               class="rounded-xl border border-emerald-500/30 bg-emerald-500/10 px-2.5 py-1.5 text-xs font-medium text-emerald-300 hover:bg-emerald-500/20 transition">
                                                Setujui
                                            </a>
                                        <?php endif; ?>
                                        <?php if ($r['status'] !== 'ditolak'): ?>
                                            <a href="requests.php?update_id=<?= $r['id'] ?>&new_status=ditolak" 
                                               class="rounded-xl border border-rose-500/30 bg-rose-500/10 px-2.5 py-1.5 text-xs font-medium text-rose-300 hover:bg-rose-500/20 transition">
                                                Tolak
                                            </a>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Modal Ajukan Surat (Siswa & Orang Tua) -->
<?php if (!$is_staff): ?>
<div id="modalCreateRequest" class="fixed inset-0 z-50 flex items-center justify-center bg-slate-950/80 backdrop-blur p-4 hidden">
    <div class="w-full max-w-lg rounded-3xl border border-white/10 bg-slate-900 p-6 sm:p-8 shadow-2xl">
        <div class="flex items-center justify-between pb-4 border-b border-white/10 mb-6">
            <h3 class="text-lg font-bold text-white flex items-center gap-2">
                <span>📋</span> Permohonan Surat Tata Usaha
            </h3>
            <button onclick="document.getElementById('modalCreateRequest').classList.add('hidden')" class="text-slate-400 hover:text-white text-lg font-bold">✕</button>
        </div>

        <form method="POST" enctype="multipart/form-data" class="space-y-4">
            <input type="hidden" name="action" value="submit_request">

            <div>
                <label class="mb-1 block text-xs font-semibold uppercase text-slate-300">Jenis Surat yang Dibutuhkan *</label>
                <select name="request_type" required class="w-full rounded-xl border border-white/10 bg-slate-950 px-3 py-2.5 text-sm text-white outline-none focus:border-blue-500">
                    <option value="Surat Keterangan Aktif Siswa">Surat Keterangan Aktif Siswa</option>
                    <option value="Surat Izin Sakit Siswa">Surat Izin Sakit Siswa (Lampirkan Surat Dokter)</option>
                    <option value="Surat Izin Dispensasi Acara">Surat Izin Dispensasi / Keperluan Acara Keluarga</option>
                    <option value="Surat Keterangan Berkelakuan Baik">Surat Keterangan Berkelakuan Baik</option>
                    <option value="Surat Rekomendasi Beasiswa">Surat Rekomendasi Beasiswa</option>
                    <option value="Lainnya">Keperluan Lainnya</option>
                </select>
            </div>

            <div>
                <label class="mb-1 block text-xs font-semibold uppercase text-slate-300">Tanggal Keperluan / Izin *</label>
                <input type="date" name="target_date" required value="<?= date('Y-m-d') ?>" class="w-full rounded-xl border border-white/10 bg-slate-950 px-4 py-2 text-sm text-white outline-none focus:border-blue-500">
            </div>

            <div>
                <label class="mb-1 block text-xs font-semibold uppercase text-slate-300">Lampiran Bukti / Surat Dokter (Opsional)</label>
                <input type="file" name="attachment_file" class="w-full rounded-xl border border-white/10 bg-slate-950 px-3 py-2 text-xs text-slate-300 file:mr-3 file:py-1.5 file:px-3 file:rounded-lg file:border-0 file:text-xs file:font-semibold file:bg-blue-600 file:text-white hover:file:bg-blue-500">
                <p class="text-[11px] text-slate-500 mt-1">Format: JPG, PNG, atau PDF. Maksimal 10 MB.</p>
            </div>

            <div>
                <label class="mb-1 block text-xs font-semibold uppercase text-slate-300">Catatan / Keterangan Tambahan</label>
                <textarea name="notes" rows="3" placeholder="Tuliskan alasan izin atau instansi tujuan surat..." class="w-full rounded-xl border border-white/10 bg-slate-950 px-4 py-2 text-sm text-white outline-none focus:border-blue-500"></textarea>
            </div>

            <div class="mt-6 flex justify-end gap-3 pt-4 border-t border-white/10">
                <button type="button" onclick="document.getElementById('modalCreateRequest').classList.add('hidden')" class="rounded-xl border border-white/10 bg-white/5 px-4 py-2 text-sm font-semibold text-slate-300 hover:bg-white/10">
                    Batal
                </button>
                <button type="submit" class="rounded-xl bg-blue-600 hover:bg-blue-500 px-5 py-2 text-sm font-semibold text-white transition shadow-lg shadow-blue-500/25">
                    Kirim Permohonan
                </button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<?php require_once __DIR__ . "/includes/footer.php"; ?>
