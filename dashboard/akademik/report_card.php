<?php
session_start();
require_once __DIR__ . "/../../config/database.php";

requireLogin();

$user_id = (int) $_SESSION['user_id'];
$user_role = $_SESSION['user_role'] ?? 'siswa';
$is_staff_or_teacher = in_array($user_role, ['guru', 'staf', 'administrator'], true);

$school_info = getSchoolSettings($pdo);
$academic_year = isset($_GET['academic_year']) && trim($_GET['academic_year']) !== '' 
    ? trim($_GET['academic_year']) 
    : ($school_info['academic_year'] ?? '2026/2027');
$current_semester = isset($_GET['semester']) && trim($_GET['semester']) !== '' 
    ? trim($_GET['semester']) 
    : ($school_info['academic_semester'] ?? 'Ganjil');

// -------------------------------------------------------------
// 1. RESOLUSI SISWA TARGET & PROTEKSI RBAC (ANTI-IDOR)
// -------------------------------------------------------------
$target_student_id = null;
$parent_children = [];

if ($user_role === 'siswa') {
    // Siswa mutlak hanya boleh melihat rapor miliknya sendiri (anti-IDOR)
    $target_student_id = $user_id;
} elseif ($user_role === 'orang_tua') {
    // Ambil daftar anak yang terhubung ke akun orang tua
    $stmt_p_kids = $pdo->prepare("
        SELECT u.id, u.name, u.nisn, c.name as class_name 
        FROM parent_students ps
        JOIN users u ON ps.student_id = u.id
        LEFT JOIN classes c ON u.class_id = c.id
        WHERE ps.parent_id = ?
        ORDER BY u.name ASC
    ");
    $stmt_p_kids->execute([$user_id]);
    $parent_children = $stmt_p_kids->fetchAll();
    $parent_children_ids = array_map('intval', array_column($parent_children, 'id'));

    $req_child = isset($_GET['student_id']) && is_numeric($_GET['student_id']) ? (int)$_GET['student_id'] : 0;
    if ($req_child > 0 && in_array($req_child, $parent_children_ids, true)) {
        $target_student_id = $req_child;
    } else {
        $target_student_id = !empty($parent_children_ids) ? (int)$parent_children_ids[0] : 0;
    }
} elseif (isset($_GET['student_id']) && is_numeric($_GET['student_id']) && $is_staff_or_teacher) {
    $target_student_id = (int)$_GET['student_id'];
} elseif ($is_staff_or_teacher) {
    // Jika guru adalah wali kelas, prioritaskan siswa binaannya terlebih dahulu
    $target_student_id = 0;
    if ($user_role === 'guru') {
        $stmt_hr_first = $pdo->prepare("
            SELECT u.id FROM users u
            JOIN classes c ON u.class_id = c.id
            WHERE c.homeroom_teacher_id = ? AND u.role = 'siswa'
            ORDER BY u.name ASC LIMIT 1
        ");
        $stmt_hr_first->execute([$user_id]);
        $target_student_id = (int)($stmt_hr_first->fetchColumn() ?: 0);
    }
    if ($target_student_id <= 0) {
        $stmt_first = $pdo->query("SELECT id FROM users WHERE role = 'siswa' ORDER BY class_id ASC, name ASC LIMIT 1");
        $target_student_id = (int)($stmt_first->fetchColumn() ?: 0);
    }
} else {
    $target_student_id = $user_id;
}

// -------------------------------------------------------------
// 2. SIMPAN CATATAN WALI KELAS, KEPUTUSAN KENAIKAN, EKSKUL, DESKRIPSI & PKL (Guru & Admin)
// -------------------------------------------------------------
$feedback_msg = "";
$feedback_type = "";

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_report_notes' && $is_staff_or_teacher) {
    if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
        $feedback_msg = "Token keamanan tidak valid.";
        $feedback_type = "error";
    } else {
        $p_student_id = (int)($_POST['student_id'] ?? $target_student_id);
        $p_year = trim($_POST['academic_year'] ?? $academic_year);
        $p_sem = trim($_POST['semester'] ?? $current_semester);
        $p_notes = trim($_POST['homeroom_notes'] ?? '');
        $p_ekskul = trim($_POST['extracurricular'] ?? '');
        $p_decision = trim($_POST['decision_status'] ?? 'belum_ditetapkan');
        if (!in_array($p_decision, ['naik_kelas', 'tinggal_kelas', 'lulus', 'tidak_lulus', 'belum_ditetapkan'], true)) {
            $p_decision = 'belum_ditetapkan';
        }
        $p_target_class = trim($_POST['target_next_class'] ?? '');

        // Subject descriptions JSON
        $p_subj_descs = null;
        if (isset($_POST['subj_desc']) && is_array($_POST['subj_desc'])) {
            $cleaned_descs = [];
            foreach ($_POST['subj_desc'] as $s_name => $s_desc) {
                if (trim($s_desc) !== '') {
                    $cleaned_descs[trim($s_name)] = trim($s_desc);
                }
            }
            if (!empty($cleaned_descs)) {
                $p_subj_descs = json_encode($cleaned_descs, JSON_UNESCAPED_UNICODE);
            }
        }

        // PKL Internship JSON
        $p_pkl = null;
        if (isset($_POST['pkl_mitra']) && is_array($_POST['pkl_mitra'])) {
            $cleaned_pkl = [];
            foreach ($_POST['pkl_mitra'] as $idx => $mitra) {
                if (trim($mitra) !== '') {
                    $cleaned_pkl[] = [
                        'mitra' => trim($mitra),
                        'lokasi' => trim($_POST['pkl_lokasi'][$idx] ?? ''),
                        'durasi' => trim($_POST['pkl_durasi'][$idx] ?? ''),
                        'nilai' => trim($_POST['pkl_nilai'][$idx] ?? ''),
                        'keterangan' => trim($_POST['pkl_keterangan'][$idx] ?? '')
                    ];
                }
            }
            if (!empty($cleaned_pkl)) {
                $p_pkl = json_encode($cleaned_pkl, JSON_UNESCAPED_UNICODE);
            }
        }

        try {
            $stmt_sn = $pdo->prepare("
                INSERT INTO student_report_notes 
                (student_id, academic_year, semester, homeroom_notes, extracurricular, decision_status, target_next_class, subject_descriptions, pkl_internship, created_by)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE 
                    homeroom_notes = VALUES(homeroom_notes),
                    extracurricular = VALUES(extracurricular),
                    decision_status = VALUES(decision_status),
                    target_next_class = VALUES(target_next_class),
                    subject_descriptions = VALUES(subject_descriptions),
                    pkl_internship = VALUES(pkl_internship),
                    created_by = VALUES(created_by),
                    updated_at = NOW()
            ");
            $stmt_sn->execute([$p_student_id, $p_year, $p_sem, $p_notes, $p_ekskul, $p_decision, $p_target_class ?: null, $p_subj_descs, $p_pkl, $user_id]);
            logActivity($pdo, 'SAVE_REPORT_NOTES', "Menyimpan catatan & keputusan rapor siswa ID $p_student_id");
            $feedback_msg = "Catatan e-rapor, keputusan kenaikan kelas, dan capaian kompetensi berhasil disimpan.";
            $feedback_type = "success";
            $target_student_id = $p_student_id;
        } catch (Exception $e) {
            $feedback_msg = "Gagal menyimpan catatan: " . $e->getMessage();
            $feedback_type = "error";
        }
    }
}

// -------------------------------------------------------------
// 3. AMBIL DATA SISWA LENGKAP (DAPODIK & BIODATA BUKU INDUK)
// -------------------------------------------------------------
$stmt_stu = $pdo->prepare("
    SELECT u.*, c.name as class_name, c.homeroom_teacher_id, 
           hr.name as homeroom_teacher_name, hr.email as homeroom_teacher_email, hr.nip as homeroom_teacher_nip, hr.title_degree as homeroom_teacher_degree, hr.signature_image as homeroom_teacher_sig,
           p.name as parent_name, p.phone as parent_phone, p.address as parent_address
    FROM users u 
    LEFT JOIN classes c ON u.class_id = c.id
    LEFT JOIN users hr ON c.homeroom_teacher_id = hr.id
    LEFT JOIN parent_students ps ON ps.student_id = u.id
    LEFT JOIN users p ON ps.parent_id = p.id
    WHERE u.id = ? AND u.role = 'siswa'
");
$stmt_stu->execute([$target_student_id]);
$student = $stmt_stu->fetch();

// Daftar siswa dan kelas untuk selector guru/admin
$classes_list = [];
$all_students_report = [];
$prev_student_id = null;
$next_student_id = null;
$selected_class_id = isset($_GET['class_id']) && $_GET['class_id'] !== '' ? (int)$_GET['class_id'] : ($student['class_id'] ?? null);

if ($is_staff_or_teacher) {
    $classes_list = $pdo->query("SELECT id, name FROM classes ORDER BY name ASC")->fetchAll();

    $stu_sql = "
        SELECT u.id, u.name, u.nisn, u.class_id, c.name as class_name 
        FROM users u 
        LEFT JOIN classes c ON u.class_id = c.id 
        WHERE u.role = 'siswa'
    ";
    $stu_params = [];
    if ($selected_class_id) {
        $stu_sql .= " AND u.class_id = ?";
        $stu_params[] = $selected_class_id;
    }
    $stu_sql .= " ORDER BY c.name ASC, u.name ASC";
    $stmt_stus = $pdo->prepare($stu_sql);
    $stmt_stus->execute($stu_params);
    $all_students_report = $stmt_stus->fetchAll();

    if (!empty($all_students_report)) {
        $ids = array_column($all_students_report, 'id');
        $curr_idx = array_search($target_student_id, $ids);
        if ($curr_idx !== false) {
            if ($curr_idx > 0) $prev_student_id = $ids[$curr_idx - 1];
            if ($curr_idx < count($ids) - 1) $next_student_id = $ids[$curr_idx + 1];
        }
    }
}

if (!$student) {
    $page_title = "Data Rapor Siswa";
    require_once __DIR__ . "/../includes/header.php";
    ?>
    <div class="max-w-md mx-auto my-12 rounded-3xl border border-rose-500/30 bg-slate-900/90 p-8 text-center shadow-2xl">
        <span class="text-4xl block mb-2 text-rose-400"><i class="fa-solid fa-triangle-exclamation"></i></span>
        <h2 class="text-lg font-bold text-white">Data Siswa Tidak Ditemukan</h2>
        <p class="text-xs text-slate-400 mt-2">Tidak ditemukan data siswa aktif untuk ditampilkan pada halaman rapor ini.</p>
        <a href="../index.php" class="mt-4 inline-flex items-center gap-1.5 rounded-xl bg-slate-800 hover:bg-slate-700 px-6 py-2 text-xs font-bold text-white transition"><i class="fa-solid fa-arrow-left"></i> Kembali ke Dashboard</a>
    </div>
    <?php
    require_once __DIR__ . "/../includes/footer.php";
    exit;
}

// -------------------------------------------------------------
// 4. REKAP KEHADIRAN & CATATAN RAPOR
// -------------------------------------------------------------
$stmt_att = $pdo->prepare("
    SELECT 
        COUNT(CASE WHEN status = 'hadir' THEN 1 END) as hadir,
        COUNT(CASE WHEN status = 'sakit' THEN 1 END) as sakit,
        COUNT(CASE WHEN status = 'izin' THEN 1 END) as izin,
        COUNT(CASE WHEN status = 'alpa' THEN 1 END) as alpa,
        COUNT(*) as total
    FROM student_attendance
    WHERE student_id = ?
");
$stmt_att->execute([$target_student_id]);
$att = $stmt_att->fetch() ?: ['hadir' => 0, 'sakit' => 0, 'izin' => 0, 'alpa' => 0, 'total' => 0];

// Ambil Catatan Wali Kelas & Ekskul dari tabel student_report_notes
$stmt_rn = $pdo->prepare("
    SELECT * FROM student_report_notes 
    WHERE student_id = ? AND academic_year = ? AND semester = ?
    LIMIT 1
");
$stmt_rn->execute([$target_student_id, $academic_year, $current_semester]);
$report_notes = $stmt_rn->fetch() ?: [
    'homeroom_notes' => 'Ahmad menunjukkan ketekunan dan kedisiplinan yang sangat baik selama semester ini. Tingkatkan keaktifan dalam diskusi dan pertahankan prestasi belajar.',
    'extracurricular' => "1. Pramuka Wajib (Predikat: A - Sangat Baik & Berjiwa Pemimpin)\n2. Kelompok Ilmiah Remaja / KIR (Predikat: A - Aktif Berinovasi)",
    'decision_status' => 'belum_ditetapkan',
    'target_next_class' => '',
    'subject_descriptions' => null,
    'pkl_internship' => null,
    'ukk_vocational' => null
];

$custom_subj_descs = [];
if (!empty($report_notes['subject_descriptions'])) {
    $decoded_sd = json_decode($report_notes['subject_descriptions'], true);
    if (is_array($decoded_sd)) {
        $custom_subj_descs = $decoded_sd;
    }
}

$pkl_entries = [];
if (!empty($report_notes['pkl_internship'])) {
    $decoded_pkl = json_decode($report_notes['pkl_internship'], true);
    if (is_array($decoded_pkl)) {
        $pkl_entries = $decoded_pkl;
    }
}

// Map KKM per mata pelajaran
$subject_kkm_map = [];
try {
    $stmt_kkm = $pdo->query("SELECT name, kkm FROM subjects");
    while ($row_kkm = $stmt_kkm->fetch()) {
        $subject_kkm_map[$row_kkm['name']] = (int)($row_kkm['kkm'] ?: 75);
    }
} catch (Exception $e_kkm) {}

// Ambil data ekstrakurikuler terintegrasi
$student_ekskul_list = [];
try {
    $stmt_stu_ekskul = $pdo->prepare("
        SELECT em.*, e.name as ekskul_name, e.category as ekskul_category
        FROM extracurricular_members em
        JOIN extracurriculars e ON em.extracurricular_id = e.id
        WHERE em.student_id = ? AND em.status = 'aktif'
        ORDER BY e.category DESC, e.name ASC
    ");
    $stmt_stu_ekskul->execute([$target_student_id]);
    $student_ekskul_list = $stmt_stu_ekskul->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e_ek) {}

// Ambil data prestasi kejuaraan terintegrasi
$ekskul_achievements = [];
try {
    $stmt_stu_ach = $pdo->prepare("
        SELECT ach.*, e.name as ekskul_name
        FROM extracurricular_achievements ach
        LEFT JOIN extracurriculars e ON ach.extracurricular_id = e.id
        WHERE ach.student_id = ?
        ORDER BY ach.achievement_date DESC LIMIT 5
    ");
    $stmt_stu_ach->execute([$target_student_id]);
    $ekskul_achievements = $stmt_stu_ach->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e_ach) {}

// -------------------------------------------------------------
// 5. NILAI MAPEL & CAPAIAN KOMPETENSI
// -------------------------------------------------------------
$stmt_subjects = $pdo->prepare("
    SELECT DISTINCT subject FROM (
        SELECT subject FROM assignments
        UNION
        SELECT subject FROM exams
        UNION
        SELECT name as subject FROM subjects
    ) as all_subjects
    ORDER BY subject ASC
");
$stmt_subjects->execute();
$dynamic_subjects = $stmt_subjects->fetchAll(PDO::FETCH_COLUMN);

if (empty($dynamic_subjects)) {
    $dynamic_subjects = ['Matematika', 'Bahasa Indonesia', 'Bahasa Inggris', 'Fisika', 'Biologi', 'Kimia', 'Sejarah Indonesia', 'Pendidikan Agama Islam'];
}

$report_grades = [];
$total_final_sum = 0;

foreach ($dynamic_subjects as $subj) {
    // Nilai Tugas
    $stmt_as = $pdo->prepare("
        SELECT AVG(s.score) as avg_score 
        FROM assignment_submissions s
        JOIN assignments a ON s.assignment_id = a.id
        WHERE s.student_id = ? AND a.subject = ? AND s.status = 'selesai' AND s.score IS NOT NULL
    ");
    $stmt_as->execute([$target_student_id, $subj]);
    $tugas_val = round((float)($stmt_as->fetchColumn() ?: 0), 1);

    // Nilai Ujian
    $stmt_ex = $pdo->prepare("
        SELECT e.category, s.score 
        FROM exam_submissions s
        JOIN exams e ON s.exam_id = e.id
        WHERE s.student_id = ? AND e.subject = ? AND (e.is_ppdb IS NULL OR e.is_ppdb = 0)
    ");
    $stmt_ex->execute([$target_student_id, $subj]);
    $ex_rows = $stmt_ex->fetchAll();

    $uh_val = 0; $uts_val = 0; $ukk_val = 0;
    foreach ($ex_rows as $er) {
        $cat = strtolower(trim($er['category'] ?? ''));
        if (in_array($cat, ['slm', 'ujian_harian', 'harian', 'kuis'], true) || str_starts_with($cat, 'latihan')) {
            $uh_val = max($uh_val, (float)$er['score']);
        } elseif (in_array($cat, ['sts', 'uts'], true)) {
            $uts_val = (float)$er['score'];
        } elseif (in_array($cat, ['sas', 'sat', 'ukk', 'uas'], true)) {
            $ukk_val = (float)$er['score'];
        }
    }

    // Hitung Nilai Akhir: Tugas (20%), UH (20%), UTS (25%), UKK (25%), Presensi (10%)
    $att_pct = ((int)($att['total'] ?? 0) > 0) ? round(((int)$att['hadir'] / (int)$att['total']) * 100, 1) : 100.0;
    $na = ($tugas_val * 0.20) + ($uh_val * 0.20) + ($uts_val * 0.25) + ($ukk_val * 0.25) + ($att_pct * 0.10);
    $na = round($na, 1);
    $total_final_sum += $na;

    $subj_kkm = $subject_kkm_map[$subj] ?? 75;
    $pred = ($na >= 85) ? 'A' : (($na >= $subj_kkm) ? 'B' : (($na >= 65) ? 'C' : 'D'));

    // Dual-Sided Narrative Description (Standar e-Rapor Kurikulum Merdeka)
    $has_custom = isset($custom_subj_descs[$subj]) && trim($custom_subj_descs[$subj]) !== '';
    if ($has_custom) {
        $deskripsi = trim($custom_subj_descs[$subj]);
        $custom_used = true;
    } else {
        $custom_used = false;
        if ($na >= 90) {
            $capaian_tinggi = "Menunjukkan penguasaan sangat istimewa dalam seluruh capaian pembelajaran {$subj}, berpikir kritis, dan terampil mengaplikasikan materi secara mandiri.";
            $capaian_bimbingan = "Pertahankan konsistensi belajar prima dan kembangkan penalaran kontekstual tingkat lanjut.";
        } elseif ($na >= 80) {
            $capaian_tinggi = "Menunjukkan penguasaan yang sangat baik dalam memahami konsep pokok {$subj} serta terampil menyelesaikan penugasan dan evaluasi sumatif.";
            $capaian_bimbingan = "Tingkatkan ketelitian dalam penyelesaian studi kasus analisis serta pembiasaan literasi mandiri.";
        } elseif ($na >= $subj_kkm) {
            $capaian_tinggi = "Mencapai Kriteria Ketercapaian Tujuan Pembelajaran (KKTP) {$subj} dengan baik pada pemahaman materi pokok.";
            $capaian_bimbingan = "Perlu penguatan dan latihan mandiri berkelanjutan pada evaluasi sumatif akhir materi.";
        } elseif ($na >= 65) {
            $capaian_tinggi = "Cukup memahami konsep pengantar mata pelajaran {$subj}.";
            $capaian_bimbingan = "Perlu pendampingan intensif dan latihan terstruktur pada materi yang belum mencapai KKTP.";
        } else {
            $capaian_tinggi = "Mulai mengenal materi dasar mata pelajaran {$subj}.";
            $capaian_bimbingan = "Sangat memerlukan pendampingan guru dan remedial terarah secara berkala untuk mencapai ketuntasan.";
        }
        $deskripsi = "<strong>Capaian Tertinggi:</strong> {$capaian_tinggi}<br><span class='text-slate-400'><strong>Perlu Peningkatan:</strong> {$capaian_bimbingan}</span>";
    }

    $report_grades[] = [
        'subject'     => $subj,
        'kkm'         => $subj_kkm,
        'tugas'       => $tugas_val,
        'uh'          => $uh_val,
        'uts'         => $uts_val,
        'ukk'         => $ukk_val,
        'na'          => $na,
        'predikat'    => $pred,
        'deskripsi'   => $deskripsi,
        'custom_used' => $custom_used,
        'raw_custom'  => $custom_subj_descs[$subj] ?? ''
    ];
}

// Integrasi Rekam Prestasi & Kedisiplinan dari Modul BK (Fase 2)
$stmt_bk_ach = $pdo->prepare("
    SELECT title, category, incident_date, points 
    FROM counseling_records 
    WHERE student_id = ? AND type = 'prestasi'
    ORDER BY incident_date DESC LIMIT 3
");
$stmt_bk_ach->execute([$target_student_id]);
$bk_achievements = $stmt_bk_ach->fetchAll();

$stmt_bk_pts = $pdo->prepare("
    SELECT COALESCE(SUM(points), 0) as total_violations, COUNT(*) as count_violations 
    FROM counseling_records 
    WHERE student_id = ? AND type = 'pelanggaran'
");
$stmt_bk_pts->execute([$target_student_id]);
$bk_violation_stat = $stmt_bk_pts->fetch();
$bk_violation_points = (int)($bk_violation_stat['total_violations'] ?? 0);
$bk_violation_count = (int)($bk_violation_stat['count_violations'] ?? 0);

$average_score = count($report_grades) > 0 ? round($total_final_sum / count($report_grades), 1) : 0;
$page_title = "E-Rapor Digital - " . htmlspecialchars($student['name']);

// Validasi Status Bebas Tanggungan (Clearance) 3-in-1
$clearance = getStudentClearanceStatus($pdo, $target_student_id);
if (!$is_staff_or_teacher && !$clearance['is_cleared']) {
    ?>
    <!DOCTYPE html>
    <html lang="id">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Akses E-Rapor Ditangguhkan - <?= htmlspecialchars($student['name']) ?></title>
        <script src="https://cdn.jsdelivr.net/npm/@tailwindcss/browser@4"></script>
        <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@fortawesome/fontawesome-free@6.5.1/css/all.min.css">
    </head>
    <body class="min-h-screen bg-slate-950 text-slate-100 flex items-center justify-center p-4 font-sans antialiased">
        <div class="max-w-lg w-full rounded-3xl border border-amber-500/30 bg-slate-900/95 p-6 sm:p-8 shadow-2xl backdrop-blur text-center">
            <div class="inline-flex h-16 w-16 items-center justify-center rounded-2xl bg-amber-500/20 text-amber-400 text-3xl mb-3">
                <i class="fa-solid fa-lock"></i>
            </div>
            <h1 class="text-2xl font-black text-white">E-Rapor Belum Dapat Diakses</h1>
            <p class="text-xs text-slate-400 mt-2 leading-relaxed">
                Akses publikasi nilai rapor ananda <strong class="text-white"><?= htmlspecialchars($student['name']) ?></strong> sementara ditangguhkan karena terdapat administrasi sekolah yang belum terselesaikan.
            </p>

            <div class="my-6 space-y-2.5 text-left text-xs">
                <?php if (!$clearance['finance']['status']): ?>
                    <div class="p-3 rounded-xl border border-rose-500/30 bg-rose-500/10 flex items-center justify-between">
                        <div>
                            <span class="font-bold text-white block">Tunggakan Administrasi Keuangan (SPP)</span>
                            <span class="text-slate-400 text-[11px]"><?= $clearance['finance']['unpaid_bills'] ?> tagihan belum lunas (<?= formatRupiah($clearance['finance']['total_unpaid']) ?>)</span>
                        </div>
                        <a href="../keuangan/payments.php" class="text-rose-400 hover:text-rose-300 font-bold underline">Selesaikan &rarr;</a>
                    </div>
                <?php endif; ?>

                <?php if (!$clearance['library']['status']): ?>
                    <div class="p-3 rounded-xl border border-rose-500/30 bg-rose-500/10 flex items-center justify-between">
                        <div>
                            <span class="font-bold text-white block">Tanggungan Perpustakaan</span>
                            <span class="text-slate-400 text-[11px]"><?= $clearance['library']['active_loans'] ?> buku pinjaman aktif / Denda: <?= formatRupiah($clearance['library']['fines']) ?></span>
                        </div>
                        <a href="../perpustakaan/clearance.php" class="text-rose-400 hover:text-rose-300 font-bold underline">Cek &rarr;</a>
                    </div>
                <?php endif; ?>

                <?php if (!$clearance['counseling']['status']): ?>
                    <div class="p-3 rounded-xl border border-amber-500/30 bg-amber-500/10">
                        <span class="font-bold text-white block">Catatan Bimbingan Konseling</span>
                        <span class="text-slate-400 text-[11px]">Memerlukan konfirmasi pembinaan wali kelas & guru BK (Poin: <?= $clearance['counseling']['points'] ?>)</span>
                    </div>
                <?php endif; ?>
            </div>

            <div class="pt-4 border-t border-white/10 flex items-center justify-between text-xs">
                <a href="../index.php" class="text-slate-400 hover:text-white transition">
                    &larr; Kembali ke Dashboard
                </a>
                <span class="text-slate-500 text-[11px]">Hubungi Tata Usaha Sekolah</span>
            </div>
        </div>
    </body>
    </html>
    <?php
    exit;
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $page_title ?></title>
    <script src="https://cdn.jsdelivr.net/npm/@tailwindcss/browser@4"></script>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@fortawesome/fontawesome-free@6.5.1/css/all.min.css">
    <style>
        @page {
            size: A4 portrait;
            margin: 12mm 15mm 15mm 15mm;
        }
        @media print {
            * {
                -webkit-print-color-adjust: exact !important;
                print-color-adjust: exact !important;
            }
            body { 
                background: white !important; 
                color: #0f172a !important; 
                padding: 0 !important; 
                font-size: 10.5pt !important;
            }
            .no-print { display: none !important; }
            .print-only { display: block !important; }
            .print-paper { 
                border: none !important; 
                box-shadow: none !important; 
                max-width: 100% !important; 
                padding: 0 !important;
                background: transparent !important;
                color: #000 !important;
            }
            .print-paper * {
                color: #000 !important;
                border-color: #334155 !important;
            }
            .print-paper .text-white { color: #000 !important; }
            .print-paper .text-slate-300, .print-paper .text-slate-400 { color: #333 !important; }
            .print-paper .bg-slate-950, .print-paper .bg-slate-900, .print-paper .bg-white\/5 {
                background: transparent !important;
            }
            table { border-collapse: collapse !important; width: 100% !important; }
            th, td { border: 1px solid #333 !important; }
            thead th { background-color: #f1f5f9 !important; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
            .page-break {
                page-break-after: always;
                break-after: page;
            }
            /* Control active tab printing vs full bundle printing */
            body.print-active-only .tab-pane:not(.active-tab) {
                display: none !important;
            }
            body.print-bundle-all .tab-pane {
                display: block !important;
                margin-bottom: 25mm !important;
            }
        }
    </style>
</head>
<body class="min-h-screen bg-slate-950 text-slate-100 flex flex-col items-center p-3 sm:p-6 font-sans antialiased print-active-only">

    <?php 
    $is_my_homeroom_student = ($is_staff_or_teacher && !empty($student['homeroom_teacher_id']) && (int)$student['homeroom_teacher_id'] === $user_id);
    ?>

    <!-- Top Action & Navigation Bar (No-Print) -->
    <div class="no-print w-full max-w-4xl mb-4 space-y-3">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div class="flex flex-wrap items-center gap-2">
                <a href="../index.php" class="inline-flex items-center gap-1.5 rounded-xl border border-white/10 bg-white/5 px-3 py-1.5 text-xs font-semibold text-slate-300 hover:bg-white/10 transition">
                    <i class="fa-solid fa-arrow-left"></i> Dashboard
                </a>
                <a href="gradebook.php" class="inline-flex items-center gap-1.5 rounded-xl border border-white/10 bg-white/5 px-3 py-1.5 text-xs font-semibold text-slate-300 hover:bg-white/10 transition">
                    <i class="fa-solid fa-chart-column"></i> Leger Nilai
                </a>
                <a href="p5_report.php?student_id=<?= $target_student_id ?>" class="inline-flex items-center gap-1.5 rounded-xl border border-emerald-500/30 bg-emerald-500/10 hover:bg-emerald-500/20 px-3 py-1.5 text-xs font-bold text-emerald-400 transition">
                    <i class="fa-solid fa-award"></i> Rapor P5
                </a>
                <?php if ($is_my_homeroom_student): ?>
                    <span class="inline-flex items-center gap-1.5 rounded-xl border border-blue-500/30 bg-blue-500/15 px-3 py-1.5 text-xs font-bold text-blue-300 shadow-sm" title="Anda bertindak sebagai Wali Kelas resmi untuk rombel ini">
                        <i class="fa-solid fa-chalkboard-user"></i> Wali Kelas: <?= htmlspecialchars($student['class_name'] ?? 'Binaan') ?>
                    </span>
                <?php elseif ($user_role === 'guru'): ?>
                    <span class="inline-flex items-center gap-1.5 rounded-xl border border-purple-500/30 bg-purple-500/10 px-3 py-1.5 text-xs font-semibold text-purple-300">
                        <i class="fa-solid fa-user-tie"></i> Guru Pengampu
                    </span>
                <?php endif; ?>
            </div>

            <div class="flex flex-wrap items-center gap-2">
                <?php if ($is_staff_or_teacher): ?>
                    <button type="button" onclick="document.getElementById('modalEditNotes').classList.remove('hidden')" class="inline-flex items-center gap-1.5 rounded-xl border border-purple-500/30 bg-purple-500/15 hover:bg-purple-500/25 px-3.5 py-2 text-xs font-bold text-purple-300 transition cursor-pointer shadow-sm">
                        <i class="fa-solid fa-pen-to-square"></i> Edit Catatan & Kenaikan
                    </button>
                <?php endif; ?>

                <!-- Tombol Cetak Lembar Aktif -->
                <button type="button" onclick="printActiveSheet()" class="inline-flex items-center gap-1.5 rounded-xl bg-blue-600 hover:bg-blue-500 px-4 py-2 text-xs font-bold text-white shadow-lg shadow-blue-500/25 transition cursor-pointer">
                    <i class="fa-solid fa-print"></i> Cetak Lembar Aktif
                </button>

                <!-- Tombol Cetak Berkas Lengkap (Cover + Biodata + Nilai) -->
                <button type="button" onclick="printBundleComplete()" class="inline-flex items-center gap-1.5 rounded-xl border border-emerald-500/30 bg-emerald-500/15 hover:bg-emerald-500/25 px-4 py-2 text-xs font-bold text-emerald-300 shadow-sm transition cursor-pointer" title="Cetak bundel resmi e-rapor sekaligus (Cover, Biodata, dan Lembar Nilai)">
                    <i class="fa-solid fa-book-bookmark"></i> Cetak 1 Berkas Lengkap
                </button>
            </div>
        </div>

        <!-- View Switcher Tabs (Nilai Akademik, Cover Rapor, Biodata Peserta Didik) -->
        <div class="flex items-center justify-between flex-wrap gap-2 pt-1 border-t border-white/10">
            <div class="inline-flex p-1 rounded-2xl bg-slate-900 border border-white/10 gap-1 text-xs">
                <button id="btnTabReport" type="button" onclick="switchReportTab('paneReport')" class="inline-flex items-center gap-1.5 rounded-xl border border-blue-500/40 bg-blue-500/20 px-3.5 py-1.5 text-xs font-bold text-blue-300 transition">
                    <i class="fa-solid fa-file-lines"></i> 1. Lembar Nilai Akademik
                </button>
                <button id="btnTabCover" type="button" onclick="switchReportTab('paneCover')" class="inline-flex items-center gap-1.5 rounded-xl border border-white/10 bg-white/5 hover:bg-white/10 px-3.5 py-1.5 text-xs font-semibold text-slate-300 transition">
                    <i class="fa-solid fa-shield-halved"></i> 2. Halaman Sampul (Cover)
                </button>
                <button id="btnTabBiodata" type="button" onclick="switchReportTab('paneBiodata')" class="inline-flex items-center gap-1.5 rounded-xl border border-white/10 bg-white/5 hover:bg-white/10 px-3.5 py-1.5 text-xs font-semibold text-slate-300 transition">
                    <i class="fa-solid fa-id-card"></i> 3. Biodata Siswa (Buku Induk)
                </button>
            </div>

            <div class="text-[11px] text-slate-400">
                <span>Standar e-Rapor <strong>Kurikulum Merdeka</strong> & Kemendikbudristek</span>
            </div>
        </div>

        <?php if (!empty($feedback_msg)): ?>
            <div class="rounded-xl border p-3 text-xs <?= $feedback_type === 'success' ? 'border-emerald-500/30 bg-emerald-500/10 text-emerald-300' : 'border-rose-500/30 bg-rose-500/10 text-rose-300' ?>">
                <i class="fa-solid <?= $feedback_type === 'success' ? 'fa-circle-check text-emerald-400' : 'fa-circle-exclamation text-rose-400' ?> mr-1.5"></i>
                <?= htmlspecialchars($feedback_msg) ?>
            </div>
        <?php endif; ?>

        <!-- Selector Bar for Teachers/Admin -->
        <?php if ($is_staff_or_teacher): ?>
            <div class="rounded-2xl border border-white/10 bg-slate-900/80 p-3.5 backdrop-blur flex flex-wrap items-center justify-between gap-3">
                <div class="flex flex-wrap items-center gap-2.5">
                    <span class="text-xs font-bold uppercase tracking-wider text-slate-400">Pilih Siswa:</span>
                    
                    <form method="GET" class="flex flex-wrap items-center gap-2">
                        <select name="class_id" onchange="this.form.submit()" class="rounded-xl border border-white/10 bg-slate-950 px-3 py-1.5 text-xs text-white focus:outline-none">
                            <option value="">Semua Rombel</option>
                            <?php foreach ($classes_list as $cl): ?>
                                <option value="<?= $cl['id'] ?>" <?= ($selected_class_id == $cl['id']) ? 'selected' : '' ?>>
                                    Kelas <?= htmlspecialchars($cl['name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>

                        <select name="student_id" onchange="this.form.submit()" class="rounded-xl border border-white/10 bg-slate-950 px-3 py-1.5 text-xs text-white focus:outline-none max-w-[200px]">
                            <?php foreach ($all_students_report as $st): ?>
                                <option value="<?= $st['id'] ?>" <?= ($target_student_id == $st['id']) ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($st['name']) ?> (<?= htmlspecialchars($st['class_name'] ?: 'Umum') ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>

                        <!-- Pilihan Semester (Preset + Manual) -->
                        <select name="semester" onchange="this.form.submit()" class="rounded-xl border border-purple-500/30 bg-purple-500/10 px-3 py-1.5 text-xs font-semibold text-purple-300 focus:outline-none">
                            <option value="Ganjil" <?= ($current_semester === 'Ganjil') ? 'selected' : '' ?>>Sem. Ganjil</option>
                            <option value="Genap" <?= ($current_semester === 'Genap') ? 'selected' : '' ?>>Sem. Genap</option>
                            <?php if (!in_array($current_semester, ['Ganjil', 'Genap'])): ?>
                                <option value="<?= htmlspecialchars($current_semester) ?>" selected><?= htmlspecialchars($current_semester) ?> (Manual)</option>
                            <?php endif; ?>
                        </select>

                        <!-- Pilihan / Input Tahun Ajaran -->
                        <div class="inline-flex items-center gap-1 rounded-xl border border-white/10 bg-slate-950 px-2 py-1">
                            <span class="text-[10px] text-slate-400 font-mono">T.A:</span>
                            <input type="text" name="academic_year" value="<?= htmlspecialchars($academic_year) ?>" 
                                   title="Tekan Enter untuk menerapkan Tahun Ajaran kustom"
                                   placeholder="2026/2027" 
                                   class="w-20 bg-transparent text-xs font-mono text-white focus:outline-none text-center">
                            <button type="submit" class="text-[10px] text-blue-400 hover:text-white px-1" title="Terapkan"><i class="fa-solid fa-check"></i></button>
                        </div>
                    </form>
                </div>

                <!-- Next/Prev Buttons -->
                <div class="flex items-center gap-1.5">
                    <?php if ($prev_student_id): ?>
                        <a href="report_card.php?student_id=<?= $prev_student_id ?>&class_id=<?= $selected_class_id ?>&semester=<?= urlencode($current_semester) ?>&academic_year=<?= urlencode($academic_year) ?>" class="rounded-xl border border-white/10 bg-white/5 hover:bg-white/10 px-2.5 py-1.5 text-xs text-slate-300">
                            ◀ Sebelumnya
                        </a>
                    <?php endif; ?>

                    <?php if ($next_student_id): ?>
                        <a href="report_card.php?student_id=<?= $next_student_id ?>&class_id=<?= $selected_class_id ?>&semester=<?= urlencode($current_semester) ?>&academic_year=<?= urlencode($academic_year) ?>" class="rounded-xl border border-white/10 bg-white/5 hover:bg-white/10 px-2.5 py-1.5 text-xs text-slate-300">
                            Berikutnya ▶
                        </a>
                    <?php endif; ?>
                </div>
            </div>
        <?php endif; ?>

        <?php if ($user_role === 'orang_tua' && count($parent_children) > 1): ?>
            <div class="rounded-2xl border border-blue-500/30 bg-blue-950/40 p-3.5 backdrop-blur flex flex-wrap items-center justify-between gap-3">
                <div class="flex items-center gap-2">
                    <span class="text-xs font-bold text-blue-300"><i class="fa-solid fa-children mr-1"></i> Pilih Data Anak:</span>
                    <form method="GET" class="inline-flex items-center gap-2">
                        <select name="student_id" onchange="this.form.submit()" class="rounded-xl border border-blue-500/40 bg-slate-900 px-3 py-1.5 text-xs font-semibold text-white focus:outline-none">
                            <?php foreach ($parent_children as $pk): ?>
                                <option value="<?= $pk['id'] ?>" <?= ($target_student_id == $pk['id']) ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($pk['name']) ?> (<?= htmlspecialchars($pk['class_name'] ?: 'Umum') ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <input type="hidden" name="semester" value="<?= htmlspecialchars($current_semester) ?>">
                        <input type="hidden" name="academic_year" value="<?= htmlspecialchars($academic_year) ?>">
                    </form>
                </div>
                <span class="text-[11px] text-slate-400">Menampilkan buku rapor untuk anak terpilih</span>
            </div>
        <?php endif; ?>
    </div>

    <!-- ========================================================= -->
    <!-- LEMBAR 1: LAPORAN NILAI HASIL BELAJAR AKADEMIK (A4)       -->
    <!-- ========================================================= -->
    <div id="paneReport" class="tab-pane active-tab print-paper w-full max-w-4xl rounded-3xl border border-white/15 bg-slate-900/95 p-6 sm:p-10 shadow-2xl backdrop-blur page-break">
        
        <!-- Kop Surat Resmi -->
        <div class="flex items-center gap-4 border-b-2 border-white/20 pb-5 mb-5">
            <?php 
                $clean_kop_logo = !empty($school_info['school_logo']) ? resolveAssetUrl($school_info['school_logo'], '../../') : '';
                $kop_logo_exists = !empty($clean_kop_logo) && (strpos($clean_kop_logo, 'http') === 0 || file_exists(dirname(__DIR__, 2) . '/' . resolveAssetUrl($school_info['school_logo'])));
            ?>
            <?php if ($kop_logo_exists): ?>
                <img src="<?= htmlspecialchars($clean_kop_logo) ?>" alt="Logo" class="h-20 w-20 object-contain shrink-0">
            <?php else: ?>
                <div class="h-16 w-16 rounded-2xl bg-blue-600/20 border border-blue-500/40 text-blue-400 flex items-center justify-center text-3xl font-black shrink-0">
                    <i class="fa-solid fa-school"></i>
                </div>
            <?php endif; ?>
            <div class="flex-1 text-center pr-6">
                <h1 class="text-2xl sm:text-3xl font-black uppercase tracking-tight text-white">
                    <?= htmlspecialchars($school_info['school_name']) ?>
                </h1>
                <p class="text-xs text-slate-300 mt-1"><?= htmlspecialchars($school_info['school_address']) ?></p>
                <p class="text-xs text-slate-400 font-mono">Telepon: <?= htmlspecialchars($school_info['school_phone']) ?> | Email: <?= htmlspecialchars($school_info['school_email']) ?></p>
                <div class="inline-block mt-2 px-4 py-0.5 rounded-full border border-white/20 bg-white/5 text-[11px] font-bold uppercase tracking-widest text-blue-400">
                    Laporan Hasil Belajar Peserta Didik (E-Rapor)
                </div>
            </div>
        </div>

        <!-- Identitas Siswa -->
        <div class="grid grid-cols-2 gap-4 text-xs mb-5 bg-slate-950/60 p-4 rounded-2xl border border-white/10">
            <div class="space-y-1.5">
                <div class="flex"><span class="w-32 text-slate-400">Nama Peserta Didik</span><span class="font-bold text-white">: <?= htmlspecialchars($student['name']) ?></span></div>
                <div class="flex"><span class="w-32 text-slate-400">Nomor Induk / NISN</span><span class="font-mono text-slate-200">: <?= htmlspecialchars($student['nisn'] ?: '-') ?></span></div>
                <div class="flex"><span class="w-32 text-slate-400">Rombongan Belajar</span><span class="font-semibold text-slate-200">: <?= htmlspecialchars($student['class_name'] ?? 'Belum ditentukan') ?></span></div>
            </div>
            <div class="space-y-1.5">
                <div class="flex"><span class="w-32 text-slate-400">Semester</span><span class="font-semibold text-white">: <?= htmlspecialchars($current_semester) ?></span></div>
                <div class="flex"><span class="w-32 text-slate-400">Tahun Pelajaran</span><span class="text-slate-200">: <?= htmlspecialchars($academic_year) ?></span></div>
                <div class="flex"><span class="w-32 text-slate-400">Rata-rata Nilai</span><span class="font-bold text-emerald-400">: <?= number_format($average_score, 1) ?> (Predikat <?= $average_score >= 85 ? 'A' : ($average_score >= 75 ? 'B' : 'C') ?>)</span></div>
            </div>
        </div>

        <!-- A. Tabel Capaian Akademik (Kurikulum Merdeka Dual-Sided) -->
        <div class="mb-5">
            <div class="flex items-center justify-between mb-2">
                <h3 class="text-xs font-bold uppercase tracking-wider text-slate-300">A. Nilai Capaian Kompetensi Pembelajaran</h3>
                <span class="text-[10px] text-slate-400 font-mono italic">Skala 0 - 100</span>
            </div>
            <div class="overflow-x-auto rounded-2xl border border-white/10">
                <table class="w-full text-left text-xs text-slate-300">
                    <thead class="border-b border-white/10 bg-white/5 text-[10px] uppercase font-bold text-slate-400">
                        <tr>
                            <th class="px-3 py-2.5 text-center w-8">No</th>
                            <th class="px-4 py-2.5">Mata Pelajaran</th>
                            <th class="px-2.5 py-2.5 text-center" title="Kriteria Ketercapaian Tujuan Pembelajaran">KKTP</th>
                            <th class="px-2.5 py-2.5 text-center">Tugas</th>
                            <th class="px-2.5 py-2.5 text-center">UH/SLM</th>
                            <th class="px-2.5 py-2.5 text-center">UTS/STS</th>
                            <th class="px-2.5 py-2.5 text-center">UKK/SAS</th>
                            <th class="px-3 py-2.5 text-center font-bold text-white">Nilai Akhir</th>
                            <th class="px-2.5 py-2.5 text-center">Pred</th>
                            <th class="px-4 py-2.5">Capaian Kompetensi (Tertinggi & Peningkatan)</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-white/5">
                        <?php foreach ($report_grades as $i => $rg): ?>
                            <tr>
                                <td class="px-3 py-2.5 text-center font-mono text-slate-500"><?= $i + 1 ?></td>
                                <td class="px-4 py-2.5 font-semibold text-white">
                                    <?= htmlspecialchars($rg['subject']) ?>
                                    <?php if (!empty($rg['custom_used'])): ?>
                                        <span class="text-[9px] text-purple-400 ml-1 no-print font-normal" title="Deskripsi telah disunting kustom oleh guru"><i class="fa-solid fa-pen-nib"></i></span>
                                    <?php endif; ?>
                                </td>
                                <td class="px-2.5 py-2.5 text-center font-mono text-slate-400"><?= $rg['kkm'] ?></td>
                                <td class="px-2.5 py-2.5 text-center font-mono"><?= $rg['tugas'] ?></td>
                                <td class="px-2.5 py-2.5 text-center font-mono"><?= $rg['uh'] ?></td>
                                <td class="px-2.5 py-2.5 text-center font-mono"><?= $rg['uts'] ?></td>
                                <td class="px-2.5 py-2.5 text-center font-mono"><?= $rg['ukk'] ?></td>
                                <td class="px-3 py-2.5 text-center font-mono font-bold <?= $rg['na'] >= $rg['kkm'] ? 'text-emerald-300' : 'text-rose-400' ?>">
                                    <?= number_format($rg['na'], 1) ?>
                                </td>
                                <td class="px-2.5 py-2.5 text-center font-bold"><?= $rg['predikat'] ?></td>
                                <td class="px-4 py-2.5 text-[11px] text-slate-300 leading-relaxed"><?= $rg['deskripsi'] ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- B & C. Ketidakhadiran & Ekstrakurikuler -->
        <div class="mb-5 grid grid-cols-1 sm:grid-cols-2 gap-4">
            <!-- B. Presensi -->
            <div>
                <h3 class="text-xs font-bold uppercase tracking-wider text-slate-300 mb-2">B. Rekapitulasi Ketidakhadiran</h3>
                <div class="rounded-2xl border border-white/10 bg-slate-950/60 p-3.5 text-xs space-y-1.5">
                    <div class="flex justify-between border-b border-white/5 pb-1"><span>1. Sakit (S)</span><strong class="font-mono"><?= $att['sakit'] ?> hari</strong></div>
                    <div class="flex justify-between border-b border-white/5 pb-1"><span>2. Izin (I)</span><strong class="font-mono"><?= $att['izin'] ?> hari</strong></div>
                    <div class="flex justify-between border-b border-white/5 pb-1"><span>3. Tanpa Keterangan / Alpa (A)</span><strong class="font-mono <?= $att['alpa'] > 0 ? 'text-rose-400' : '' ?>"><?= $att['alpa'] ?> hari</strong></div>
                    <div class="flex justify-between pt-1"><span>Total Kehadiran Efektif</span><strong class="font-mono text-emerald-400"><?= $att['hadir'] ?> hari (<?= $att_pct ?>%)</strong></div>
                </div>
            </div>

            <!-- C. Ekstrakurikuler -->
            <div>
                <h3 class="text-xs font-bold uppercase tracking-wider text-slate-300 mb-2">C. Kegiatan Ekstrakurikuler</h3>
                <div class="rounded-2xl border border-white/10 bg-slate-950/60 p-3 text-xs text-slate-200 min-h-[110px]">
                    <?php if (!empty($student_ekskul_list)): ?>
                        <div class="space-y-2">
                            <?php foreach ($student_ekskul_list as $sek): ?>
                                <div class="p-2 rounded-xl bg-white/[0.03] border border-white/5 flex items-start justify-between gap-2">
                                    <div class="min-w-0">
                                        <span class="font-bold text-white block text-[11px] truncate"><?= htmlspecialchars($sek['ekskul_name']) ?> (<?= ucfirst($sek['ekskul_category']) ?>)</span>
                                        <p class="text-[10px] text-slate-400 italic line-clamp-1">"<?= htmlspecialchars($sek['grade_notes'] ?: 'Aktif mengikuti kegiatan latihan rutin.') ?>"</p>
                                    </div>
                                    <span class="inline-flex items-center justify-center w-6 h-6 rounded-lg font-bold text-xs bg-blue-500/20 text-blue-300 border border-blue-500/30 shrink-0">
                                        <?= htmlspecialchars($sek['grade_predicate'] ?: 'A') ?>
                                    </span>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <div class="whitespace-pre-line leading-relaxed">
                            <?= htmlspecialchars($report_notes['extracurricular'] ?? "1. Pramuka Wajib (Predikat: A)\n2. Kegiatan Olahraga & Seni (Predikat: B)") ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- D. Rekam Prestasi & Kedisiplinan Siswa (Integrasi Bimbingan Konseling / BK) -->
        <div class="mb-5">
            <h3 class="text-xs font-bold uppercase tracking-wider text-slate-300 mb-2">D. Rekam Prestasi & Kedisiplinan (Integrasi BK & Ekskul)</h3>
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 text-xs">
                <div class="rounded-2xl border border-white/10 bg-slate-950/60 p-3.5 space-y-1.5">
                    <span class="text-slate-400 font-semibold block text-[11px] mb-1">
                        <i class="fa-solid fa-trophy text-amber-400 mr-1"></i> Prestasi & Apresiasi Sekolah
                    </span>
                    <?php if (!empty($ekskul_achievements)): ?>
                        <?php foreach ($ekskul_achievements as $ach): ?>
                            <div class="flex items-center justify-between border-b border-white/5 pb-1 gap-2">
                                <div class="min-w-0">
                                    <span class="text-white font-bold block truncate text-[11px]"><?= htmlspecialchars($ach['rank_achieved']) ?> - <?= htmlspecialchars($ach['competition_name']) ?></span>
                                    <span class="text-[9px] text-amber-400 uppercase font-mono">Tingkat <?= htmlspecialchars($ach['level']) ?></span>
                                </div>
                                <span class="font-mono text-amber-400 font-bold shrink-0 text-[10px] bg-amber-500/10 px-1.5 py-0.5 rounded border border-amber-500/20">Juara</span>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    <?php if (!empty($bk_achievements)): ?>
                        <?php foreach ($bk_achievements as $ach): ?>
                            <div class="flex items-center justify-between border-b border-white/5 pb-1">
                                <span class="text-slate-200 truncate"><?= htmlspecialchars($ach['title']) ?></span>
                                <span class="font-mono text-emerald-400 font-bold shrink-0">+<?= $ach['points'] ?> Poin</span>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    <?php if (empty($ekskul_achievements) && empty($bk_achievements)): ?>
                        <p class="text-slate-500 italic text-[11px]">Belum ada catatan perlombaan / penghargaan semester ini.</p>
                    <?php endif; ?>
                </div>

                <div class="rounded-2xl border border-white/10 bg-slate-950/60 p-3.5 space-y-1.5">
                    <span class="text-slate-400 font-semibold block text-[11px] mb-1">
                        <i class="fa-solid fa-shield-halved text-blue-400 mr-1"></i> Indeks Kepatuhan Tata Tertib
                    </span>
                    <div class="flex items-center justify-between border-b border-white/5 pb-1">
                        <span class="text-slate-200">Total Poin Pelanggaran</span>
                        <strong class="font-mono <?= $bk_violation_points > 0 ? 'text-amber-400' : 'text-emerald-400' ?>"><?= $bk_violation_points ?> Poin (<?= $bk_violation_count ?> Kejadian)</strong>
                    </div>
                    <div class="flex items-center justify-between pt-1">
                        <span class="text-slate-200">Status Sikap & Perilaku</span>
                        <strong class="text-emerald-400"><?= $bk_violation_points == 0 ? 'Sangat Baik (Terpuji)' : ($bk_violation_points < 20 ? 'Baik & Kooperatif' : 'Dalam Bimbingan Khusus') ?></strong>
                    </div>
                </div>
            </div>
        </div>

        <!-- E. Catatan Perkembangan Karakter & Rekomendasi Wali Kelas -->
        <div class="mb-5">
            <h3 class="text-xs font-bold uppercase tracking-wider text-slate-300 mb-2">E. Catatan Wali Kelas & Karakter</h3>
            <div class="rounded-2xl border border-white/10 bg-slate-950/60 p-4 text-xs text-slate-200 leading-relaxed italic">
                "<?= htmlspecialchars($report_notes['homeroom_notes'] ?? 'Ananda menunjukkan perkembangan belajar yang positif dan berdisiplin tinggi.') ?>"
            </div>
        </div>

        <!-- F. Praktik Kerja Lapangan (PKL) / Magang (Opsional - Terutama SMK/Vokasi) -->
        <?php if (!empty($pkl_entries)): ?>
        <div class="mb-5">
            <h3 class="text-xs font-bold uppercase tracking-wider text-slate-300 mb-2">F. Praktik Kerja Lapangan (PKL) & Magang Industri</h3>
            <div class="overflow-x-auto rounded-2xl border border-white/10">
                <table class="w-full text-left text-xs text-slate-300">
                    <thead class="border-b border-white/10 bg-white/5 text-[10px] uppercase font-bold text-slate-400">
                        <tr>
                            <th class="px-3 py-2 text-center w-8">No</th>
                            <th class="px-4 py-2">Mitra Dunia Usaha / Industri (DUDI)</th>
                            <th class="px-3 py-2">Lokasi</th>
                            <th class="px-3 py-2 text-center">Durasi</th>
                            <th class="px-3 py-2 text-center">Nilai</th>
                            <th class="px-4 py-2">Keterangan / Kompetensi yang Dikuasai</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-white/5">
                        <?php foreach ($pkl_entries as $idx_pkl => $pkl): ?>
                            <tr>
                                <td class="px-3 py-2 text-center font-mono text-slate-500"><?= $idx_pkl + 1 ?></td>
                                <td class="px-4 py-2 font-semibold text-white"><?= htmlspecialchars($pkl['mitra'] ?? '-') ?></td>
                                <td class="px-3 py-2 text-slate-300"><?= htmlspecialchars($pkl['lokasi'] ?? '-') ?></td>
                                <td class="px-3 py-2 text-center text-slate-300"><?= htmlspecialchars($pkl['durasi'] ?? '-') ?></td>
                                <td class="px-3 py-2 text-center font-mono font-bold text-emerald-400"><?= htmlspecialchars($pkl['nilai'] ?? '-') ?></td>
                                <td class="px-4 py-2 text-[11px] text-slate-300"><?= htmlspecialchars($pkl['keterangan'] ?? '-') ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php endif; ?>

        <!-- KOTAK KEPUTUSAN KENAIKAN KELAS / KELULUSAN (Standar Resmi Rapor Akhir Tahun / Semester Genap) -->
        <?php 
        $decision_status = $report_notes['decision_status'] ?? 'belum_ditetapkan';
        $target_next_class = $report_notes['target_next_class'] ?? '';
        $is_genap = (strtolower($current_semester) === 'genap');
        ?>
        <div class="mb-6 rounded-2xl border-2 <?= $decision_status === 'tinggal_kelas' || $decision_status === 'tidak_lulus' ? 'border-rose-500/40 bg-rose-500/5' : 'border-blue-500/40 bg-blue-500/5' ?> p-4 text-xs">
            <div class="flex items-center justify-between mb-2">
                <h3 class="font-black uppercase tracking-wider text-white flex items-center gap-2">
                    <i class="fa-solid fa-graduation-cap text-blue-400"></i>
                    Keputusan Hasil Akhir Pembelajaran
                </h3>
                <span class="text-[10px] font-mono uppercase px-2 py-0.5 rounded-full border border-white/20 bg-white/5 text-slate-300">
                    Semester <?= htmlspecialchars($current_semester) ?> - T.A <?= htmlspecialchars($academic_year) ?>
                </span>
            </div>
            
            <div class="text-slate-200 leading-relaxed">
                <?php if ($is_genap || in_array($decision_status, ['naik_kelas', 'tinggal_kelas', 'lulus', 'tidak_lulus'])): ?>
                    <p class="mb-2">Berdasarkan pencapaian seluruh tujuan pembelajaran, rekapitulasi kehadiran, serta rapat pleno dewan guru, peserta didik dinyatakan:</p>
                    <div class="p-3 rounded-xl border border-white/10 bg-slate-950/80 font-bold text-sm flex flex-wrap items-center gap-3">
                        <?php if ($decision_status === 'naik_kelas'): ?>
                            <span class="inline-flex items-center gap-1.5 text-emerald-400">
                                <i class="fa-solid fa-circle-check text-base"></i> NAIK KE KELAS:
                            </span>
                            <span class="px-3 py-1 rounded-lg bg-emerald-500/20 text-emerald-300 border border-emerald-500/40 tracking-wide">
                                <?= htmlspecialchars(!empty($target_next_class) ? $target_next_class : 'Tingkat Selanjutnya') ?>
                            </span>
                        <?php elseif ($decision_status === 'tinggal_kelas'): ?>
                            <span class="inline-flex items-center gap-1.5 text-rose-400">
                                <i class="fa-solid fa-circle-xmark text-base"></i> TINGGAL DI KELAS:
                            </span>
                            <span class="px-3 py-1 rounded-lg bg-rose-500/20 text-rose-300 border border-rose-500/40 tracking-wide">
                                <?= htmlspecialchars(!empty($target_next_class) ? $target_next_class : ($student['class_name'] ?? 'Kelas Semula')) ?>
                            </span>
                        <?php elseif ($decision_status === 'lulus'): ?>
                            <span class="inline-flex items-center gap-1.5 text-emerald-400">
                                <i class="fa-solid fa-certificate text-base"></i> LULUS
                            </span>
                            <span class="text-xs text-slate-300 font-normal">Telah menyelesaikan seluruh program pembelajaran di jenjang pendidikan ini.</span>
                        <?php elseif ($decision_status === 'tidak_lulus'): ?>
                            <span class="inline-flex items-center gap-1.5 text-rose-400">
                                <i class="fa-solid fa-triangle-exclamation text-base"></i> TIDAK LULUS
                            </span>
                        <?php else: ?>
                            <span class="text-amber-400 italic font-medium">
                                <i class="fa-solid fa-clock-rotate-left mr-1"></i> Status kenaikan/kelulusan belum ditetapkan oleh Wali Kelas.
                            </span>
                        <?php endif; ?>
                    </div>
                <?php else: ?>
                    <div class="p-2.5 rounded-xl border border-white/10 bg-slate-950/60 flex items-center justify-between text-slate-300">
                        <span><i class="fa-solid fa-circle-info text-blue-400 mr-1.5"></i> <strong>Semester Ganjil:</strong> Peserta didik melanjutkan kegiatan pembelajaran ke Semester Genap.</span>
                        <span class="text-emerald-400 font-bold"><i class="fa-solid fa-check-circle"></i> Berkelanjutan</span>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Tanda Tangan Tiga Pihak -->
        <div class="grid grid-cols-3 gap-4 text-center text-xs text-slate-300 pt-5 border-t border-white/10">
            <div>
                <p class="text-slate-400">Mengetahui,</p>
                <p class="font-semibold">Orang Tua / Wali Murid,</p>
                <div class="h-16"></div>
                <p class="font-bold underline text-white">..........................................</p>
            </div>
            <div>
                <?= renderOfficialSignatureHtml($pdo, 'wali_kelas', [
                    'teacher_id' => $student['homeroom_teacher_id'] ?? 0,
                    'custom_name' => !empty($student['homeroom_teacher_name']) ? ($student['homeroom_teacher_name'] . (!empty($student['homeroom_teacher_degree']) ? ', ' . $student['homeroom_teacher_degree'] : '')) : null,
                    'custom_nip' => $student['homeroom_teacher_nip'] ?? null,
                    'custom_signature_image' => $student['homeroom_teacher_sig'] ?? null,
                    'custom_title' => 'Wali Kelas ' . ($student['class_name'] ?? ''),
                    'doc_type' => 'RAPOR',
                    'doc_num' => 'RAPOR-' . $student['id'] . '-' . ($student['nisn'] ?: '2026'),
                    'student_name' => $student['name'],
                    'show_city_date' => true,
                    'align' => 'center'
                ]) ?>
            </div>
            <div>
                <p class="text-slate-400">Mengetahui,</p>
                <?= renderOfficialSignatureHtml($pdo, 'headmaster', [
                    'doc_type' => 'RAPOR',
                    'doc_num' => 'RAPOR-' . $student['id'] . '-' . ($student['nisn'] ?: '2026'),
                    'student_name' => $student['name'],
                    'show_city_date' => false,
                    'align' => 'center'
                ]) ?>
            </div>
        </div>

    </div>

    <!-- ========================================================= -->
    <!-- LEMBAR 2: HALAMAN SAMPUL (COVER RAPOR PESERTA DIDIK)      -->
    <!-- ========================================================= -->
    <div id="paneCover" class="tab-pane hidden print-paper w-full max-w-4xl rounded-3xl border border-white/15 bg-slate-900/95 p-8 sm:p-14 shadow-2xl backdrop-blur text-center flex flex-col justify-between min-h-[920px] page-break">
        <div class="border-4 border-double border-white/20 p-8 sm:p-12 rounded-2xl h-full flex flex-col justify-between">
            
            <!-- Logo & Kementerian -->
            <div class="space-y-4">
                <?php 
                    $clean_cov_logo = !empty($school_info['school_logo']) ? resolveAssetUrl($school_info['school_logo'], '../../') : '';
                    $cov_logo_exists = !empty($clean_cov_logo) && (strpos($clean_cov_logo, 'http') === 0 || file_exists(dirname(__DIR__, 2) . '/' . resolveAssetUrl($school_info['school_logo'])));
                ?>
                <?php if ($cov_logo_exists): ?>
                    <img src="<?= htmlspecialchars($clean_cov_logo) ?>" alt="Logo" class="inline-block h-24 w-24 object-contain mb-2">
                <?php else: ?>
                    <div class="inline-flex items-center justify-center w-24 h-24 rounded-3xl bg-blue-500/10 border-2 border-blue-500/30 text-blue-400 text-5xl mb-2 shadow-inner">
                        <i class="fa-solid fa-graduation-cap"></i>
                    </div>
                <?php endif; ?>
                <h2 class="text-xs sm:text-sm font-bold uppercase tracking-widest text-slate-300 leading-relaxed">
                    KEMENTERIAN PENDIDIKAN, KEBUDAYAAN, RISET, DAN TEKNOLOGI<br>REPUBLIK INDONESIA
                </h2>
                <div class="pt-6">
                    <h1 class="text-3xl sm:text-4xl font-black uppercase tracking-tight text-white">
                        RAPOR PESERTA DIDIK
                    </h1>
                    <p class="text-xs sm:text-sm font-bold tracking-widest text-blue-400 mt-1 uppercase">
                        KURIKULUM MERDEKA
                    </p>
                </div>
            </div>

            <!-- Identitas Sekolah -->
            <div class="my-8 space-y-2.5">
                <p class="text-[11px] uppercase font-bold tracking-widest text-slate-400">Nama Satuan Pendidikan:</p>
                <div class="inline-block px-8 py-3.5 rounded-2xl border-2 border-white/20 bg-white/5 max-w-md w-full">
                    <h3 class="text-xl sm:text-2xl font-black uppercase text-white tracking-wide">
                        <?= htmlspecialchars($school_info['school_name']) ?>
                    </h3>
                    <p class="text-xs text-slate-300 mt-1">NPSN: <?= htmlspecialchars($school_info['school_npsn'] ?? '20108921') ?> &bull; NSS: <?= htmlspecialchars($school_info['school_nss'] ?? '-') ?></p>
                </div>
            </div>

            <!-- Identitas Siswa -->
            <div class="my-6 max-w-md mx-auto w-full">
                <p class="text-[11px] uppercase font-bold tracking-widest text-slate-400 mb-2">Identitas Peserta Didik:</p>
                <div class="rounded-2xl border border-white/20 bg-slate-950/70 p-5 text-left space-y-2 text-xs">
                    <div class="flex"><span class="w-32 text-slate-400 font-semibold">Nama Lengkap</span><span class="font-bold text-sm text-white">: <?= htmlspecialchars($student['name']) ?></span></div>
                    <div class="flex"><span class="w-32 text-slate-400 font-semibold">NISN</span><span class="font-mono text-slate-200">: <?= htmlspecialchars($student['nisn'] ?: '-') ?></span></div>
                    <div class="flex"><span class="w-32 text-slate-400 font-semibold">Rombongan Belajar</span><span class="font-semibold text-slate-200">: Kelas <?= htmlspecialchars($student['class_name'] ?? '-') ?></span></div>
                </div>
            </div>

            <!-- Bagian Bawah Cover -->
            <div class="space-y-1 text-xs text-slate-300 pt-6 border-t border-white/10">
                <p class="font-bold text-white"><?= htmlspecialchars($school_info['school_address']) ?></p>
                <p class="text-[11px] text-slate-400">Tahun Pelajaran: <strong class="text-white"><?= htmlspecialchars($academic_year) ?></strong></p>
            </div>
        </div>
    </div>

    <!-- ========================================================= -->
    <!-- LEMBAR 3: BIODATA PESERTA DIDIK (BUKU INDUK DAPODIK)       -->
    <!-- ========================================================= -->
    <div id="paneBiodata" class="tab-pane hidden print-paper w-full max-w-4xl rounded-3xl border border-white/15 bg-slate-900/95 p-6 sm:p-10 shadow-2xl backdrop-blur page-break">
        
        <!-- Header Biodata -->
        <div class="text-center border-b-2 border-white/20 pb-4 mb-6">
            <h2 class="text-xl sm:text-2xl font-black uppercase tracking-tight text-white">
                KETERANGAN TENTANG DIRI PESERTA DIDIK
            </h2>
            <p class="text-xs text-slate-400 mt-1 uppercase tracking-wider">Buku Laporan Hasil Belajar (Buku Induk Peserta Didik)</p>
        </div>

        <!-- Tabel Biodata Lengkap -->
        <div class="space-y-2 text-xs text-slate-200 mb-8">
            <table class="w-full text-left text-xs border-collapse">
                <tbody class="divide-y divide-white/10">
                    <tr><td class="py-2.5 w-8 font-mono text-slate-400">1.</td><td class="py-2.5 w-60 text-slate-400">Nama Lengkap Peserta Didik</td><td class="py-2.5 font-bold text-white">: <?= htmlspecialchars($student['name']) ?></td></tr>
                    <tr><td class="py-2.5 font-mono text-slate-400">2.</td><td class="py-2.5 text-slate-400">Nomor Induk Siswa Nasional (NISN)</td><td class="py-2.5 font-mono">: <?= htmlspecialchars($student['nisn'] ?: '-') ?></td></tr>
                    <tr><td class="py-2.5 font-mono text-slate-400">3.</td><td class="py-2.5 text-slate-400">Nomor Induk Siswa (NIS)</td><td class="py-2.5 font-mono">: <?= htmlspecialchars($student['nis'] ?? $student['id']) ?></td></tr>
                    <tr><td class="py-2.5 font-mono text-slate-400">4.</td><td class="py-2.5 text-slate-400">Tempat, Tanggal Lahir</td><td class="py-2.5">: <?= htmlspecialchars($student['birth_place'] ?? 'Indonesia') ?>, <?= !empty($student['birth_date']) ? date('d F Y', strtotime($student['birth_date'])) : '01 Januari 2008' ?></td></tr>
                    <tr><td class="py-2.5 font-mono text-slate-400">5.</td><td class="py-2.5 text-slate-400">Jenis Kelamin</td><td class="py-2.5">: <?= ($student['gender'] ?? 'L') === 'L' ? 'Laki-laki (L)' : 'Perempuan (P)' ?></td></tr>
                    <tr><td class="py-2.5 font-mono text-slate-400">6.</td><td class="py-2.5 text-slate-400">Agama</td><td class="py-2.5">: <?= htmlspecialchars($student['religion'] ?? 'Islam') ?></td></tr>
                    <tr><td class="py-2.5 font-mono text-slate-400">7.</td><td class="py-2.5 text-slate-400">Status dalam Keluarga</td><td class="py-2.5">: Anak Kandung</td></tr>
                    <tr><td class="py-2.5 font-mono text-slate-400">8.</td><td class="py-2.5 text-slate-400">Alamat Tempat Tinggal Peserta Didik</td><td class="py-2.5">: <?= htmlspecialchars($student['address'] ?: 'Sesuai dengan alamat domisili terdaftar di dapodik sekolah') ?></td></tr>
                    <tr><td class="py-2.5 font-mono text-slate-400">9.</td><td class="py-2.5 text-slate-400">Nomor Telepon / WhatsApp Siswa</td><td class="py-2.5 font-mono">: <?= htmlspecialchars($student['phone'] ?: '-') ?></td></tr>
                    <tr><td class="py-2.5 font-mono text-slate-400">10.</td><td class="py-2.5 text-slate-400">Sekolah Asal (Jenjang Sebelumnya)</td><td class="py-2.5">: <?= htmlspecialchars($student['previous_school'] ?? 'SMP/MTs Sederajat') ?></td></tr>
                    <tr><td class="py-2.5 font-mono text-slate-400">11.</td><td class="py-2.5 text-slate-400">Diterima di Sekolah Ini</td><td class="py-2.5">: Kelas <?= htmlspecialchars($student['class_name'] ?? 'X') ?> pada tanggal <?= date('d F Y', strtotime($student['created_at'])) ?></td></tr>
                    <tr><td class="py-2.5 font-mono text-slate-400">12.</td><td class="py-2.5 text-slate-400">Nama Orang Tua / Wali</td><td class="py-2.5">: <?= htmlspecialchars($student['parent_name'] ?? 'Orang Tua / Wali Murid') ?></td></tr>
                    <tr><td class="py-2.5 font-mono text-slate-400">13.</td><td class="py-2.5 text-slate-400">Alamat Orang Tua / Wali</td><td class="py-2.5">: <?= htmlspecialchars($student['parent_address'] ?? $student['address'] ?? '-') ?></td></tr>
                    <tr><td class="py-2.5 font-mono text-slate-400">14.</td><td class="py-2.5 text-slate-400">Nomor Telepon Orang Tua / Wali</td><td class="py-2.5 font-mono">: <?= htmlspecialchars($student['parent_phone'] ?? '-') ?></td></tr>
                </tbody>
            </table>
        </div>

        <!-- Pas Foto 3x4 & Tanda Tangan Kepala Sekolah -->
        <div class="pt-6 border-t border-white/10 flex items-center justify-between gap-6">
            <div class="w-32 h-44 rounded-xl border-2 border-dashed border-white/20 bg-slate-950/60 flex flex-col items-center justify-center text-center p-2 text-slate-400 shrink-0">
                <i class="fa-solid fa-user text-3xl mb-1 opacity-50"></i>
                <span class="text-[10px] font-mono leading-tight">Pas Foto Siswa<br>3 x 4 cm</span>
            </div>

            <div class="w-72 text-center text-xs">
                <p class="text-slate-400"><?= htmlspecialchars($school_info['school_city'] ?? 'Jakarta') ?>, <?= date('d F Y') ?></p>
                <p class="font-bold text-white mt-1">Kepala Sekolah,</p>
                <?= renderOfficialSignatureHtml($pdo, 'headmaster', [
                    'doc_type' => 'BIODATA',
                    'doc_num' => 'BIO-' . $student['id'] . '-' . ($student['nisn'] ?: '2026'),
                    'student_name' => $student['name'],
                    'show_city_date' => false,
                    'align' => 'center'
                ]) ?>
            </div>
        </div>
    </div>

    <!-- ========================================================= -->
    <!-- MODAL EDIT CATATAN & KEPUTUSAN RAPOR (GURU & WALI KELAS) -->
    <!-- ========================================================= -->
    <?php if ($is_staff_or_teacher): ?>
        <div id="modalEditNotes" class="fixed inset-0 z-50 hidden bg-black/70 backdrop-blur-sm flex items-center justify-center p-4">
            <div class="relative w-full max-w-2xl rounded-3xl border border-white/10 bg-slate-900 p-6 sm:p-8 shadow-2xl text-slate-100 max-h-[92vh] flex flex-col">
                
                <!-- Modal Header -->
                <div class="flex items-center justify-between border-b border-white/10 pb-4 mb-4 shrink-0">
                    <div>
                        <h3 class="text-lg font-bold text-white flex items-center gap-2">
                            <i class="fa-solid fa-pen-to-square text-purple-400"></i>
                            Kelola Catatan & Keputusan Rapor
                        </h3>
                        <p class="text-xs text-purple-400 font-semibold"><?= htmlspecialchars($student['name']) ?> (<?= htmlspecialchars($student['class_name'] ?? 'Kelas') ?>)</p>
                    </div>
                    <button type="button" onclick="document.getElementById('modalEditNotes').classList.add('hidden')" class="text-slate-400 hover:text-white text-lg cursor-pointer"><i class="fa-solid fa-xmark"></i></button>
                </div>

                <!-- Modal Sub-Tabs -->
                <div class="flex items-center gap-1.5 p-1 rounded-xl bg-slate-950 border border-white/10 mb-4 shrink-0 text-xs overflow-x-auto">
                    <button id="btn_tabNotes" type="button" onclick="switchModalTab('tabNotes')" class="px-3 py-1.5 rounded-lg bg-purple-600 text-white font-bold text-xs whitespace-nowrap">
                        1. Catatan & BK
                    </button>
                    <button id="btn_tabDecision" type="button" onclick="switchModalTab('tabDecision')" class="px-3 py-1.5 rounded-lg bg-white/5 text-slate-400 hover:text-white font-medium text-xs whitespace-nowrap">
                        2. Keputusan Kenaikan
                    </button>
                    <button id="btn_tabEkskul" type="button" onclick="switchModalTab('tabEkskul')" class="px-3 py-1.5 rounded-lg bg-white/5 text-slate-400 hover:text-white font-medium text-xs whitespace-nowrap">
                        3. Ekskul & PKL (SMK)
                    </button>
                    <button id="btn_tabSubjects" type="button" onclick="switchModalTab('tabSubjects')" class="px-3 py-1.5 rounded-lg bg-white/5 text-slate-400 hover:text-white font-medium text-xs whitespace-nowrap">
                        4. Kustomisasi Deskripsi Mapel
                    </button>
                </div>

                <!-- Modal Form Body -->
                <form method="POST" action="report_card.php?student_id=<?= $target_student_id ?>&class_id=<?= $selected_class_id ?>&semester=<?= urlencode($current_semester) ?>&academic_year=<?= urlencode($academic_year) ?>" class="flex-1 overflow-y-auto space-y-4 pr-1 text-xs">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="save_report_notes">
                    <input type="hidden" name="student_id" value="<?= $target_student_id ?>">
                    <input type="hidden" name="academic_year" value="<?= htmlspecialchars($academic_year) ?>">
                    <input type="hidden" name="semester" value="<?= htmlspecialchars($current_semester) ?>">

                    <!-- TAB 1: CATATAN WALI KELAS & REKOMENDASI -->
                    <div id="content_tabNotes" class="space-y-4">
                        <div>
                            <div class="flex items-center justify-between mb-1.5 flex-wrap gap-1">
                                <label class="block text-xs font-semibold text-slate-300">Catatan Perkembangan Karakter & Motivasi Wali Kelas:</label>
                                <button type="button" onclick="autoGenerateHomeroomNotes()" 
                                        class="inline-flex items-center gap-1 text-[11px] font-bold text-amber-400 hover:text-amber-300 bg-amber-500/10 hover:bg-amber-500/20 px-2.5 py-1 rounded-lg border border-amber-500/30 transition cursor-pointer"
                                        title="Generate catatan otomatis berdasarkan prestasi BK, poin kedisiplinan, dan nilai rata-rata">
                                    <i class="fa-solid fa-wand-magic-sparkles"></i> Tarik Rekomendasi Cerdas (BK & Nilai)
                                </button>
                            </div>
                            <textarea id="homeroomNotesInput" name="homeroom_notes" rows="5" required class="w-full rounded-xl border border-white/10 bg-slate-950 px-3.5 py-2.5 text-xs text-white focus:border-purple-500 focus:outline-none transition leading-relaxed"><?= htmlspecialchars($report_notes['homeroom_notes'] ?? '') ?></textarea>
                            <span class="text-[10px] text-slate-400 block mt-1">Dapat disunting bebas sebelum disimpan ke e-rapor resmi.</span>
                        </div>
                    </div>

                    <!-- TAB 2: KEPUTUSAN KENAIKAN KELAS / KELULUSAN -->
                    <div id="content_tabDecision" class="hidden space-y-4">
                        <div class="p-3 rounded-xl border border-blue-500/30 bg-blue-500/10 text-slate-300">
                            <span class="font-bold text-white block mb-0.5"><i class="fa-solid fa-circle-info text-blue-400 mr-1"></i> Penentuan Status Kenaikan / Kelulusan</span>
                            <p class="text-[11px] leading-relaxed">Pada Semester Genap / Akhir Tahun Pelajaran, hasil keputusan ini akan dicetak resmi pada kotak keputusan e-rapor.</p>
                        </div>

                        <div>
                            <label class="block text-xs font-semibold text-slate-300 mb-1.5">Status Keputusan:</label>
                            <select name="decision_status" class="w-full rounded-xl border border-white/10 bg-slate-950 px-3.5 py-2.5 text-xs text-white focus:border-purple-500 focus:outline-none transition">
                                <option value="belum_ditetapkan" <?= ($decision_status === 'belum_ditetapkan') ? 'selected' : '' ?>>Belum Ditetapkan</option>
                                <option value="naik_kelas" <?= ($decision_status === 'naik_kelas') ? 'selected' : '' ?>>Naik ke Kelas (Semester Genap)</option>
                                <option value="tinggal_kelas" <?= ($decision_status === 'tinggal_kelas') ? 'selected' : '' ?>>Tinggal di Kelas</option>
                                <option value="lulus" <?= ($decision_status === 'lulus') ? 'selected' : '' ?>>Lulus (Tingkat Akhir)</option>
                                <option value="tidak_lulus" <?= ($decision_status === 'tidak_lulus') ? 'selected' : '' ?>>Tidak Lulus</option>
                            </select>
                        </div>

                        <div>
                            <label class="block text-xs font-semibold text-slate-300 mb-1.5">Target Rombel / Kelas Selanjutnya:</label>
                            <input type="text" name="target_next_class" value="<?= htmlspecialchars($target_next_class) ?>" placeholder="Contoh: XI MIPA 1, XII RPL 2, atau Kelas 8B"
                                   class="w-full rounded-xl border border-white/10 bg-slate-950 px-3.5 py-2.5 text-xs text-white focus:border-purple-500 focus:outline-none transition">
                            <span class="text-[10px] text-slate-400 block mt-1">Isi dengan nama rombel atau tingkat kelas kenaikan siswa.</span>
                        </div>
                    </div>

                    <!-- TAB 3: EKSTRAKURIKULER & PKL / MAGANG (SMK) -->
                    <div id="content_tabEkskul" class="hidden space-y-4">
                        <div>
                            <label class="block text-xs font-semibold text-slate-300 mb-1.5">Kegiatan Ekstrakurikuler & Predikat:</label>
                            <textarea name="extracurricular" rows="3" class="w-full rounded-xl border border-white/10 bg-slate-950 px-3.5 py-2.5 text-xs text-white focus:border-purple-500 focus:outline-none transition leading-relaxed"><?= htmlspecialchars($report_notes['extracurricular'] ?? '') ?></textarea>
                            <span class="text-[10px] text-slate-400 block mt-1">Contoh: 1. Pramuka (A - Sangat Aktif) \n 2. Paskibra (B - Baik)</span>
                        </div>

                        <div class="pt-3 border-t border-white/10">
                            <h4 class="font-bold text-white mb-2 flex items-center gap-1.5">
                                <i class="fa-solid fa-briefcase text-emerald-400"></i>
                                Praktik Kerja Lapangan (PKL) / Magang Industri (Opsional SMK)
                            </h4>
                            
                            <?php 
                            $pkl_0 = $pkl_entries[0] ?? [];
                            ?>
                            <div class="space-y-3 rounded-2xl border border-white/10 bg-slate-950/60 p-3.5">
                                <div class="grid grid-cols-2 gap-3">
                                    <div>
                                        <label class="block text-[11px] text-slate-400 mb-1">Mitra DUDI / Perusahaan</label>
                                        <input type="text" name="pkl_mitra[]" value="<?= htmlspecialchars($pkl_0['mitra'] ?? '') ?>" placeholder="PT Telkom Indonesia" class="w-full rounded-lg border border-white/10 bg-slate-900 px-3 py-1.5 text-xs text-white">
                                    </div>
                                    <div>
                                        <label class="block text-[11px] text-slate-400 mb-1">Lokasi PKL</label>
                                        <input type="text" name="pkl_lokasi[]" value="<?= htmlspecialchars($pkl_0['lokasi'] ?? '') ?>" placeholder="Jakarta Pusat" class="w-full rounded-lg border border-white/10 bg-slate-900 px-3 py-1.5 text-xs text-white">
                                    </div>
                                </div>
                                <div class="grid grid-cols-2 gap-3">
                                    <div>
                                        <label class="block text-[11px] text-slate-400 mb-1">Durasi Pelaksanaan</label>
                                        <input type="text" name="pkl_durasi[]" value="<?= htmlspecialchars($pkl_0['durasi'] ?? '') ?>" placeholder="6 Bulan" class="w-full rounded-lg border border-white/10 bg-slate-900 px-3 py-1.5 text-xs text-white">
                                    </div>
                                    <div>
                                        <label class="block text-[11px] text-slate-400 mb-1">Nilai Akhir PKL</label>
                                        <input type="text" name="pkl_nilai[]" value="<?= htmlspecialchars($pkl_0['nilai'] ?? '') ?>" placeholder="88.5" class="w-full rounded-lg border border-white/10 bg-slate-900 px-3 py-1.5 text-xs text-white">
                                    </div>
                                </div>
                                <div>
                                    <label class="block text-[11px] text-slate-400 mb-1">Keterangan / Kompetensi</label>
                                    <input type="text" name="pkl_keterangan[]" value="<?= htmlspecialchars($pkl_0['keterangan'] ?? '') ?>" placeholder="Sangat kompeten dalam konfigurasi jaringan LAN & fiber optic" class="w-full rounded-lg border border-white/10 bg-slate-900 px-3 py-1.5 text-xs text-white">
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- TAB 4: KUSTOMISASI DESKRIPSI MAPEL -->
                    <div id="content_tabSubjects" class="hidden space-y-4">
                        <div class="p-3 rounded-xl border border-purple-500/30 bg-purple-500/10 text-slate-300">
                            <span class="font-bold text-white block mb-0.5"><i class="fa-solid fa-wand-magic-sparkles text-purple-400 mr-1"></i> Penyesuaian Narasi Capaian Pembelajaran</span>
                            <p class="text-[11px] leading-relaxed">Secara default, narasi dihasilkan otomatis mencakup <strong>Capaian Tertinggi</strong> dan <strong>Perlu Peningkatan</strong>. Anda dapat menuliskan narasi spesifik di bawah ini bila diperlukan.</p>
                        </div>

                        <div class="space-y-3">
                            <?php foreach ($report_grades as $rg_item): 
                                $s_name = $rg_item['subject'];
                                $s_raw = $rg_item['raw_custom'];
                            ?>
                                <div class="rounded-xl border border-white/10 bg-slate-950/60 p-3">
                                    <div class="flex items-center justify-between mb-1.5">
                                        <span class="font-bold text-white"><?= htmlspecialchars($s_name) ?></span>
                                        <span class="text-[10px] text-slate-400 font-mono">Nilai: <?= $rg_item['na'] ?> (Pred: <?= $rg_item['predikat'] ?>)</span>
                                    </div>
                                    <textarea name="subj_desc[<?= htmlspecialchars($s_name) ?>]" rows="2" placeholder="Kosongkan untuk menggunakan narasi Kurikulum Merdeka otomatis..."
                                              class="w-full rounded-lg border border-white/10 bg-slate-900 px-3 py-2 text-xs text-white focus:border-purple-500 focus:outline-none"><?= htmlspecialchars($s_raw) ?></textarea>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <!-- Modal Submit Bar -->
                    <div class="flex items-center justify-end gap-3 pt-3 border-t border-white/10 shrink-0">
                        <button type="button" onclick="document.getElementById('modalEditNotes').classList.add('hidden')" class="rounded-xl border border-white/10 bg-white/5 hover:bg-white/10 px-4 py-2 text-xs font-semibold text-slate-300 transition">
                            Batal
                        </button>
                        <button type="submit" class="inline-flex items-center gap-1.5 rounded-xl bg-purple-600 hover:bg-purple-500 px-5 py-2 text-xs font-bold text-white shadow-lg shadow-purple-600/25 transition cursor-pointer">
                            <i class="fa-solid fa-floppy-disk"></i> Simpan Catatan & Keputusan
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <script>
        function switchReportTab(tabName) {
            const panes = ['paneReport', 'paneCover', 'paneBiodata'];
            const buttons = ['btnTabReport', 'btnTabCover', 'btnTabBiodata'];
            
            panes.forEach(p => {
                const el = document.getElementById(p);
                if (el) {
                    if (p === tabName) {
                        el.classList.remove('hidden');
                        el.classList.add('active-tab');
                    } else {
                        el.classList.add('hidden');
                        el.classList.remove('active-tab');
                    }
                }
            });

            buttons.forEach(b => {
                const btn = document.getElementById(b);
                if (btn) {
                    const match = b.toLowerCase().replace('btntab', '') === tabName.toLowerCase().replace('pane', '');
                    if (match) {
                        btn.className = "inline-flex items-center gap-1.5 rounded-xl border border-blue-500/40 bg-blue-500/20 px-3.5 py-1.5 text-xs font-bold text-blue-300 transition";
                    } else {
                        btn.className = "inline-flex items-center gap-1.5 rounded-xl border border-white/10 bg-white/5 hover:bg-white/10 px-3.5 py-1.5 text-xs font-semibold text-slate-300 transition";
                    }
                }
            });
        }

        function switchModalTab(tabId) {
            const tabs = ['tabNotes', 'tabDecision', 'tabEkskul', 'tabSubjects'];
            tabs.forEach(t => {
                const content = document.getElementById('content_' + t);
                const btn = document.getElementById('btn_' + t);
                if (content && btn) {
                    if (t === tabId) {
                        content.classList.remove('hidden');
                        btn.className = "px-3 py-1.5 rounded-lg bg-purple-600 text-white font-bold text-xs whitespace-nowrap";
                    } else {
                        content.classList.add('hidden');
                        btn.className = "px-3 py-1.5 rounded-lg bg-white/5 text-slate-400 hover:text-white font-medium text-xs whitespace-nowrap";
                    }
                }
            });
        }

        function printActiveSheet() {
            document.body.classList.remove('print-bundle-all');
            document.body.classList.add('print-active-only');
            window.print();
        }

        function printBundleComplete() {
            document.body.classList.remove('print-active-only');
            document.body.classList.add('print-bundle-all');
            window.print();
        }

        function autoGenerateHomeroomNotes() {
            const stuName = <?= json_encode($student['name'] ?? 'Ananda') ?>;
            const avgScore = <?= (float)$average_score ?>;
            const attRate = <?= (float)$att_pct ?>;
            const bkPoints = <?= (int)$bk_violation_points ?>;
            const achCount = <?= count($bk_achievements) ?>;

            let text = `Ananda ${stuName} `;
            if (avgScore >= 85) {
                text += `menunjukkan capaian akademik yang sangat unggul dengan rata-rata nilai ${avgScore}. `;
            } else if (avgScore >= 75) {
                text += `menunjukkan konsistensi belajar yang baik dan tuntas mencapai seluruh target kompetensi (rata-rata ${avgScore}). `;
            } else {
                text += `telah berusaha dengan baik di semester ini (rata-rata ${avgScore}) namun memerlukan bimbingan lebih pada pemahaman konsep mandiri. `;
            }

            if (achCount > 0) {
                text += `Aktif berprestasi dan mengharumkan nama sekolah dalam kegiatan ekstrakurikuler/kejuaraan. `;
            }

            if (bkPoints === 0 && attRate >= 95) {
                text += `Memiliki rekam kedisiplinan sempurna (tanpa catatan pelanggaran) serta tingkat kehadiran prima (${attRate}%). `;
            } else if (bkPoints > 0) {
                text += `Diharapkan lebih memperhatikan tata tertib sekolah dan meningkatkan kedisiplinan waktu. `;
            }

            text += `Pertahankan semangat belajar, budi pekerti yang luhur, dan terus kembangkan potensi terbaik di semester berikutnya.`;

            const noteInput = document.getElementById('homeroomNotesInput');
            if (noteInput) {
                noteInput.value = text;
                noteInput.focus();
            }
        }
        </script>
    <?php endif; ?>

</body>
</html>
