<?php
/**
 * REST API Gateway: Presensi RFID / Barcode Fisik Gerbang Sekolah
 * Menerima tap kartu pelajar RFID / barcode scanner dari mikrokontroler (ESP32 / Arduino / Raspberry Pi)
 * dan mencatat presensi siswa/pendidik secara otomatis serta memicu notifikasi WhatsApp.
 */
declare(strict_types=1);

header("Content-Type: application/json; charset=UTF-8");
// CORS: Hanya izinkan origin dari host server terdaftar atau request same-origin
$request_origin = $_SERVER['HTTP_ORIGIN'] ?? '';
$server_host = $_SERVER['HTTP_HOST'] ?? 'localhost';
if (!empty($request_origin)) {
    $parsed_origin = parse_url($request_origin, PHP_URL_HOST);
    if ($parsed_origin === $server_host || $parsed_origin === 'localhost' || $parsed_origin === '127.0.0.1') {
        header("Access-Control-Allow-Origin: " . $request_origin);
    }
}
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-API-KEY");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once __DIR__ . "/../config/database.php";

// -------------------------------------------------------------
// 1. VALIDASI API KEY & AUTENTIKASI PERANGKAT
// -------------------------------------------------------------
$school_settings = getSchoolSettings($pdo);
$valid_api_key = trim($school_settings['api_gateway_key'] ?? '');

if (empty($valid_api_key)) {
    http_response_code(503);
    echo json_encode([
        'status' => 'error',
        'code' => 'GATEWAY_UNCONFIGURED',
        'message' => 'Layanan API Gerbang RFID belum dikonfigurasi kuncinya oleh administrator.'
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

// Ambil API Key dari Header atau Query/Body
$received_key = $_SERVER['HTTP_X_API_KEY'] ?? '';
if (empty($received_key) && !empty($_SERVER['HTTP_AUTHORIZATION'])) {
    if (preg_match('/Bearer\s+(.*)$/i', $_SERVER['HTTP_AUTHORIZATION'], $matches)) {
        $received_key = trim($matches[1]);
    }
}
if (empty($received_key)) {
    $received_key = trim((string)($_POST['api_key'] ?? $_GET['api_key'] ?? ''));
}

if (!hash_equals($valid_api_key, $received_key)) {
    http_response_code(401);
    echo json_encode([
        'status' => 'error',
        'code' => 'UNAUTHORIZED',
        'message' => 'API Key gerbang tidak valid atau belum diisi.'
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

// -------------------------------------------------------------
// 2. PARSE DATA INPUT (JSON / FORM-DATA)
// -------------------------------------------------------------
$raw_input = file_get_contents('php://input');
$json_data = json_decode($raw_input, true) ?: [];

$identifier = trim((string)($json_data['uid'] ?? $json_data['rfid_uid'] ?? $json_data['nisn'] ?? $json_data['user_id'] ?? $_POST['uid'] ?? $_POST['nisn'] ?? $_POST['user_id'] ?? ''));
$terminal_id = trim((string)($json_data['terminal_id'] ?? $json_data['device_id'] ?? $_POST['terminal_id'] ?? 'GERBANG-UTAMA-01'));
$action_mode = strtolower(trim((string)($json_data['action'] ?? $_POST['action'] ?? 'auto'))); // auto, in, out

if (empty($identifier)) {
    http_response_code(400);
    echo json_encode([
        'status' => 'error',
        'code' => 'EMPTY_IDENTIFIER',
        'message' => 'Parameter UID / NISN / User ID kartu wajib dikirimkan.'
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

$today = date('Y-m-d');
$now_time = date('H:i:s');
$late_threshold = '07:15:00';

// -------------------------------------------------------------
// 3. CARI PENGGUNA BERDASARKAN NISN, ID, ATAU NO TELEPON
// -------------------------------------------------------------
$stmt_user = $pdo->prepare("
    SELECT u.id, u.name, u.role, u.nisn, u.phone, u.class_id, c.name as class_name
    FROM users u
    LEFT JOIN classes c ON u.class_id = c.id
    WHERE u.nisn = ? OR u.id = ? OR u.phone = ?
    LIMIT 1
");
$stmt_user->execute([$identifier, is_numeric($identifier) ? (int)$identifier : -1, $identifier]);
$user = $stmt_user->fetch();

if (!$user) {
    http_response_code(404);
    echo json_encode([
        'status' => 'error',
        'code' => 'USER_NOT_FOUND',
        'message' => "Kartu/ID '{$identifier}' tidak terdaftar pada sistem civitas sekolah.",
        'terminal' => $terminal_id
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

$user_id = (int)$user['id'];
$user_name = $user['name'];
$user_role = $user['role'];
$class_name = $user['class_name'] ?? '-';

// -------------------------------------------------------------
// 4. EKSEKUSI PRESENSI SESUAI ROLE
// -------------------------------------------------------------
$response_data = [
    'user_id'    => $user_id,
    'name'       => $user_name,
    'role'       => $user_role,
    'class'      => $class_name,
    'date'       => $today,
    'time'       => $now_time,
    'terminal'   => $terminal_id
];

if ($user_role === 'siswa') {
    // A. PRESENSI SISWA (student_attendance)
    $stmt_check = $pdo->prepare("SELECT id, status, created_at FROM student_attendance WHERE student_id = ? AND date = ?");
    $stmt_check->execute([$user_id, $today]);
    $existing = $stmt_check->fetch();

    if ($existing) {
        $response_data['action'] = 'already_recorded';
        $response_data['status'] = $existing['status'];
        $response_data['message'] = "Presensi Ananda {$user_name} hari ini sudah tercatat sebelumnya.";

        echo json_encode([
            'status' => 'success',
            'code' => 'ALREADY_ATTENDED',
            'message' => $response_data['message'],
            'data' => $response_data
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        exit;
    }

    $att_status = ($now_time > $late_threshold) ? 'terlambat' : 'hadir';
    $notes = "Auto-Tap RFID Terminal [{$terminal_id}]";

    $stmt_ins = $pdo->prepare("
        INSERT INTO student_attendance (student_id, date, status, notes, recorded_by)
        VALUES (?, ?, ?, ?, ?)
    ");
    // recorded_by: gunakan ID siswa itu sendiri atau 1 (system admin)
    $stmt_ins->execute([$user_id, $today, $att_status, $notes, 1]);

    $response_data['action'] = 'check_in';
    $response_data['status'] = $att_status;
    $response_data['message'] = ($att_status === 'terlambat') 
        ? "Presensi tercatat: TERLAMBAT (Pukul {$now_time})" 
        : "Presensi berhasil: HADIR TEPAT WAKTU (Pukul {$now_time})";

    // Kirim notifikasi WA ke orang tua
    try {
        $stmt_parent = $pdo->prepare("
            SELECT u.name as parent_name, u.phone as parent_phone 
            FROM parent_students ps
            JOIN users u ON ps.parent_id = u.id
            WHERE ps.student_id = ?
            LIMIT 1
        ");
        $stmt_parent->execute([$user_id]);
        $parent = $stmt_parent->fetch();

        if ($parent && !empty($parent['parent_phone'])) {
            $status_indo = ($att_status === 'terlambat') ? 'Terlambat Masuk' : 'Hadir Tepat Waktu';
            $wa_msg = "Halo Bpk/Ibu *{$parent['parent_name']}*,\n\n"
                    . "Pemberitahuan presensi gerbang sekolah:\n"
                    . "Nama Siswa: *{$user_name}*\n"
                    . "Kelas: *{$class_name}*\n"
                    . "Waktu Tap: *{$now_time} WIB*\n"
                    . "Status: *{$status_indo}*\n"
                    . "Terminal: *{$terminal_id}*\n\n"
                    . "Terima kasih atas perhatian Bapak/Ibu.";
            sendWhatsAppNotification($pdo, $parent['parent_phone'], $parent['parent_name'], $wa_msg, 'presensi');
        }
    } catch (Exception $e) {}

    logActivity($pdo, 'RFID_ATTENDANCE_STUDENT', "Tap RFID Siswa {$user_name} ({$class_name}) di {$terminal_id}: {$att_status}");

} else {
    // B. PRESENSI PENDIDIK / TENAGA KEPENDIDIKAN (teacher_attendance)
    $stmt_t_check = $pdo->prepare("SELECT * FROM teacher_attendance WHERE teacher_id = ? AND date = ?");
    $stmt_t_check->execute([$user_id, $today]);
    $t_existing = $stmt_t_check->fetch();

    if (!$t_existing) {
        // Clock-In Guru/Staf
        $att_status = ($now_time > $late_threshold) ? 'terlambat' : 'hadir';
        $notes = "Clock-In Tap RFID Terminal [{$terminal_id}]";

        $stmt_t_ins = $pdo->prepare("
            INSERT INTO teacher_attendance (teacher_id, date, check_in_time, status, notes)
            VALUES (?, ?, ?, ?, ?)
        ");
        $stmt_t_ins->execute([$user_id, $today, $now_time, $att_status, $notes]);

        $response_data['action'] = 'clock_in';
        $response_data['status'] = $att_status;
        $response_data['message'] = "Clock-In Pendidik berhasil ({$att_status}) pukul {$now_time}";
        logActivity($pdo, 'RFID_TEACHER_CLOCK_IN', "Clock-in RFID Guru {$user_name} di {$terminal_id}");
    } else {
        // Jika sudah ada clock-in dan aksi out atau waktu > 13:00, catat clock-out
        if (empty($t_existing['check_out_time'])) {
            $stmt_t_up = $pdo->prepare("
                UPDATE teacher_attendance 
                SET check_out_time = ?, notes = CONCAT(COALESCE(notes,''), ' | Clock-Out RFID [{$terminal_id}]')
                WHERE id = ?
            ");
            $stmt_t_up->execute([$now_time, $t_existing['id']]);

            $response_data['action'] = 'clock_out';
            $response_data['status'] = $t_existing['status'];
            $response_data['check_in_time'] = $t_existing['check_in_time'];
            $response_data['check_out_time'] = $now_time;
            $response_data['message'] = "Clock-Out Pendidik berhasil dicatat pukul {$now_time}";
            logActivity($pdo, 'RFID_TEACHER_CLOCK_OUT', "Clock-out RFID Guru {$user_name} di {$terminal_id}");
        } else {
            $response_data['action'] = 'already_completed';
            $response_data['status'] = $t_existing['status'];
            $response_data['check_in_time'] = $t_existing['check_in_time'];
            $response_data['check_out_time'] = $t_existing['check_out_time'];
            $response_data['message'] = "Pendidik {$user_name} sudah menyelesaikan Clock-In & Clock-Out hari ini.";
        }
    }
}

// -------------------------------------------------------------
// 5. OUTPUT RESPONSE JSON RESMI
// -------------------------------------------------------------
http_response_code(200);
echo json_encode([
    'status' => 'success',
    'code' => 'ATTENDANCE_SUCCESS',
    'message' => $response_data['message'],
    'data' => $response_data
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
