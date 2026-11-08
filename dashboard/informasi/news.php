<?php
session_start();
require_once __DIR__ . "/../../config/database.php";

requireLogin();

$user_id = (int) $_SESSION['user_id'];
$user_role = $_SESSION['user_role'] ?? 'siswa';
$can_manage_all = in_array($user_role, ['administrator', 'staf'], true);
$can_write = in_array($user_role, ['administrator', 'staf', 'guru'], true);
$is_guru = ($user_role === 'guru');

// Batasi akses hanya untuk role Guru, Staf, dan Administrator
if (!$can_write) {
    header("Location: ../index.php?error=unauthorized");
    exit;
}

$message = "";
$message_type = "";

// Pastikan direktori uploads untuk berita tersedia
$upload_dir = __DIR__ . "/../../uploads/news/";
if (!is_dir($upload_dir)) {
    @mkdir($upload_dir, 0755, true);
}

// -------------------------------------------------------------
// 1. ACTION: TAMBAH BERITA BARU
// -------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'create_news') {
    if (!validateCsrfToken()) {
        $message = "Token keamanan tidak valid. Silakan muat ulang halaman.";
        $message_type = "error";
    } else {
        $title = trim($_POST['title'] ?? '');
        $excerpt = trim($_POST['excerpt'] ?? '');
        $content = trim($_POST['content'] ?? '');
        $category = trim($_POST['category'] ?? 'kegiatan');
        $desired_status = trim($_POST['status'] ?? 'draft');
        $is_featured = (!empty($_POST['is_featured']) && $can_manage_all) ? 1 : 0;
        $book_id = !empty($_POST['book_id']) ? (int)$_POST['book_id'] : null;
        $event_id = !empty($_POST['event_id']) ? (int)$_POST['event_id'] : null;
        $counseling_id = !empty($_POST['counseling_id']) ? (int)$_POST['counseling_id'] : null;

        // Validasi Kategori
        $valid_categories = array_keys(NEWS_CATEGORIES);
        if (!in_array($category, $valid_categories, true)) {
            $category = 'kegiatan';
        }

        // Aturan Status berdasarkan Role:
        // Guru: Hanya boleh 'draft' atau 'pending' (Pengajuan Persetujuan)
        // Staf & Admin: Bebas memilih 'published' atau 'draft'
        if ($is_guru) {
            $status = ($desired_status === 'draft') ? 'draft' : 'pending';
            $approved_by = null;
            $approved_at = null;
            $published_at = null;
        } else {
            $status = ($desired_status === 'draft') ? 'draft' : 'published';
            $approved_by = ($status === 'published') ? $user_id : null;
            $approved_at = ($status === 'published') ? date('Y-m-d H:i:s') : null;
            $published_at = ($status === 'published') ? date('Y-m-d H:i:s') : null;
        }

        if ($title === '' || strip_tags($content) === '') {
            $message = "Judul berita dan isi konten wajib diisi.";
            $message_type = "error";
        } else {
            // Upload Foto Sampul / Cover dengan validasi keamanan terpusat & EXIF strip
            $thumbnail_url = null;
            if (isset($_FILES['thumbnail']) && $_FILES['thumbnail']['error'] === UPLOAD_ERR_OK) {
                $uploadRes = saveSecureUpload(
                    $_FILES['thumbnail'],
                    'news',
                    ['jpg', 'jpeg', 'png', 'webp', 'gif'],
                    [],
                    5 * 1024 * 1024,
                    'news'
                );
                if ($uploadRes['success']) {
                    $thumbnail_url = $uploadRes['filename'];
                } else {
                    $message = $uploadRes['error'];
                    $message_type = "error";
                }
            }

            if ($message === '') {
                if (empty($excerpt)) {
                    $plain = strip_tags($content);
                    $excerpt = mb_substr($plain, 0, 160) . (mb_strlen($plain) > 160 ? '...' : '');
                }

                $slug = generateNewsSlug($title, $pdo);

                $stmt = $pdo->prepare("
                    INSERT INTO news 
                    (title, slug, excerpt, content, category, thumbnail, book_id, event_id, counseling_id, author_id, status, approved_by, approved_at, is_featured, published_at)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");
                $stmt->execute([
                    $title,
                    $slug,
                    $excerpt,
                    $content,
                    $category,
                    $thumbnail_url,
                    $book_id,
                    $event_id,
                    $counseling_id,
                    $user_id,
                    $status,
                    $approved_by,
                    $approved_at,
                    $is_featured,
                    $published_at
                ]);

                $new_id = (int)$pdo->lastInsertId();
                logActivity($pdo, 'create_news', "Membuat berita ID: $new_id - $title (Status: $status)");

                if ($is_guru && $status === 'pending') {
                    // Notifikasi internal pesan untuk semua administrator
                    try {
                        $admins = $pdo->query("SELECT id FROM users WHERE role = 'administrator'")->fetchAll(PDO::FETCH_COLUMN);
                        $notif_txt = "Guru " . ($_SESSION['user_name'] ?? 'Pendidik') . " baru saja mengajukan artikel berita baru: \"" . $title . "\". Silakan tinjau dan setujui di menu Berita Sekolah.";
                        $stmt_msg = $pdo->prepare("INSERT INTO messages (sender_id, receiver_id, message, is_read) VALUES (?, ?, ?, 0)");
                        foreach ($admins as $aid) {
                            $stmt_msg->execute([$user_id, (int)$aid, $notif_txt]);
                        }
                    } catch (Exception $e_notif) {}
                    $message = "Berita berhasil disimpan dan diajukan ke Staf/Administrator untuk persetujuan publikasi.";
                } elseif ($is_guru && $status === 'draft') {
                    $message = "Draf berita berhasil disimpan.";
                } else {
                    $message = "Berita berhasil disimpan dan langsung diterbitkan ke portal publik!";
                }
                $message_type = "success";
            }
        }
    }
}

// -------------------------------------------------------------
// 2. ACTION: EDIT BERITA
// -------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'edit_news') {
    if (!validateCsrfToken()) {
        $message = "Token keamanan tidak valid. Silakan muat ulang halaman.";
        $message_type = "error";
    } else {
        $news_id = (int)($_POST['news_id'] ?? 0);

        // Ambil data berita yang akan diedit
        $stmt_check = $pdo->prepare("SELECT * FROM news WHERE id = ?");
        $stmt_check->execute([$news_id]);
        $existing = $stmt_check->fetch();

        if (!$existing) {
            $message = "Berita tidak ditemukan.";
            $message_type = "error";
        } elseif ($is_guru && (int)$existing['author_id'] !== $user_id) {
            $message = "Anda hanya memiliki wewenang untuk mengedit berita yang Anda tulis sendiri.";
            $message_type = "error";
        } else {
            $title = trim($_POST['title'] ?? '');
            $excerpt = trim($_POST['excerpt'] ?? '');
            $content = trim($_POST['content'] ?? '');
            $category = trim($_POST['category'] ?? 'kegiatan');
            $desired_status = trim($_POST['status'] ?? 'draft');
            $is_featured = (!empty($_POST['is_featured']) && $can_manage_all) ? 1 : ($can_manage_all ? 0 : (int)$existing['is_featured']);

            $valid_categories = array_keys(NEWS_CATEGORIES);
            if (!in_array($category, $valid_categories, true)) {
                $category = 'kegiatan';
            }

            $book_id = !empty($_POST['book_id']) ? (int)$_POST['book_id'] : null;
            $event_id = !empty($_POST['event_id']) ? (int)$_POST['event_id'] : null;
            $counseling_id = !empty($_POST['counseling_id']) ? (int)$_POST['counseling_id'] : null;

            if ($title === '' || strip_tags($content) === '') {
                $message = "Judul berita dan isi konten wajib diisi.";
                $message_type = "error";
            } else {
                // Aturan status saat edit:
                if ($is_guru) {
                    // Jika sebelumnya rejected atau draft, pengajuan ulang mengubah status jadi 'pending'
                    $status = ($desired_status === 'draft') ? 'draft' : 'pending';
                    $rejection_note_sql = ($status === 'pending') ? ", rejection_note = NULL" : "";
                    $approval_sql = "";
                } else {
                    // Admin & Staf bebas memilih
                    $status = in_array($desired_status, ['draft', 'pending', 'published', 'rejected'], true) ? $desired_status : 'published';
                    $rejection_note_sql = "";
                    if ($status === 'published' && $existing['status'] !== 'published') {
                        $approval_sql = ", approved_by = $user_id, approved_at = NOW(), published_at = IFNULL(published_at, NOW()), rejection_note = NULL";
                    } elseif ($status === 'draft') {
                        $approval_sql = "";
                    } else {
                        $approval_sql = "";
                    }
                }

                // Handle Pergantian / Upload Thumbnail
                $thumbnail_sql = "";
                $params_upd = [];

                if (isset($_FILES['thumbnail']) && $_FILES['thumbnail']['error'] === UPLOAD_ERR_OK) {
                    $uploadRes = saveSecureUpload(
                        $_FILES['thumbnail'],
                        'news',
                        ['jpg', 'jpeg', 'png', 'webp', 'gif'],
                        [],
                        5 * 1024 * 1024,
                        'news'
                    );
                    if ($uploadRes['success']) {
                        // Hapus file lama jika ada
                        if (!empty($existing['thumbnail'])) {
                            $oldFile = basename($existing['thumbnail']);
                            if (file_exists($upload_dir . $oldFile)) {
                                @unlink($upload_dir . $oldFile);
                            }
                        }
                        $thumbnail_sql = ", thumbnail = ?";
                        $params_upd[] = $uploadRes['filename'];
                    } else {
                        $message = $uploadRes['error'];
                        $message_type = "error";
                    }
                }

                // Handle Hapus Foto Cover
                if (isset($_POST['remove_thumbnail']) && $_POST['remove_thumbnail'] === '1') {
                    if (!empty($existing['thumbnail']) && file_exists($upload_dir . $existing['thumbnail'])) {
                        @unlink($upload_dir . $existing['thumbnail']);
                    }
                    $thumbnail_sql = ", thumbnail = NULL";
                }

                if (empty($excerpt)) {
                    $plain = strip_tags($content);
                    $excerpt = mb_substr($plain, 0, 160) . (mb_strlen($plain) > 160 ? '...' : '');
                }

                $slug = generateNewsSlug($title, $pdo, $news_id);

                $sql = "
                    UPDATE news 
                    SET title = ?, slug = ?, excerpt = ?, content = ?, category = ?, status = ?, is_featured = ?, book_id = ?, event_id = ?, counseling_id = ?
                        $thumbnail_sql
                        $rejection_note_sql
                        $approval_sql
                    WHERE id = ?
                ";
                $base_params = [$title, $slug, $excerpt, $content, $category, $status, $is_featured, $book_id, $event_id, $counseling_id];
                $all_params = array_merge($base_params, $params_upd, [$news_id]);

                $pdo->prepare($sql)->execute($all_params);
                logActivity($pdo, 'edit_news', "Memperbarui berita ID: $news_id - $title (Status: $status)");

                if ($is_guru && $status === 'pending') {
                    // Notifikasi internal pesan untuk semua administrator
                    try {
                        $admins = $pdo->query("SELECT id FROM users WHERE role = 'administrator'")->fetchAll(PDO::FETCH_COLUMN);
                        $notif_txt = "Guru " . ($_SESSION['user_name'] ?? 'Pendidik') . " baru saja memperbarui & mengajukan artikel berita: \"" . $title . "\". Silakan tinjau dan setujui di menu Berita Sekolah.";
                        $stmt_msg = $pdo->prepare("INSERT INTO messages (sender_id, receiver_id, message, is_read) VALUES (?, ?, ?, 0)");
                        foreach ($admins as $aid) {
                            $stmt_msg->execute([$user_id, (int)$aid, $notif_txt]);
                        }
                    } catch (Exception $e_notif) {}
                    $message = "Perubahan berita berhasil disimpan dan diajukan kembali ke Staf/Administrator.";
                } else {
                    $message = "Berita berhasil diperbarui.";
                }
                $message_type = "success";
            }
        }
    }
}

// -------------------------------------------------------------
// 3. ACTION: SETUJUI BERITA (Khusus Staf & Admin)
// -------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'approve_news') {
    if (!$can_manage_all) {
        $message = "Hanya Staf dan Administrator yang berhak menyetujui berita.";
        $message_type = "error";
    } elseif (!validateCsrfToken()) {
        $message = "Token keamanan tidak valid.";
        $message_type = "error";
    } else {
        $news_id = (int)($_POST['news_id'] ?? 0);
        $stmt_check = $pdo->prepare("SELECT author_id, title FROM news WHERE id = ?");
        $stmt_check->execute([$news_id]);
        $target_n = $stmt_check->fetch();

        $stmt_app = $pdo->prepare("
            UPDATE news 
            SET status = 'published', approved_by = ?, approved_at = NOW(), published_at = IFNULL(published_at, NOW()), rejection_note = NULL 
            WHERE id = ?
        ");
        $stmt_app->execute([$user_id, $news_id]);

        if ($target_n && (int)$target_n['author_id'] !== $user_id) {
            try {
                $congrats = "Kabar baik! Artikel berita Anda berjudul \"" . $target_n['title'] . "\" telah disetujui dan resmi diterbitkan di portal informasi publik sekolah.";
                $pdo->prepare("INSERT INTO messages (sender_id, receiver_id, message, is_read) VALUES (?, ?, ?, 0)")
                    ->execute([$user_id, (int)$target_n['author_id'], $congrats]);
            } catch (Exception $e_notif) {}
        }

        logActivity($pdo, 'approve_news', "Menyetujui berita ID: $news_id untuk dipublikasikan.");
        $message = "Berita berhasil disetujui dan telah tayang resmi di portal informasi publik!";
        $message_type = "success";
    }
}

// -------------------------------------------------------------
// 4. ACTION: TOLAK / MINTA REVISI BERITA (Khusus Staf & Admin)
// -------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'reject_news') {
    if (!$can_manage_all) {
        $message = "Hanya Staf dan Administrator yang berhak menolak atau meminta revisi berita.";
        $message_type = "error";
    } elseif (!validateCsrfToken()) {
        $message = "Token keamanan tidak valid.";
        $message_type = "error";
    } else {
        $news_id = (int)($_POST['news_id'] ?? 0);
        $rejection_note = trim($_POST['rejection_note'] ?? '');

        if ($rejection_note === '') {
            $rejection_note = "Mohon lakukan perbaikan konten sesuai dengan kaidah publikasi sekolah.";
        }

        $stmt_check = $pdo->prepare("SELECT author_id, title FROM news WHERE id = ?");
        $stmt_check->execute([$news_id]);
        $target_n = $stmt_check->fetch();

        $stmt_rej = $pdo->prepare("
            UPDATE news 
            SET status = 'rejected', rejection_note = ?, approved_by = ?, approved_at = NOW() 
            WHERE id = ?
        ");
        $stmt_rej->execute([$rejection_note, $user_id, $news_id]);

        if ($target_n && (int)$target_n['author_id'] !== $user_id) {
            try {
                $rev_msg = "Artikel berita Anda berjudul \"" . $target_n['title'] . "\" memerlukan revisi:\n\n\"" . $rejection_note . "\"\n\nSilakan periksa dan perbarui melalui menu Berita Sekolah.";
                $pdo->prepare("INSERT INTO messages (sender_id, receiver_id, message, is_read) VALUES (?, ?, ?, 0)")
                    ->execute([$user_id, (int)$target_n['author_id'], $rev_msg]);
            } catch (Exception $e_notif) {}
        }

        logActivity($pdo, 'reject_news', "Menolak berita ID: $news_id dengan catatan revisi.");
        $message = "Berita ditolak dan statusnya diubah menjadi Ditolak / Perlu Revisi. Catatan revisi telah dikirim ke pesan internal guru penulis.";
        $message_type = "warning";
    }
}

// -------------------------------------------------------------
// 5. ACTION: SIARKAN BERITA VIA WHATSAPP GATEWAY (Staf & Admin)
// -------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'broadcast_wa') {
    if (!$can_manage_all) {
        $message = "Hanya Staf dan Administrator yang berhak menyiarkan berita via WhatsApp Gateway.";
        $message_type = "error";
    } elseif (!validateCsrfToken()) {
        $message = "Token keamanan tidak valid.";
        $message_type = "error";
    } else {
        $news_id = (int)($_POST['news_id'] ?? 0);
        $target_audience = trim($_POST['target_audience'] ?? 'guru');
        $custom_phone = trim($_POST['custom_phone'] ?? '');

        $stmt_b = $pdo->prepare("SELECT * FROM news WHERE id = ? AND status = 'published'");
        $stmt_b->execute([$news_id]);
        $news_b = $stmt_b->fetch();

        if (!$news_b) {
            $message = "Berita tidak ditemukan atau belum berstatus Diterbitkan (Published).";
            $message_type = "error";
        } else {
            $proto = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
            $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
            $dir_path = dirname(dirname($_SERVER['SCRIPT_NAME']));
            $news_url = "{$proto}://{$host}" . str_replace('\\', '/', rtrim($dir_path, '/\\')) . "/../berita.php?id=" . $news_b['id'];

            $broadcast_msg = "📢 *BERITA SEKOLAH RESMI*\n" .
                             "*" . $news_b['title'] . "*\n\n" .
                             ($news_b['excerpt'] ? $news_b['excerpt'] . "\n\n" : "") .
                             "📖 Baca selengkapnya melalui portal publik:\n" .
                             $news_url . "\n\n" .
                             "_Pesan otomatis dari Sistem Informasi Sekolah_";

            $sent_count = 0;

            if ($target_audience === 'custom' && !empty($custom_phone)) {
                sendWhatsAppNotification($pdo, $custom_phone, 'Penerima Siaran', $broadcast_msg, 'pengumuman');
                $sent_count = 1;
            } else {
                $rec_roles = ($target_audience === 'wali_murid') ? ['orang_tua'] : ($target_audience === 'semua' ? ['guru', 'staf', 'orang_tua'] : ['guru', 'staf']);
                $in_placeholders = implode(',', array_fill(0, count($rec_roles), '?'));
                $stmt_users = $pdo->prepare("SELECT name, phone FROM users WHERE role IN ($in_placeholders) AND phone IS NOT NULL AND phone != '' LIMIT 50");
                $stmt_users->execute($rec_roles);
                $recipients = $stmt_users->fetchAll();

                foreach ($recipients as $rec) {
                    sendWhatsAppNotification($pdo, $rec['phone'], $rec['name'], $broadcast_msg, 'pengumuman');
                    $sent_count++;
                }
            }

            logActivity($pdo, 'broadcast_news_wa', "Menyiarkan berita ID: $news_id ke $sent_count penerima ($target_audience)");
            $message = "Siaran WhatsApp berhasil dikirim ke $sent_count kontak penerima!";
            $message_type = "success";
        }
    }
}

// -------------------------------------------------------------
// 5. ACTION: TOGGLE FEATURED / BERITA UTAMA (Khusus Staf & Admin)
// -------------------------------------------------------------
if (isset($_GET['toggle_featured']) && $can_manage_all) {
    if (!isset($_GET['csrf_token']) || !hash_equals($_SESSION['csrf_token'] ?? '', (string)$_GET['csrf_token'])) {
        header("Location: news.php?error=invalid_csrf");
        exit;
    }
    $feat_id = (int)$_GET['toggle_featured'];
    $pdo->prepare("UPDATE news SET is_featured = NOT is_featured WHERE id = ?")->execute([$feat_id]);
    logActivity($pdo, 'toggle_featured_news', "Mengubah status unggulan berita ID: $feat_id");
    header("Location: news.php?msg=featured_toggled");
    exit;
}

// -------------------------------------------------------------
// 6. ACTION: HAPUS BERITA
// -------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_news') {
    if (!validateCsrfToken()) {
        $message = "Token keamanan tidak valid.";
        $message_type = "error";
    } else {
        $news_id = (int)($_POST['news_id'] ?? 0);
        $stmt_chk = $pdo->prepare("SELECT * FROM news WHERE id = ?");
        $stmt_chk->execute([$news_id]);
        $target_news = $stmt_chk->fetch();

        if ($target_news) {
            if ($is_guru && (int)$target_news['author_id'] !== $user_id) {
                $message = "Anda tidak memiliki hak untuk menghapus berita ini.";
                $message_type = "error";
            } else {
                if (!empty($target_news['thumbnail']) && file_exists($upload_dir . $target_news['thumbnail'])) {
                    @unlink($upload_dir . $target_news['thumbnail']);
                }
                $pdo->prepare("DELETE FROM news WHERE id = ?")->execute([$news_id]);
                logActivity($pdo, 'delete_news', "Menghapus berita ID: $news_id - " . $target_news['title']);
                $message = "Berita berhasil dihapus.";
                $message_type = "success";
            }
        }
    }
}

// Handle GET flash message
if (isset($_GET['msg']) && $_GET['msg'] === 'featured_toggled') {
    $message = "Status Berita Utama (Featured) berhasil diperbarui.";
    $message_type = "success";
}

// -------------------------------------------------------------
// 7. STATISTIK SISTEM BERITA
// -------------------------------------------------------------
$stats = [
    'total_published' => 0,
    'total_pending'   => 0,
    'total_draft'     => 0,
    'total_rejected'  => 0,
    'my_news'         => 0,
    'total_views'     => 0,
];

try {
    $stats['total_published'] = (int)$pdo->query("SELECT COUNT(*) FROM news WHERE status = 'published'")->fetchColumn();
    $stats['total_pending']   = (int)$pdo->query("SELECT COUNT(*) FROM news WHERE status = 'pending'")->fetchColumn();
    $stats['total_draft']     = (int)$pdo->query("SELECT COUNT(*) FROM news WHERE status = 'draft'")->fetchColumn();
    $stats['total_rejected']  = (int)$pdo->query("SELECT COUNT(*) FROM news WHERE status = 'rejected'")->fetchColumn();
    $stats['my_news']         = (int)$pdo->query("SELECT COUNT(*) FROM news WHERE author_id = $user_id")->fetchColumn();
    $stats['total_views']     = (int)$pdo->query("SELECT SUM(views) FROM news WHERE status = 'published'")->fetchColumn() ?: 0;
} catch (Exception $e) {}

// -------------------------------------------------------------
// 8. QUERY DAFTAR BERITA DENGAN FILTER & SEARCH
// -------------------------------------------------------------
$filter_status = trim($_GET['status'] ?? '');
$filter_category = trim($_GET['category'] ?? '');
$filter_scope = trim($_GET['scope'] ?? ''); // 'my' untuk berita saya
$search_q = trim($_GET['q'] ?? '');

$where_clauses = [];
$params_q = [];

// Jika guru memilih scope 'my' atau default jika ingin filter artikel sendiri
if ($filter_scope === 'my' || ($is_guru && !isset($_GET['scope']) && $filter_status === 'draft')) {
    $where_clauses[] = "n.author_id = ?";
    $params_q[] = $user_id;
}

if ($filter_status !== '' && in_array($filter_status, ['draft', 'pending', 'published', 'rejected'], true)) {
    $where_clauses[] = "n.status = ?";
    $params_q[] = $filter_status;
}

if ($filter_category !== '' && array_key_exists($filter_category, NEWS_CATEGORIES)) {
    $where_clauses[] = "n.category = ?";
    $params_q[] = $filter_category;
}

if ($search_q !== '') {
    $where_clauses[] = "(n.title LIKE ? OR n.excerpt LIKE ? OR u.name LIKE ?)";
    $params_q[] = "%$search_q%";
    $params_q[] = "%$search_q%";
    $params_q[] = "%$search_q%";
}

$where_sql = !empty($where_clauses) ? 'WHERE ' . implode(' AND ', $where_clauses) : '';

$available_books = [];
$available_events = [];
$available_counseling = [];

try {
    $available_books = $pdo->query("SELECT id, code, title, author FROM library_books ORDER BY title ASC LIMIT 100")->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}

try {
    $available_events = $pdo->query("SELECT id, title, event_date, category FROM calendar_events ORDER BY event_date DESC LIMIT 50")->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}

try {
    $available_counseling = $pdo->query("
        SELECT cr.id, cr.title, cr.date, u.name as student_name 
        FROM counseling_records cr 
        JOIN users u ON cr.student_id = u.id 
        WHERE cr.type = 'prestasi' 
        ORDER BY cr.date DESC LIMIT 50
    ")->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}

$news_list = [];
try {
    $stmt_list = $pdo->prepare("
        SELECT n.*, 
               u.name as author_name, u.role as author_role,
               app.name as approver_name,
               lb.title as book_title, lb.author as book_author,
               ce.title as event_title, ce.event_date as event_date,
               cr.title as counseling_title, stu.name as counseling_student_name
        FROM news n
        JOIN users u ON n.author_id = u.id
        LEFT JOIN users app ON n.approved_by = app.id
        LEFT JOIN library_books lb ON n.book_id = lb.id
        LEFT JOIN calendar_events ce ON n.event_id = ce.id
        LEFT JOIN counseling_records cr ON n.counseling_id = cr.id
        LEFT JOIN users stu ON cr.student_id = stu.id
        $where_sql
        ORDER BY 
            CASE WHEN n.status = 'pending' THEN 0 ELSE 1 END,
            n.is_featured DESC, 
            n.created_at DESC
    ");
    $stmt_list->execute($params_q);
    $news_list = $stmt_list->fetchAll();
} catch (Exception $e) {
    $news_list = [];
}

$page_title = "Manajemen Berita & Artikel Sekolah";
require_once __DIR__ . "/../includes/header.php";
?>

<!-- Quill.js Rich Text Editor Assets -->
<link href="https://cdn.jsdelivr.net/npm/quill@2.0.2/dist/quill.snow.css" rel="stylesheet">
<script src="https://cdn.jsdelivr.net/npm/quill@2.0.2/dist/quill.js"></script>

<style>
/* Custom Quill Dark Mode Theme */
.ql-toolbar.ql-snow {
    border-color: rgba(255, 255, 255, 0.12) !important;
    background: rgba(15, 23, 42, 0.95) !important;
    border-top-left-radius: 0.75rem;
    border-top-right-radius: 0.75rem;
}
.ql-container.ql-snow {
    border-color: rgba(255, 255, 255, 0.12) !important;
    background: #020617 !important;
    border-bottom-left-radius: 0.75rem;
    border-bottom-right-radius: 0.75rem;
    color: #f1f5f9 !important;
    font-size: 0.875rem;
    min-height: 220px;
}
.ql-snow .ql-stroke { stroke: #94a3b8 !important; }
.ql-snow .ql-fill { fill: #94a3b8 !important; }
.ql-snow .ql-picker { color: #94a3b8 !important; }
.ql-snow .ql-picker-options {
    background-color: #0f172a !important;
    border-color: rgba(255, 255, 255, 0.15) !important;
    color: #e2e8f0 !important;
}

/* Modal animation */
.modal-transition {
    transition: opacity 0.25s ease-out, transform 0.25s ease-out;
}
</style>

<div class="space-y-6">

    <!-- ============================================================== -->
    <!-- TOP NAVIGATION TABS: PENGUMUMAN VS BERITA SEKOLAH -->
    <!-- ============================================================== -->
    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4 border-b border-white/10 pb-4">
        <div>
            <div class="flex items-center gap-2 mb-1">
                <span class="inline-flex items-center gap-1.5 px-2.5 py-0.5 rounded-full text-[11px] font-semibold bg-sky-500/10 text-sky-400 border border-sky-500/20">
                    <i class="fa-solid fa-newspaper text-xs"></i> Sistem Informasi & Komunikasi
                </span>
                <?php if ($can_manage_all && $stats['total_pending'] > 0): ?>
                    <span class="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-[11px] font-bold bg-amber-500/20 text-amber-300 border border-amber-500/30 animate-pulse">
                        <i class="fa-solid fa-bell"></i> <?= $stats['total_pending'] ?> Butuh Persetujuan
                    </span>
                <?php endif; ?>
            </div>
            <h1 class="text-2xl sm:text-3xl font-extrabold text-white tracking-tight flex items-center gap-3">
                <span>Berita & Publikasi Sekolah</span>
            </h1>
            <p class="text-xs sm:text-sm text-slate-400 mt-0.5">
                Portal liputan kegiatan, prestasi, karya literasi, dan artikel edukasi terintegrasi.
            </p>
        </div>

        <!-- Tab Navigasi Pengumuman / Berita & Button Tulis -->
        <div class="flex flex-wrap items-center gap-2.5">
            <div class="flex items-center bg-slate-900 border border-white/10 p-1 rounded-xl shadow-inner">
                <a href="announcements.php" class="px-3.5 py-1.5 text-xs font-semibold rounded-lg text-slate-400 hover:text-white transition flex items-center gap-1.5">
                    <i class="fa-solid fa-bullhorn text-slate-500"></i>
                    <span>Pengumuman</span>
                </a>
                <a href="news.php" class="px-3.5 py-1.5 text-xs font-semibold rounded-lg bg-blue-600 text-white shadow-sm flex items-center gap-1.5">
                    <i class="fa-solid fa-newspaper"></i>
                    <span>Berita Sekolah</span>
                    <?php if ($stats['total_pending'] > 0): ?>
                        <span class="ml-1 px-1.5 py-0.2 rounded-full text-[10px] bg-amber-400 text-slate-950 font-bold">
                            <?= $stats['total_pending'] ?>
                        </span>
                    <?php endif; ?>
                </a>
            </div>

            <!-- Tombol Tambah Berita -->
            <button type="button" onclick="openCreateNewsModal()" 
                    class="inline-flex items-center gap-2 px-4 py-2 rounded-xl text-xs sm:text-sm font-semibold text-white bg-gradient-to-r from-blue-600 via-indigo-600 to-sky-600 hover:opacity-95 shadow-lg shadow-blue-500/25 transition active:scale-95">
                <i class="fa-solid fa-feather-pointed"></i>
                <span><?= $is_guru ? 'Tulis & Ajukan Berita' : 'Tulis Berita Baru' ?></span>
            </button>
        </div>
    </div>

    <!-- Alert Flash Message -->
    <?php if (!empty($message)): ?>
        <div class="p-4 rounded-2xl border flex items-start gap-3 transition-all duration-300 <?= $message_type === 'success' ? 'bg-emerald-500/10 border-emerald-500/20 text-emerald-300' : ($message_type === 'warning' ? 'bg-amber-500/10 border-amber-500/20 text-amber-300' : 'bg-rose-500/10 border-rose-500/20 text-rose-300') ?>">
            <i class="fa-solid <?= $message_type === 'success' ? 'fa-circle-check text-emerald-400' : ($message_type === 'warning' ? 'fa-triangle-exclamation text-amber-400' : 'fa-circle-exclamation text-rose-400') ?> text-lg mt-0.5"></i>
            <div class="flex-1 text-xs sm:text-sm leading-relaxed">
                <?= htmlspecialchars($message) ?>
            </div>
            <button type="button" onclick="this.parentElement.remove()" class="text-slate-400 hover:text-white transition">
                <i class="fa-solid fa-xmark"></i>
            </button>
        </div>
    <?php endif; ?>

    <!-- Information banner for Guru -->
    <?php if ($is_guru): ?>
        <div class="p-4 rounded-2xl bg-gradient-to-r from-blue-900/30 via-slate-900/60 to-indigo-900/30 border border-blue-500/20 text-slate-300 flex items-start gap-3.5 shadow-sm">
            <div class="h-9 w-9 rounded-xl bg-blue-500/20 border border-blue-500/30 flex items-center justify-center text-blue-400 shrink-0">
                <i class="fa-solid fa-circle-info text-base"></i>
            </div>
            <div class="text-xs sm:text-sm space-y-1">
                <p class="font-bold text-white flex items-center gap-1.5">
                    Alur Moderasi Artikel Berita Pendidik
                </p>
                <p class="text-slate-300 leading-relaxed">
                    Sebagai <strong>Guru</strong>, tulisan berita yang Anda ajukan akan melalui tahap peninjauan dan persetujuan oleh tim <strong>Staf Tata Usaha</strong> atau <strong>Administrator</strong> sebelum resmi tayang di portal informasi publik. Anda dapat menyimpan draf sewaktu-waktu atau langsung mengajukannya untuk ditinjau.
                </p>
            </div>
        </div>
    <?php endif; ?>

    <!-- ============================================================== -->
    <!-- STATS CARDS -->
    <!-- ============================================================== -->
    <div class="grid grid-cols-2 lg:grid-cols-4 gap-3 sm:gap-4">
        <!-- 1. Diterbitkan -->
        <div class="bg-slate-900/80 backdrop-blur-md rounded-2xl border border-white/10 p-4 sm:p-5 flex items-center justify-between">
            <div>
                <p class="text-[11px] sm:text-xs font-semibold uppercase tracking-wider text-slate-400">Diterbitkan Resmi</p>
                <p class="text-xl sm:text-2xl font-extrabold text-emerald-400 mt-1"><?= number_format($stats['total_published']) ?></p>
                <p class="text-[10px] text-slate-400 mt-0.5">Tayang di portal publik</p>
            </div>
            <div class="h-10 w-10 sm:h-12 sm:w-12 rounded-2xl bg-emerald-500/10 border border-emerald-500/20 flex items-center justify-center text-emerald-400 text-lg sm:text-xl">
                <i class="fa-solid fa-circle-check"></i>
            </div>
        </div>

        <!-- 2. Menunggu Persetujuan (Pending) -->
        <div class="bg-slate-900/80 backdrop-blur-md rounded-2xl border <?= $stats['total_pending'] > 0 ? 'border-amber-500/40 shadow-lg shadow-amber-500/10' : 'border-white/10' ?> p-4 sm:p-5 flex items-center justify-between">
            <div>
                <p class="text-[11px] sm:text-xs font-semibold uppercase tracking-wider text-slate-400">Butuh Persetujuan</p>
                <p class="text-xl sm:text-2xl font-extrabold text-amber-400 mt-1 flex items-center gap-2">
                    <?= number_format($stats['total_pending']) ?>
                    <?php if ($stats['total_pending'] > 0): ?>
                        <span class="inline-block h-2 w-2 rounded-full bg-amber-400 animate-ping"></span>
                    <?php endif; ?>
                </p>
                <p class="text-[10px] text-slate-400 mt-0.5"><?= $can_manage_all ? 'Menunggu review Anda' : 'Dari pengajuan guru' ?></p>
            </div>
            <div class="h-10 w-10 sm:h-12 sm:w-12 rounded-2xl bg-amber-500/10 border border-amber-500/20 flex items-center justify-center text-amber-400 text-lg sm:text-xl">
                <i class="fa-solid fa-clock-rotate-left"></i>
            </div>
        </div>

        <!-- 3. Berita Saya / Draf -->
        <div class="bg-slate-900/80 backdrop-blur-md rounded-2xl border border-white/10 p-4 sm:p-5 flex items-center justify-between">
            <div>
                <p class="text-[11px] sm:text-xs font-semibold uppercase tracking-wider text-slate-400">
                    <?= $is_guru ? 'Tulisan Saya' : 'Draf & Arsip' ?>
                </p>
                <p class="text-xl sm:text-2xl font-extrabold text-sky-400 mt-1">
                    <?= number_format($is_guru ? $stats['my_news'] : $stats['total_draft']) ?>
                </p>
                <p class="text-[10px] text-slate-400 mt-0.5">
                    <?= $is_guru ? 'Total artikel diajukan' : 'Belum dipublikasikan' ?>
                </p>
            </div>
            <div class="h-10 w-10 sm:h-12 sm:w-12 rounded-2xl bg-sky-500/10 border border-sky-500/20 flex items-center justify-center text-sky-400 text-lg sm:text-xl">
                <i class="fa-solid <?= $is_guru ? 'fa-user-pen' : 'fa-file-lines' ?>"></i>
            </div>
        </div>

        <!-- 4. Total Pembaca / Views -->
        <div class="bg-slate-900/80 backdrop-blur-md rounded-2xl border border-white/10 p-4 sm:p-5 flex items-center justify-between">
            <div>
                <p class="text-[11px] sm:text-xs font-semibold uppercase tracking-wider text-slate-400">Total Pembaca</p>
                <p class="text-xl sm:text-2xl font-extrabold text-purple-400 mt-1"><?= number_format($stats['total_views']) ?></p>
                <p class="text-[10px] text-slate-400 mt-0.5">Akumulasi views portal</p>
            </div>
            <div class="h-10 w-10 sm:h-12 sm:w-12 rounded-2xl bg-purple-500/10 border border-purple-500/20 flex items-center justify-center text-purple-400 text-lg sm:text-xl">
                <i class="fa-solid fa-eye"></i>
            </div>
        </div>
    </div>

    <!-- ============================================================== -->
    <!-- FILTER BAR & SEARCH -->
    <!-- ============================================================== -->
    <div class="bg-slate-900/90 backdrop-blur-xl border border-white/10 rounded-2xl p-4 sm:p-5 shadow-xl space-y-3">
        <!-- Quick Status Pills -->
        <div class="flex items-center gap-1.5 overflow-x-auto pb-2 scrollbar-thin">
            <a href="news.php" 
               class="px-3 py-1.5 rounded-xl text-xs font-semibold whitespace-nowrap transition <?= empty($filter_status) && empty($filter_scope) ? 'bg-blue-600 text-white shadow-md shadow-blue-600/30' : 'bg-white/5 text-slate-300 hover:bg-white/10 hover:text-white' ?>">
                <i class="fa-solid fa-list-ul mr-1"></i> Semua Berita
            </a>

            <?php if ($can_manage_all): ?>
                <a href="news.php?status=pending" 
                   class="px-3 py-1.5 rounded-xl text-xs font-semibold whitespace-nowrap transition flex items-center gap-1.5 <?= $filter_status === 'pending' ? 'bg-amber-500 text-slate-950 font-bold shadow-md shadow-amber-500/30' : 'bg-amber-500/10 text-amber-400 border border-amber-500/20 hover:bg-amber-500/20' ?>">
                    <i class="fa-solid fa-clock-rotate-left"></i>
                    <span>Menunggu Persetujuan</span>
                    <?php if ($stats['total_pending'] > 0): ?>
                        <span class="px-1.5 py-0.2 rounded-full text-[10px] <?= $filter_status === 'pending' ? 'bg-slate-950 text-amber-400' : 'bg-amber-500 text-slate-950' ?> font-extrabold">
                            <?= $stats['total_pending'] ?>
                        </span>
                    <?php endif; ?>
                </a>
            <?php endif; ?>

            <a href="news.php?status=published" 
               class="px-3 py-1.5 rounded-xl text-xs font-semibold whitespace-nowrap transition <?= $filter_status === 'published' ? 'bg-emerald-600 text-white shadow-md shadow-emerald-600/30' : 'bg-white/5 text-slate-300 hover:bg-white/10 hover:text-white' ?>">
                <i class="fa-solid fa-circle-check mr-1"></i> Diterbitkan (<?= $stats['total_published'] ?>)
            </a>

            <a href="news.php?status=draft" 
               class="px-3 py-1.5 rounded-xl text-xs font-semibold whitespace-nowrap transition <?= $filter_status === 'draft' ? 'bg-slate-700 text-white' : 'bg-white/5 text-slate-300 hover:bg-white/10 hover:text-white' ?>">
                <i class="fa-solid fa-file-pen mr-1"></i> Draf (<?= $stats['total_draft'] ?>)
            </a>

            <a href="news.php?status=rejected" 
               class="px-3 py-1.5 rounded-xl text-xs font-semibold whitespace-nowrap transition <?= $filter_status === 'rejected' ? 'bg-rose-600 text-white shadow-md shadow-rose-600/30' : 'bg-white/5 text-slate-300 hover:bg-white/10 hover:text-white' ?>">
                <i class="fa-solid fa-triangle-exclamation mr-1"></i> Ditolak / Revisi (<?= $stats['total_rejected'] ?>)
            </a>

            <?php if ($is_guru): ?>
                <a href="news.php?scope=my" 
                   class="px-3 py-1.5 rounded-xl text-xs font-semibold whitespace-nowrap transition <?= $filter_scope === 'my' ? 'bg-indigo-600 text-white shadow-md shadow-indigo-600/30' : 'bg-indigo-500/10 text-indigo-300 border border-indigo-500/20 hover:bg-indigo-500/20' ?>">
                    <i class="fa-solid fa-user-pen mr-1"></i> Tulisan Saya (<?= $stats['my_news'] ?>)
                </a>
            <?php endif; ?>
        </div>

        <!-- Filter Form Dropdowns & Search -->
        <form method="GET" action="news.php" class="grid grid-cols-1 sm:grid-cols-12 gap-3 pt-2 border-t border-white/5">
            <?php if (!empty($filter_status)): ?>
                <input type="hidden" name="status" value="<?= htmlspecialchars($filter_status) ?>">
            <?php endif; ?>
            <?php if (!empty($filter_scope)): ?>
                <input type="hidden" name="scope" value="<?= htmlspecialchars($filter_scope) ?>">
            <?php endif; ?>

            <!-- Search input -->
            <div class="sm:col-span-6 relative">
                <i class="fa-solid fa-magnifying-glass absolute left-3.5 top-1/2 -translate-y-1/2 text-slate-400 text-xs"></i>
                <input type="text" name="q" value="<?= htmlspecialchars($search_q) ?>" 
                       placeholder="Cari judul berita, ringkasan, atau nama penulis..." 
                       class="w-full pl-9 pr-4 py-2 rounded-xl bg-slate-950/70 border border-white/10 text-xs sm:text-sm text-white placeholder-slate-400 focus:outline-none focus:border-blue-500 transition">
            </div>

            <!-- Filter Kategori -->
            <div class="sm:col-span-4">
                <select name="category" onchange="this.form.submit()" 
                        class="w-full px-3 py-2 rounded-xl bg-slate-950/70 border border-white/10 text-xs sm:text-sm text-slate-200 focus:outline-none focus:border-blue-500 transition">
                    <option value="">Semua Kategori</option>
                    <?php foreach (NEWS_CATEGORIES as $ckey => $cdata): ?>
                        <option value="<?= $ckey ?>" <?= $filter_category === $ckey ? 'selected' : '' ?>>
                            <?= htmlspecialchars($cdata['label']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <!-- Reset / Submit button -->
            <div class="sm:col-span-2 flex items-center gap-2">
                <button type="submit" class="flex-1 py-2 rounded-xl bg-blue-600 hover:bg-blue-500 text-white text-xs font-semibold shadow-sm transition">
                    Filter
                </button>
                <?php if ($search_q !== '' || $filter_category !== '' || $filter_status !== '' || $filter_scope !== ''): ?>
                    <a href="news.php" class="p-2 rounded-xl bg-white/5 hover:bg-white/10 text-slate-400 hover:text-white text-xs font-semibold transition" title="Reset Filter">
                        <i class="fa-solid fa-rotate-left"></i>
                    </a>
                <?php endif; ?>
            </div>
        </form>
    </div>

    <!-- ============================================================== -->
    <!-- DAFTAR KARTU BERITA -->
    <!-- ============================================================== -->
    <?php if (empty($news_list)): ?>
        <div class="bg-slate-900/60 rounded-3xl border border-white/10 p-12 text-center space-y-3">
            <div class="h-16 w-16 rounded-full bg-slate-800 text-slate-400 mx-auto flex items-center justify-center text-2xl">
                <i class="fa-solid fa-newspaper"></i>
            </div>
            <h3 class="text-base font-bold text-white">Tidak Ada Berita yang Ditemukan</h3>
            <p class="text-xs sm:text-sm text-slate-400 max-w-md mx-auto">
                Belum ada berita atau artikel yang sesuai dengan filter atau kata kunci pencarian yang Anda pilih.
            </p>
            <div class="pt-2">
                <button type="button" onclick="openCreateNewsModal()" class="inline-flex items-center gap-2 px-4 py-2 rounded-xl bg-blue-600 hover:bg-blue-500 text-white text-xs font-semibold transition">
                    <i class="fa-solid fa-feather-pointed"></i>
                    <span>Mulai Tulis Berita Pertama</span>
                </button>
            </div>
        </div>
    <?php else: ?>
        <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-5">
            <?php foreach ($news_list as $item): ?>
                <?php
                    $is_author = ((int)$item['author_id'] === $user_id);
                    $can_edit = $can_manage_all || $is_author;
                    $can_delete = $can_manage_all || $is_author;
                    $cat_info = NEWS_CATEGORIES[$item['category']] ?? ['label' => ucfirst($item['category']), 'badge' => 'border-slate-500/30 bg-slate-500/10 text-slate-300'];
                    $status_info = NEWS_STATUSES[$item['status']] ?? ['label' => ucfirst($item['status']), 'badge' => 'border-slate-500/30 bg-slate-500/10 text-slate-300'];
                    $thumb_src = !empty($item['thumbnail']) ? resolveAssetUrl($item['thumbnail'], '../../') : null;
                ?>
                <div class="bg-slate-900/85 backdrop-blur-xl rounded-3xl border <?= $item['status'] === 'pending' ? 'border-amber-500/40 shadow-lg shadow-amber-500/10' : ($item['is_featured'] ? 'border-indigo-500/40' : 'border-white/10') ?> overflow-hidden flex flex-col justify-between hover:border-blue-500/40 transition duration-300 group">
                    
                    <div>
                        <!-- Cover Image / Fallback Header -->
                        <div class="relative h-44 w-full bg-slate-950 overflow-hidden">
                            <?php if ($thumb_src): ?>
                                <img src="<?= $thumb_src ?>" alt="<?= htmlspecialchars($item['title']) ?>" 
                                     class="w-full h-full object-cover group-hover:scale-105 transition duration-500">
                            <?php else: ?>
                                <div class="w-full h-full bg-gradient-to-tr from-slate-950 via-slate-900 to-indigo-950/70 flex items-center justify-center text-slate-700">
                                    <i class="fa-solid fa-newspaper text-5xl opacity-40"></i>
                                </div>
                            <?php endif; ?>

                            <!-- Overlay Gradient -->
                            <div class="absolute inset-0 bg-gradient-to-t from-slate-900 via-transparent to-black/40"></div>

                            <!-- Top Badges -->
                            <div class="absolute top-3 left-3 right-3 flex items-center justify-between gap-2">
                                <span class="px-2.5 py-1 rounded-full text-[10px] font-bold uppercase tracking-wider backdrop-blur-md border <?= $cat_info['badge'] ?>">
                                    <?= htmlspecialchars($cat_info['label']) ?>
                                </span>

                                <div class="flex items-center gap-1.5">
                                    <?php if ($item['is_featured']): ?>
                                        <span class="px-2 py-0.5 rounded-full text-[10px] font-bold bg-amber-500 text-slate-950 flex items-center gap-1 shadow-sm" title="Berita Utama">
                                            <i class="fa-solid fa-star"></i> Featured
                                        </span>
                                    <?php endif; ?>

                                    <span class="px-2.5 py-0.5 rounded-full text-[10px] font-bold border backdrop-blur-md <?= $status_info['badge'] ?>">
                                        <?= htmlspecialchars($status_info['label']) ?>
                                    </span>
                                </div>
                            </div>

                            <!-- Bottom Photo Meta: Views & Date -->
                            <div class="absolute bottom-2.5 left-3 right-3 flex items-center justify-between text-[11px] text-slate-300">
                                <span class="flex items-center gap-1">
                                    <i class="fa-regular fa-calendar-days text-slate-400"></i>
                                    <?= date('d M Y', strtotime($item['created_at'])) ?>
                                </span>
                                <span class="flex items-center gap-1 bg-black/50 px-2 py-0.5 rounded-full backdrop-blur-sm">
                                    <i class="fa-regular fa-eye text-slate-400"></i>
                                    <?= number_format($item['views']) ?> views
                                </span>
                            </div>
                        </div>

                        <!-- Content Body -->
                        <div class="p-4 sm:p-5 space-y-3">
                            <h2 class="text-base sm:text-lg font-bold text-white line-clamp-2 group-hover:text-blue-400 transition leading-snug">
                                <?= htmlspecialchars($item['title']) ?>
                            </h2>

                            <p class="text-xs text-slate-400 line-clamp-3 leading-relaxed">
                                <?= htmlspecialchars($item['excerpt'] ?: strip_tags($item['content'])) ?>
                            </p>

                            <!-- Catatan Penolakan jika Status Rejected -->
                            <?php if ($item['status'] === 'rejected' && !empty($item['rejection_note'])): ?>
                                <div class="p-3 rounded-xl bg-rose-500/10 border border-rose-500/25 text-rose-300 text-xs space-y-1">
                                    <div class="font-bold flex items-center gap-1.5 text-rose-200">
                                        <i class="fa-solid fa-triangle-exclamation"></i>
                                        <span>Catatan Review Penolakan:</span>
                                    </div>
                                    <p class="text-[11px] leading-relaxed italic">
                                        "<?= htmlspecialchars($item['rejection_note']) ?>"
                                    </p>
                                </div>
                            <?php endif; ?>

                            <!-- Badges Integrasi Ekosistem Sekolah -->
                            <?php if (!empty($item['book_title']) || !empty($item['event_title']) || !empty($item['counseling_title'])): ?>
                                <div class="flex flex-wrap items-center gap-1.5 pt-1">
                                    <?php if (!empty($item['book_title'])): ?>
                                        <span class="inline-flex items-center gap-1 text-[10px] font-semibold text-teal-300 bg-teal-500/10 border border-teal-500/20 px-2 py-0.5 rounded-lg" title="Buku: <?= htmlspecialchars($item['book_title']) ?>">
                                            <i class="fa-solid fa-book-bookmark text-teal-400"></i> <?= htmlspecialchars(mb_substr($item['book_title'], 0, 22)) ?><?= mb_strlen($item['book_title']) > 22 ? '...' : '' ?>
                                        </span>
                                    <?php endif; ?>
                                    <?php if (!empty($item['event_title'])): ?>
                                        <span class="inline-flex items-center gap-1 text-[10px] font-semibold text-cyan-300 bg-cyan-500/10 border border-cyan-500/20 px-2 py-0.5 rounded-lg" title="Agenda: <?= htmlspecialchars($item['event_title']) ?>">
                                            <i class="fa-regular fa-calendar-check text-cyan-400"></i> <?= htmlspecialchars(mb_substr($item['event_title'], 0, 22)) ?><?= mb_strlen($item['event_title']) > 22 ? '...' : '' ?>
                                        </span>
                                    <?php endif; ?>
                                    <?php if (!empty($item['counseling_title'])): ?>
                                        <span class="inline-flex items-center gap-1 text-[10px] font-semibold text-amber-300 bg-amber-500/10 border border-amber-500/20 px-2 py-0.5 rounded-lg" title="Prestasi: <?= htmlspecialchars($item['counseling_student_name']) ?>">
                                            <i class="fa-solid fa-trophy text-amber-400"></i> <?= htmlspecialchars($item['counseling_student_name']) ?>
                                        </span>
                                    <?php endif; ?>
                                </div>
                            <?php endif; ?>

                            <!-- Author & Approver Info -->
                            <div class="pt-3 border-t border-white/5 flex items-center justify-between text-[11px]">
                                <div class="flex items-center gap-2">
                                    <div class="h-6 w-6 rounded-full bg-slate-800 text-slate-300 flex items-center justify-center font-bold text-[10px]">
                                        <?= strtoupper(mb_substr($item['author_name'], 0, 1)) ?>
                                    </div>
                                    <div>
                                        <p class="font-semibold text-slate-200 truncate max-w-[130px] sm:max-w-[160px]">
                                            <?= htmlspecialchars($item['author_name']) ?>
                                        </p>
                                        <p class="text-[10px] text-slate-500 capitalize">
                                            <?= htmlspecialchars(getRoleLabel($item['author_role'])) ?>
                                        </p>
                                    </div>
                                </div>

                                <?php if (!empty($item['approver_name'])): ?>
                                    <div class="text-right">
                                        <p class="text-[9px] text-slate-500 uppercase tracking-wider">Disetujui</p>
                                        <p class="text-[10px] text-emerald-400 font-medium truncate max-w-[100px]">
                                            <?= htmlspecialchars($item['approver_name']) ?>
                                        </p>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <!-- Footer Action Buttons -->
                    <div class="p-4 sm:p-5 pt-0 border-t border-white/5 mt-3 flex flex-wrap items-center justify-between gap-2">
                        <!-- Preview Link / Modal Trigger -->
                        <button type="button" onclick="previewNews(<?= htmlspecialchars(json_encode($item, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP)) ?>)" 
                                class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-xl bg-white/5 hover:bg-white/10 text-slate-300 hover:text-white text-xs font-semibold transition">
                            <i class="fa-regular fa-eye"></i>
                            <span>Lihat Artikel</span>
                        </button>

                        <div class="flex items-center gap-1.5">
                            <!-- Tombol Broadcast WhatsApp (Staf & Admin jika published) -->
                            <?php if ($can_manage_all && $item['status'] === 'published'): ?>
                                <button type="button" onclick="openWaBroadcastModal(<?= (int)$item['id'] ?>, <?= htmlspecialchars(json_encode($item['title']), ENT_QUOTES, 'UTF-8') ?>)"
                                        class="p-2 rounded-xl bg-emerald-500/10 hover:bg-emerald-600 text-emerald-400 hover:text-white text-xs transition border border-emerald-500/25" 
                                        title="Kirim Siaran WhatsApp">
                                    <i class="fa-brands fa-whatsapp text-sm"></i>
                                </button>
                            <?php endif; ?>

                            <!-- Tombol Approval (Khusus Staf & Admin jika pending) -->
                            <?php if ($can_manage_all && $item['status'] === 'pending'): ?>
                                <button type="button" onclick="confirmApprove(<?= (int)$item['id'] ?>, <?= htmlspecialchars(json_encode($item['title']), ENT_QUOTES, 'UTF-8') ?>)"
                                        class="px-2.5 py-1.5 rounded-xl bg-emerald-600 hover:bg-emerald-500 text-white text-xs font-semibold shadow-sm transition flex items-center gap-1"
                                        title="Setujui & Publikasikan">
                                    <i class="fa-solid fa-check"></i>
                                    <span class="hidden sm:inline">Setujui</span>
                                </button>

                                <button type="button" onclick="openRejectModal(<?= (int)$item['id'] ?>, <?= htmlspecialchars(json_encode($item['title']), ENT_QUOTES, 'UTF-8') ?>)"
                                        class="px-2.5 py-1.5 rounded-xl bg-rose-600/20 hover:bg-rose-600 text-rose-300 hover:text-white border border-rose-500/30 text-xs font-semibold transition flex items-center gap-1"
                                        title="Tolak / Minta Revisi">
                                    <i class="fa-solid fa-xmark"></i>
                                    <span class="hidden sm:inline">Tolak</span>
                                </button>
                            <?php endif; ?>

                            <!-- Toggle Featured (Admin & Staf) -->
                            <?php if ($can_manage_all): ?>
                                <a href="news.php?toggle_featured=<?= $item['id'] ?>" 
                                   class="p-2 rounded-xl text-xs transition <?= $item['is_featured'] ? 'bg-amber-500/20 text-amber-400 hover:bg-amber-500/30' : 'bg-white/5 text-slate-400 hover:text-amber-400 hover:bg-white/10' ?>" 
                                   title="<?= $item['is_featured'] ? 'Hapus dari Berita Utama' : 'Jadikan Berita Utama' ?>">
                                    <i class="fa-solid fa-star"></i>
                                </a>
                            <?php endif; ?>

                            <!-- Edit Button -->
                            <?php if ($can_edit): ?>
                                <button type="button" onclick="openEditNewsModal(<?= htmlspecialchars(json_encode($item, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP)) ?>)" 
                                        class="p-2 rounded-xl bg-white/5 hover:bg-blue-600 text-slate-300 hover:text-white text-xs transition" title="Edit Berita">
                                    <i class="fa-solid fa-pen-to-square"></i>
                                </button>
                            <?php endif; ?>

                            <!-- Delete Button -->
                            <?php if ($can_delete): ?>
                                <button type="button" onclick="confirmDelete(<?= (int)$item['id'] ?>, <?= htmlspecialchars(json_encode($item['title']), ENT_QUOTES, 'UTF-8') ?>)" 
                                        class="p-2 rounded-xl bg-white/5 hover:bg-rose-600 text-slate-300 hover:text-white text-xs transition" title="Hapus Berita">
                                    <i class="fa-regular fa-trash-can"></i>
                                </button>
                            <?php endif; ?>
                        </div>
                    </div>

                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

</div>

<!-- ============================================================== -->
<!-- MODAL: TULIS / EDIT BERITA BARU -->
<!-- ============================================================== -->
<div id="newsFormModal" class="fixed inset-0 z-50 hidden bg-black/85 backdrop-blur-md flex items-center justify-center p-3 sm:p-5 overflow-y-auto">
    <div class="bg-slate-900 border border-white/15 rounded-3xl w-full max-w-4xl max-h-[92vh] flex flex-col shadow-2xl overflow-hidden modal-transition">
        
        <!-- Modal Header -->
        <div class="p-4 sm:p-5 border-b border-white/10 flex items-center justify-between shrink-0 bg-slate-950/60">
            <div>
                <h3 id="formModalTitle" class="text-base sm:text-lg font-bold text-white flex items-center gap-2">
                    <i class="fa-solid fa-feather-pointed text-blue-400"></i>
                    <span>Tulis Berita Baru</span>
                </h3>
                <p class="text-xs text-slate-400 mt-0.5">
                    <?= $is_guru ? 'Artikel Anda akan diajukan ke tim Staf / Administrator untuk proses moderasi.' : 'Publikasikan berita atau simpan sebagai draf internal.' ?>
                </p>
            </div>
            <button type="button" onclick="closeNewsFormModal()" class="p-2 text-slate-400 hover:text-white rounded-xl hover:bg-white/10 transition">
                <i class="fa-solid fa-xmark text-lg"></i>
            </button>
        </div>

        <!-- Form Content (Scrollable) -->
        <form id="newsForm" method="POST" action="news.php" enctype="multipart/form-data" class="flex-1 overflow-y-auto p-4 sm:p-6 space-y-4">
            <?= csrfField() ?>
            <input type="hidden" name="action" id="formActionInput" value="create_news">
            <input type="hidden" name="news_id" id="formNewsId" value="">
            <input type="hidden" name="content" id="formContentInput" value="">
            <input type="hidden" name="remove_thumbnail" id="formRemoveThumbnail" value="0">

            <!-- Judul Berita -->
            <div>
                <label class="block text-xs font-semibold text-slate-300 mb-1.5">
                    Judul Berita <span class="text-rose-400">*</span>
                </label>
                <input type="text" name="title" id="formTitle" required
                       placeholder="Contoh: Siswa SMA Teladan Raih Medali Emas OSN 2026 Tingkat Nasional" 
                       class="w-full px-4 py-2.5 rounded-xl bg-slate-950 border border-white/15 text-sm text-white placeholder-slate-500 focus:outline-none focus:border-blue-500 transition">
            </div>

            <!-- Kategori & Cover Image -->
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                    <label class="block text-xs font-semibold text-slate-300 mb-1.5">
                        Kategori Berita <span class="text-rose-400">*</span>
                    </label>
                    <select name="category" id="formCategory" required
                            class="w-full px-3.5 py-2.5 rounded-xl bg-slate-950 border border-white/15 text-sm text-slate-200 focus:outline-none focus:border-blue-500 transition">
                        <?php foreach (NEWS_CATEGORIES as $ckey => $cdata): ?>
                            <option value="<?= $ckey ?>"><?= htmlspecialchars($cdata['label']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div>
                    <label class="block text-xs font-semibold text-slate-300 mb-1.5">
                        Foto Sampul / Cover (Opsional, Maks 5MB)
                    </label>
                    <input type="file" name="thumbnail" id="formThumbnailFile" accept="image/*"
                           class="w-full text-xs text-slate-400 file:mr-3 file:py-2 file:px-3 file:rounded-xl file:border-0 file:text-xs file:font-semibold file:bg-blue-600 file:text-white hover:file:bg-blue-500 file:cursor-pointer">
                    
                    <!-- Preview current thumbnail (saat edit) -->
                    <div id="currentThumbnailBox" class="hidden mt-2 flex items-center justify-between p-2 rounded-xl bg-slate-950 border border-white/10">
                        <div class="flex items-center gap-2">
                            <img id="currentThumbnailImg" src="" alt="Thumbnail" class="h-10 w-14 object-cover rounded-lg">
                            <span class="text-xs text-slate-300 font-medium">Cover saat ini terpasang</span>
                        </div>
                        <button type="button" onclick="markRemoveThumbnail()" class="text-rose-400 hover:text-rose-300 text-xs font-semibold p-1">
                            <i class="fa-solid fa-trash-can mr-1"></i> Hapus
                        </button>
                    </div>
                </div>
            </div>

            <!-- Ringkasan Singkat (Excerpt) -->
            <div>
                <label class="block text-xs font-semibold text-slate-300 mb-1.5">
                    Ringkasan Berita (Excerpt) <span class="text-slate-500 font-normal">(Opsional - maksimal 200 karakter)</span>
                </label>
                <textarea name="excerpt" id="formExcerpt" rows="2" maxlength="250"
                          placeholder="Ringkasan singkat yang akan ditampilkan pada kartu berita dan mading publik..." 
                          class="w-full px-3.5 py-2 rounded-xl bg-slate-950 border border-white/15 text-xs sm:text-sm text-white placeholder-slate-500 focus:outline-none focus:border-blue-500 transition"></textarea>
            </div>

            <!-- Integrasi Ekosistem Sekolah (Opsional) -->
            <div class="p-4 rounded-2xl bg-slate-950 border border-white/10 space-y-3">
                <div class="flex items-center gap-2">
                    <span class="text-blue-400 text-sm"><i class="fa-solid fa-network-wired"></i></span>
                    <h4 class="text-xs font-bold text-white uppercase tracking-wider">Integrasi Modul Sekolah (Opsional)</h4>
                </div>
                <p class="text-[11px] text-slate-400">
                    Tautkan artikel ini dengan data buku perpustakaan, agenda kalender akademik, atau rekam jejak prestasi siswa BK untuk menampilkan widget interaktif otomatis.
                </p>

                <div class="grid grid-cols-1 md:grid-cols-3 gap-3 pt-1">
                    <!-- Tautkan Buku Perpustakaan -->
                    <div>
                        <label class="block text-[11px] font-semibold text-teal-300 mb-1 flex items-center gap-1">
                            <i class="fa-solid fa-book-bookmark text-xs"></i> Buku Perpustakaan
                        </label>
                        <select name="book_id" id="formBookId" class="w-full px-3 py-2 rounded-xl bg-slate-900 border border-white/10 text-xs text-white focus:outline-none focus:border-teal-500 transition">
                            <option value="">-- Tidak Ditautkan --</option>
                            <?php foreach ($available_books as $bk): ?>
                                <option value="<?= $bk['id'] ?>">
                                    <?= htmlspecialchars($bk['title']) ?> (<?= htmlspecialchars($bk['author']) ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <!-- Tautkan Agenda Kalender -->
                    <div>
                        <label class="block text-[11px] font-semibold text-cyan-300 mb-1 flex items-center gap-1">
                            <i class="fa-regular fa-calendar-check text-xs"></i> Agenda Kegiatan Kalender
                        </label>
                        <select name="event_id" id="formEventId" class="w-full px-3 py-2 rounded-xl bg-slate-900 border border-white/10 text-xs text-white focus:outline-none focus:border-cyan-500 transition">
                            <option value="">-- Tidak Ditautkan --</option>
                            <?php foreach ($available_events as $ev): ?>
                                <option value="<?= $ev['id'] ?>">
                                    [<?= date('d/m', strtotime($ev['event_date'])) ?>] <?= htmlspecialchars($ev['title']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <!-- Tautkan Prestasi Siswa BK -->
                    <div>
                        <label class="block text-[11px] font-semibold text-amber-300 mb-1 flex items-center gap-1">
                            <i class="fa-solid fa-trophy text-xs"></i> Catatan Prestasi Siswa (BK)
                        </label>
                        <select name="counseling_id" id="formCounselingId" class="w-full px-3 py-2 rounded-xl bg-slate-900 border border-white/10 text-xs text-white focus:outline-none focus:border-amber-500 transition">
                            <option value="">-- Tidak Ditautkan --</option>
                            <?php foreach ($available_counseling as $cs): ?>
                                <option value="<?= $cs['id'] ?>">
                                    <?= htmlspecialchars($cs['student_name']) ?> - <?= htmlspecialchars($cs['title']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
            </div>

            <!-- Editor Konten Quill -->
            <div>
                <label class="block text-xs font-semibold text-slate-300 mb-1.5">
                    Isi Lengkap Berita <span class="text-rose-400">*</span>
                </label>
                <div id="newsQuillEditor"></div>
            </div>

            <!-- Pengaturan Status & Publikasi -->
            <div class="p-4 rounded-2xl bg-slate-950 border border-white/10 space-y-3">
                <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3">
                    <div>
                        <label class="block text-xs font-bold text-white mb-0.5">
                            Status Pengiriman / Publikasi
                        </label>
                        <p class="text-[11px] text-slate-400">
                            <?php if ($is_guru): ?>
                                Pilih <strong>Ajukan Persetujuan</strong> agar tulisan Anda dapat ditinjau oleh Staf/Admin, atau <strong>Draf</strong> jika masih ingin diedit nanti.
                            <?php else: ?>
                                Sebagai Staf/Admin, Anda dapat memilih <strong>Diterbitkan Langsung</strong> atau <strong>Draf</strong>.
                            <?php endif; ?>
                        </p>
                    </div>

                    <div class="shrink-0">
                        <select name="status" id="formStatusSelect" 
                                class="px-3 py-2 rounded-xl bg-slate-900 border border-white/20 text-xs sm:text-sm text-white font-semibold focus:outline-none focus:border-blue-500 transition">
                            <?php if ($is_guru): ?>
                                <option value="pending">Ajukan Persetujuan (Menunggu Review)</option>
                                <option value="draft">Simpan Sebagai Draf</option>
                            <?php else: ?>
                                <option value="published">Diterbitkan Resmi (Publik)</option>
                                <option value="draft">Simpan Sebagai Draf</option>
                                <option value="pending">Menunggu Persetujuan</option>
                            <?php endif; ?>
                        </select>
                    </div>
                </div>

                <?php if ($can_manage_all): ?>
                    <div class="pt-2 border-t border-white/10 flex items-center gap-2">
                        <input type="checkbox" name="is_featured" id="formIsFeatured" value="1" 
                               class="h-4 w-4 rounded bg-slate-900 border-white/20 text-blue-600 focus:ring-0">
                        <label for="formIsFeatured" class="text-xs text-slate-300 font-medium cursor-pointer">
                            Jadikan sebagai <strong class="text-amber-400 font-bold">Berita Utama / Headline (Featured)</strong> di halaman portal
                        </label>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Footer Action -->
            <div class="flex items-center justify-end gap-3 pt-3">
                <button type="button" onclick="closeNewsFormModal()" 
                        class="px-4 py-2 rounded-xl bg-white/10 hover:bg-white/15 text-slate-300 text-xs sm:text-sm font-semibold transition">
                    Batal
                </button>
                <button type="submit" id="btnSubmitNews" 
                        class="px-5 py-2 rounded-xl bg-blue-600 hover:bg-blue-500 text-white text-xs sm:text-sm font-semibold shadow-lg shadow-blue-600/30 transition flex items-center gap-2">
                    <i class="fa-solid fa-paper-plane"></i>
                    <span id="btnSubmitText">Simpan Berita</span>
                </button>
            </div>
        </form>

    </div>
</div>

<!-- ============================================================== -->
<!-- MODAL: PREVIEW / BACA DETAIL BERITA -->
<!-- ============================================================== -->
<div id="newsPreviewModal" class="fixed inset-0 z-50 hidden bg-black/85 backdrop-blur-md flex items-center justify-center p-3 sm:p-5 overflow-y-auto">
    <div class="bg-slate-900 border border-white/15 rounded-3xl w-full max-w-3xl max-h-[92vh] flex flex-col shadow-2xl overflow-hidden modal-transition">
        
        <div class="p-4 sm:p-5 border-b border-white/10 flex items-center justify-between shrink-0 bg-slate-950/60">
            <span id="previewCategoryBadge" class="px-2.5 py-1 rounded-full text-[11px] font-bold uppercase tracking-wider border">
                Kategori
            </span>
            <button type="button" onclick="closeNewsPreviewModal()" class="p-2 text-slate-400 hover:text-white rounded-xl hover:bg-white/10 transition">
                <i class="fa-solid fa-xmark text-lg"></i>
            </button>
        </div>

        <div class="flex-1 overflow-y-auto p-5 sm:p-8 space-y-5">
            <!-- Cover image jika ada -->
            <div id="previewCoverWrapper" class="hidden rounded-2xl overflow-hidden max-h-72 w-full bg-slate-950 border border-white/10">
                <img id="previewCoverImg" src="" alt="Cover" class="w-full h-full object-cover">
            </div>

            <!-- Judul & Meta -->
            <div class="space-y-2">
                <h1 id="previewTitle" class="text-xl sm:text-2xl font-extrabold text-white leading-tight"></h1>
                <div class="flex flex-wrap items-center gap-3 text-xs text-slate-400 pt-1">
                    <span id="previewAuthor" class="flex items-center gap-1 font-semibold text-slate-200">
                        <i class="fa-solid fa-pen-nib text-blue-400"></i> Penulis
                    </span>
                    <span class="text-slate-600">•</span>
                    <span id="previewDate" class="flex items-center gap-1">
                        <i class="fa-regular fa-calendar-days text-slate-500"></i> Tanggal
                    </span>
                    <span class="text-slate-600">•</span>
                    <span id="previewStatus" class="px-2 py-0.5 rounded-full text-[10px] font-bold border">
                        Status
                    </span>
                </div>
            </div>

            <!-- Rejection notice jika ada -->
            <div id="previewRejectionBox" class="hidden p-4 rounded-2xl bg-rose-500/10 border border-rose-500/25 text-rose-300 text-xs space-y-1">
                <p class="font-bold flex items-center gap-1.5 text-rose-200">
                    <i class="fa-solid fa-triangle-exclamation"></i>
                    <span>Catatan Review Penolakan:</span>
                </p>
                <p id="previewRejectionText" class="text-xs italic leading-relaxed"></p>
            </div>

            <!-- Konten Lengkap -->
            <div id="previewContent" class="text-slate-200 text-xs sm:text-sm leading-relaxed space-y-3 font-normal prose prose-invert max-w-none">
            </div>
        </div>

        <div class="p-4 border-t border-white/10 flex items-center justify-between shrink-0 bg-slate-950/60">
            <span class="text-[11px] text-slate-400">Portal Manajemen-PHP</span>
            <button type="button" onclick="closeNewsPreviewModal()" class="px-4 py-1.5 rounded-xl bg-white/10 hover:bg-white/15 text-slate-300 text-xs font-semibold transition">
                Tutup
            </button>
        </div>

    </div>
</div>

<!-- ============================================================== -->
<!-- MODAL: TOLAK / CATATAN REVISI BERITA (Khusus Staf & Admin) -->
<!-- ============================================================== -->
<div id="rejectModal" class="fixed inset-0 z-50 hidden bg-black/85 backdrop-blur-md flex items-center justify-center p-4">
    <div class="bg-slate-900 border border-white/15 rounded-3xl w-full max-w-md p-5 sm:p-6 shadow-2xl space-y-4">
        <div class="flex items-start gap-3">
            <div class="h-10 w-10 rounded-2xl bg-rose-500/15 border border-rose-500/30 flex items-center justify-center text-rose-400 shrink-0">
                <i class="fa-solid fa-triangle-exclamation text-lg"></i>
            </div>
            <div>
                <h3 class="text-base font-bold text-white">Tolak / Minta Revisi Berita</h3>
                <p id="rejectModalSub" class="text-xs text-slate-400 mt-0.5">
                    Berikan alasan atau catatan perbaikan yang harus dilakukan oleh guru sebelum berita diterbitkan.
                </p>
            </div>
        </div>

        <form method="POST" action="news.php" class="space-y-4">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="reject_news">
            <input type="hidden" name="news_id" id="rejectNewsId" value="">

            <div>
                <label class="block text-xs font-semibold text-slate-300 mb-1.5">
                    Catatan Revisi / Alasan Penolakan <span class="text-rose-400">*</span>
                </label>
                <textarea name="rejection_note" required rows="3"
                          placeholder="Contoh: Mohon lengkapi dokumentasi foto kegiatan dan perbaiki penulisan nama narasumber..." 
                          class="w-full px-3.5 py-2 rounded-xl bg-slate-950 border border-white/15 text-xs sm:text-sm text-white placeholder-slate-500 focus:outline-none focus:border-rose-500 transition"></textarea>
            </div>

            <div class="flex items-center justify-end gap-2 pt-2">
                <button type="button" onclick="closeRejectModal()" class="px-4 py-2 rounded-xl bg-white/10 hover:bg-white/15 text-slate-300 text-xs font-semibold transition">
                    Batal
                </button>
                <button type="submit" class="px-4 py-2 rounded-xl bg-rose-600 hover:bg-rose-500 text-white text-xs font-semibold transition flex items-center gap-1.5 shadow-lg shadow-rose-600/30">
                    <i class="fa-solid fa-xmark"></i>
                    <span>Tolak Berita</span>
                </button>
            </div>
        </form>
    </div>
</div>

<!-- ============================================================== -->
<!-- MODAL: SIARKAN BERITA KE WHATSAPP GATEWAY (Staf & Admin) -->
<!-- ============================================================== -->
<div id="waBroadcastModal" class="fixed inset-0 z-50 hidden bg-black/85 backdrop-blur-md flex items-center justify-center p-3 sm:p-5 overflow-y-auto">
    <div class="bg-slate-900 border border-white/15 rounded-3xl w-full max-w-lg shadow-2xl overflow-hidden modal-transition">
        <div class="p-4 sm:p-5 border-b border-white/10 flex items-center justify-between shrink-0 bg-slate-950/60">
            <h3 class="text-base font-bold text-white flex items-center gap-2">
                <i class="fa-brands fa-whatsapp text-emerald-400 text-lg"></i>
                <span>Siarkan Berita via WhatsApp</span>
            </h3>
            <button type="button" onclick="closeWaBroadcastModal()" class="p-2 text-slate-400 hover:text-white rounded-xl hover:bg-white/10 transition">
                <i class="fa-solid fa-xmark text-lg"></i>
            </button>
        </div>

        <form method="POST" action="news.php" class="p-5 space-y-4">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="broadcast_wa">
            <input type="hidden" name="news_id" id="waBroadcastNewsId" value="">

            <div class="p-3.5 rounded-2xl bg-slate-950 border border-white/10">
                <span class="text-[11px] font-bold text-slate-400 uppercase tracking-wider block mb-1">Berita yang akan disiarkan:</span>
                <p id="waBroadcastNewsTitle" class="text-xs sm:text-sm font-bold text-white"></p>
            </div>

            <div>
                <label class="block text-xs font-semibold text-slate-300 mb-1.5">Target Siaran (Audience) *</label>
                <select name="target_audience" id="waTargetAudience" onchange="toggleWaCustomPhone(this.value)" class="w-full px-3.5 py-2.5 rounded-xl bg-slate-950 border border-white/15 text-xs sm:text-sm text-white focus:outline-none focus:border-emerald-500 transition">
                    <option value="guru">Dewan Guru & Tenaga Kependidikan</option>
                    <option value="wali_murid">Orang Tua / Wali Murid Terdaftar</option>
                    <option value="semua">Semua Civitas (Guru, Staf & Orang Tua)</option>
                    <option value="custom">Nomor WhatsApp Tertentu Saja</option>
                </select>
            </div>

            <div id="waCustomPhoneWrap" class="hidden">
                <label class="block text-xs font-semibold text-slate-300 mb-1.5">Nomor WhatsApp Tujuan</label>
                <input type="text" name="custom_phone" id="waCustomPhone" placeholder="Contoh: 081234567890" class="w-full px-3.5 py-2.5 rounded-xl bg-slate-950 border border-white/15 text-xs sm:text-sm text-white focus:outline-none focus:border-emerald-500 transition">
                <span class="text-[11px] text-slate-500 mt-1 block">Format: 08xx atau 628xx</span>
            </div>

            <div class="p-3 rounded-xl bg-emerald-500/10 border border-emerald-500/20 text-emerald-300 text-xs flex items-start gap-2">
                <i class="fa-solid fa-circle-info mt-0.5"></i>
                <span class="leading-relaxed">Pesan siaran WhatsApp menyertakan judul berita, ringkasan pengantar, dan tautan langsung untuk membaca liputan lengkap di website resmi.</span>
            </div>

            <div class="flex items-center justify-end gap-2.5 pt-2">
                <button type="button" onclick="closeWaBroadcastModal()" class="px-4 py-2 rounded-xl bg-white/10 hover:bg-white/15 text-slate-300 text-xs font-semibold transition">
                    Batal
                </button>
                <button type="submit" class="px-5 py-2 rounded-xl bg-emerald-600 hover:bg-emerald-500 text-white text-xs font-bold shadow-lg shadow-emerald-600/30 transition flex items-center gap-1.5 cursor-pointer">
                    <i class="fa-brands fa-whatsapp"></i>
                    <span>Kirim Siaran Sekarang</span>
                </button>
            </div>
        </form>
    </div>
</div>

<!-- ============================================================== -->
<!-- HIDDEN FORMS UNTUK APPROVE & DELETE -->
<!-- ============================================================== -->
<form id="approveForm" method="POST" action="news.php" class="hidden">
    <?= csrfField() ?>
    <input type="hidden" name="action" value="approve_news">
    <input type="hidden" name="news_id" id="approveNewsId" value="">
</form>

<form id="deleteForm" method="POST" action="news.php" class="hidden">
    <?= csrfField() ?>
    <input type="hidden" name="action" value="delete_news">
    <input type="hidden" name="news_id" id="deleteNewsId" value="">
</form>

<!-- SweetAlert2 untuk konfirmasi modern -->
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

<script>
let quillNewsEditor = null;

document.addEventListener('DOMContentLoaded', function() {
    // Inisialisasi Quill Editor
    quillNewsEditor = new Quill('#newsQuillEditor', {
        theme: 'snow',
        placeholder: 'Tulis isi artikel berita lengkap di sini. Gunakan format judul, tebal, miring, list, dan kutipan sesuai kebutuhan...',
        modules: {
            toolbar: [
                [{ 'header': [1, 2, 3, false] }],
                ['bold', 'italic', 'underline', 'strike'],
                [{ 'color': [] }, { 'background': [] }],
                [{ 'list': 'ordered'}, { 'list': 'bullet' }],
                ['blockquote', 'code-block'],
                ['link', 'clean']
            ]
        }
    });

    // Sinkronisasi Quill ke input sebelum submit form
    document.getElementById('newsForm').addEventListener('submit', function(e) {
        const htmlContent = quillNewsEditor.root.innerHTML;
        const textContent = quillNewsEditor.getText().trim();
        if (!textContent) {
            e.preventDefault();
            Swal.fire({
                icon: 'warning',
                title: 'Konten Kosong',
                text: 'Harap masukkan isi berita sebelum menyimpan.',
                background: '#0f172a',
                color: '#fff'
            });
            return false;
        }
        document.getElementById('formContentInput').value = htmlContent;
    });

    // Auto-open create modal if parameters from BK / Perpustakaan / Kalender are provided in URL
    const urlParams = new URLSearchParams(window.location.search);
    if (urlParams.get('action') === 'create') {
        openCreateNewsModal();
        if (urlParams.get('title')) document.getElementById('formTitle').value = urlParams.get('title');
        if (urlParams.get('category')) document.getElementById('formCategory').value = urlParams.get('category');
        if (urlParams.get('excerpt')) document.getElementById('formExcerpt').value = urlParams.get('excerpt');
        if (urlParams.get('book_id')) document.getElementById('formBookId').value = urlParams.get('book_id');
        if (urlParams.get('event_id')) document.getElementById('formEventId').value = urlParams.get('event_id');
        if (urlParams.get('counseling_id')) document.getElementById('formCounselingId').value = urlParams.get('counseling_id');
        if (urlParams.get('content') && quillNewsEditor) {
            quillNewsEditor.root.innerHTML = urlParams.get('content');
        } else if (urlParams.get('source') === 'prestasi' && quillNewsEditor) {
            const stTitle = urlParams.get('title') || 'Prestasi Siswa';
            const stExcerpt = urlParams.get('excerpt') || '';
            quillNewsEditor.root.innerHTML = `<p>Keluarga besar sekolah dengan bangga menyampaikan apresiasi dan selamat setinggi-tingginya atas capaian membanggakan: <strong>${stTitle}</strong>.</p><p>${stExcerpt}</p><p>Prestasi ini merupakan buah dari dedikasi, kerja keras, bimbingan para guru pembina, serta dukungan penuh orang tua. Sekolah senantiasa berkomitmen untuk memfasilitasi dan mendorong seluruh peserta didik agar mampu mengukir prestasi terbaik di bidang akademik maupun non-akademik.</p><blockquote>&ldquo;Semoga capaian prestasi ini menjadi inspirasi dan pemacu semangat bagi seluruh siswa-siswi lainnya untuk terus berkarya, berprestasi, dan mengharumkan nama sekolah.&rdquo;</blockquote><p>Selamat dan sukses selalu!</p>`;
        } else if (urlParams.get('source') === 'book' && quillNewsEditor) {
            const bTitle = urlParams.get('title') || 'Resensi Buku';
            const bExcerpt = urlParams.get('excerpt') || '';
            quillNewsEditor.root.innerHTML = `<p>Dalam rangka memperkaya wawasan literasi civitas akademika sekolah, berikut adalah ulasan dan rekomendasi buku pilihan yang kini dapat diakses di Perpustakaan Sekolah: <strong>${bTitle}</strong>.</p><p>${bExcerpt}</p><p>Buku ini menyajikan gagasan yang kaya dan sangat direkomendasikan bagi peserta didik serta dewan guru untuk dibaca dan dijadikan bahan pengembangan diri. Silakan kunjungi Perpustakaan Sekolah atau akses modul digital yang tersedia untuk membaca selengkapnya.</p>`;
        } else if (urlParams.get('source') === 'calendar' && quillNewsEditor) {
            const evTitle = urlParams.get('title') || 'Liputan Acara';
            const evExcerpt = urlParams.get('excerpt') || '';
            quillNewsEditor.root.innerHTML = `<p>Telah terlaksana dengan sukses dan lancar agenda resmi kegiatan sekolah: <strong>${evTitle}</strong>.</p><p>${evExcerpt}</p><p>Seluruh civitas akademika terlibat aktif dan antusias dalam menyukseskan rangkaian kegiatan ini. Terima kasih kepada seluruh panitia pelaksana, dewan guru, staf, serta para siswa yang telah berpartisipasi dengan penuh semangat.</p>`;
        }
    }
});

// Buka Modal Tulis Baru
function openCreateNewsModal() {
    document.getElementById('formActionInput').value = 'create_news';
    document.getElementById('formNewsId').value = '';
    document.getElementById('formModalTitle').innerHTML = '<i class="fa-solid fa-feather-pointed text-blue-400"></i> <span>Tulis Berita Baru</span>';
    document.getElementById('btnSubmitText').innerText = '<?= $is_guru ? "Ajukan Berita" : "Terbitkan Berita" ?>';
    
    document.getElementById('formTitle').value = '';
    document.getElementById('formCategory').value = 'kegiatan';
    document.getElementById('formExcerpt').value = '';
    document.getElementById('formThumbnailFile').value = '';
    document.getElementById('formRemoveThumbnail').value = '0';
    document.getElementById('currentThumbnailBox').classList.add('hidden');
    
    document.getElementById('formBookId').value = '';
    document.getElementById('formEventId').value = '';
    document.getElementById('formCounselingId').value = '';

    if (quillNewsEditor) {
        quillNewsEditor.root.innerHTML = '';
    }

    const modal = document.getElementById('newsFormModal');
    modal.classList.remove('hidden');
}

// Buka Modal Edit
function openEditNewsModal(item) {
    document.getElementById('formActionInput').value = 'edit_news';
    document.getElementById('formNewsId').value = item.id;
    document.getElementById('formModalTitle').innerHTML = '<i class="fa-solid fa-pen-to-square text-amber-400"></i> <span>Edit Berita</span>';
    document.getElementById('btnSubmitText').innerText = 'Simpan Perubahan';
    
    document.getElementById('formTitle').value = item.title || '';
    document.getElementById('formCategory').value = item.category || 'kegiatan';
    document.getElementById('formExcerpt').value = item.excerpt || '';
    document.getElementById('formStatusSelect').value = item.status || 'draft';
    document.getElementById('formRemoveThumbnail').value = '0';

    document.getElementById('formBookId').value = item.book_id || '';
    document.getElementById('formEventId').value = item.event_id || '';
    document.getElementById('formCounselingId').value = item.counseling_id || '';

    if (document.getElementById('formIsFeatured')) {
        document.getElementById('formIsFeatured').checked = (parseInt(item.is_featured) === 1);
    }

    // Tampilkan cover yang ada
    const curBox = document.getElementById('currentThumbnailBox');
    const curImg = document.getElementById('currentThumbnailImg');
    if (item.thumbnail) {
        curImg.src = item.thumbnail.startsWith('http') ? item.thumbnail : (item.thumbnail.startsWith('uploads/') ? '../../' + item.thumbnail : '../../uploads/news/' + item.thumbnail);
        curBox.classList.remove('hidden');
    } else {
        curBox.classList.add('hidden');
    }

    if (quillNewsEditor) {
        quillNewsEditor.root.innerHTML = item.content || '';
    }

    const modal = document.getElementById('newsFormModal');
    modal.classList.remove('hidden');
}

function markRemoveThumbnail() {
    document.getElementById('formRemoveThumbnail').value = '1';
    document.getElementById('currentThumbnailBox').classList.add('hidden');
}

function closeNewsFormModal() {
    document.getElementById('newsFormModal').classList.add('hidden');
}

// Preview Modal
function previewNews(item) {
    document.getElementById('previewTitle').innerText = item.title;
    document.getElementById('previewAuthor').innerHTML = '<i class="fa-solid fa-pen-nib text-blue-400 mr-1"></i> ' + (item.author_name || 'Penulis');
    document.getElementById('previewDate').innerHTML = '<i class="fa-regular fa-calendar-days text-slate-500 mr-1"></i> ' + (item.created_at ? item.created_at.substring(0, 10) : '');
    
    // Status & Kategori
    const badgeCategory = document.getElementById('previewCategoryBadge');
    badgeCategory.innerText = item.category.toUpperCase();

    const badgeStatus = document.getElementById('previewStatus');
    badgeStatus.innerText = item.status.toUpperCase();
    if (item.status === 'published') {
        badgeStatus.className = 'px-2 py-0.5 rounded-full text-[10px] font-bold border border-emerald-500/30 bg-emerald-500/10 text-emerald-300';
    } else if (item.status === 'pending') {
        badgeStatus.className = 'px-2 py-0.5 rounded-full text-[10px] font-bold border border-amber-500/30 bg-amber-500/10 text-amber-300';
    } else if (item.status === 'rejected') {
        badgeStatus.className = 'px-2 py-0.5 rounded-full text-[10px] font-bold border border-rose-500/30 bg-rose-500/10 text-rose-300';
    } else {
        badgeStatus.className = 'px-2 py-0.5 rounded-full text-[10px] font-bold border border-slate-500/30 bg-slate-500/10 text-slate-300';
    }

    // Cover Image
    const coverWrap = document.getElementById('previewCoverWrapper');
    const coverImg = document.getElementById('previewCoverImg');
    if (item.thumbnail) {
        coverImg.src = item.thumbnail.startsWith('http') ? item.thumbnail : (item.thumbnail.startsWith('uploads/') ? '../../' + item.thumbnail : '../../uploads/news/' + item.thumbnail);
        coverWrap.classList.remove('hidden');
    } else {
        coverWrap.classList.add('hidden');
    }

    // Rejection note
    const rejBox = document.getElementById('previewRejectionBox');
    const rejTxt = document.getElementById('previewRejectionText');
    if (item.status === 'rejected' && item.rejection_note) {
        rejTxt.innerText = '"' + item.rejection_note + '"';
        rejBox.classList.remove('hidden');
    } else {
        rejBox.classList.add('hidden');
    }

    // Content
    document.getElementById('previewContent').innerHTML = item.content || '<p class="text-slate-500 italic">Konten berita kosong.</p>';

    document.getElementById('newsPreviewModal').classList.remove('hidden');
}

function closeNewsPreviewModal() {
    document.getElementById('newsPreviewModal').classList.add('hidden');
}

// Konfirmasi Setujui (Approve)
function confirmApprove(id, title) {
    if (typeof Swal !== 'undefined') {
        Swal.fire({
            title: 'Setujui & Publikasikan?',
            html: `Apakah Anda yakin ingin menyetujui berita:<br><strong class="text-amber-400">"${title}"</strong>?<br><br><span class="text-xs text-slate-400">Berita akan langsung tampil di portal publik dan dibaca oleh seluruh civitas sekolah.</span>`,
            icon: 'question',
            showCancelButton: true,
            confirmButtonColor: '#10b981',
            cancelButtonColor: '#64748b',
            confirmButtonText: '<i class="fa-solid fa-check mr-1"></i> Ya, Setujui & Terbitkan',
            cancelButtonText: 'Batal',
            background: '#0f172a',
            color: '#fff'
        }).then((result) => {
            if (result.isConfirmed) {
                document.getElementById('approveNewsId').value = id;
                document.getElementById('approveForm').submit();
            }
        });
    } else {
        if (confirm(`Apakah Anda yakin ingin menyetujui dan mempublikasikan berita: "${title}"?`)) {
            document.getElementById('approveNewsId').value = id;
            document.getElementById('approveForm').submit();
        }
    }
}

// Modal Tolak (Reject)
function openRejectModal(id, title) {
    document.getElementById('rejectNewsId').value = id;
    document.getElementById('rejectModalSub').innerHTML = `Menolak pengajuan berita: <strong class="text-amber-300">"${title}"</strong>. Silakan isi catatan revisi.`;
    document.getElementById('rejectModal').classList.remove('hidden');
}

function closeRejectModal() {
    document.getElementById('rejectModal').classList.add('hidden');
}

// Konfirmasi Hapus
function confirmDelete(id, title) {
    if (typeof Swal !== 'undefined') {
        Swal.fire({
            title: 'Hapus Berita Ini?',
            html: `Berita <strong class="text-rose-400">"${title}"</strong> beserta lampiran covernya akan dihapus secara permanen.`,
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#ef4444',
            cancelButtonColor: '#64748b',
            confirmButtonText: '<i class="fa-solid fa-trash mr-1"></i> Ya, Hapus',
            cancelButtonText: 'Batal',
            background: '#0f172a',
            color: '#fff'
        }).then((result) => {
            if (result.isConfirmed) {
                document.getElementById('deleteNewsId').value = id;
                document.getElementById('deleteForm').submit();
            }
        });
    } else {
        if (confirm(`Apakah Anda yakin ingin menghapus berita: "${title}"?`)) {
            document.getElementById('deleteNewsId').value = id;
            document.getElementById('deleteForm').submit();
        }
    }
}

// WhatsApp Broadcast Modal Controllers
function openWaBroadcastModal(id, title) {
    document.getElementById('waBroadcastNewsId').value = id;
    document.getElementById('waBroadcastNewsTitle').textContent = title;
    document.getElementById('waTargetAudience').value = 'guru';
    document.getElementById('waCustomPhoneWrap').classList.add('hidden');
    document.getElementById('waCustomPhone').value = '';
    document.getElementById('waBroadcastModal').classList.remove('hidden');
}

function closeWaBroadcastModal() {
    document.getElementById('waBroadcastModal').classList.add('hidden');
}

function toggleWaCustomPhone(val) {
    const wrap = document.getElementById('waCustomPhoneWrap');
    if (val === 'custom') {
        wrap.classList.remove('hidden');
        document.getElementById('waCustomPhone').focus();
    } else {
        wrap.classList.add('hidden');
    }
}
</script>

<?php require_once __DIR__ . "/../includes/footer.php"; ?>
