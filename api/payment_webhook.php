<?php
/**
 * Payment Gateway Webhook Endpoint
 * Menerima callback settlement dari Payment Gateway (Tripay / Midtrans / Xendit / Bank VA)
 * Terintegrasi otomatis dengan student_bills, bill_payments (BKU), dan Notifikasi WhatsApp Wali.
 */
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . "/../config/database.php";

// Ambil input JSON atau POST
$raw_input = file_get_contents('php://input');
$data = json_decode($raw_input, true) ?: $_POST;

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || empty($data)) {
    http_response_code(400);
    echo json_encode([
        'status' => 'error',
        'message' => 'Permintaan tidak valid. Harap gunakan metode POST dengan payload yang sesuai.'
    ]);
    exit;
}

// 1. Validasi Keamanan Signature HMAC (Wajib Dikonfigurasi demi Keamanan)
$received_signature = $_SERVER['HTTP_X_CALLBACK_SIGNATURE'] ?? $_SERVER['HTTP_X_MIDTRANS_SIGNATURE'] ?? ($data['signature'] ?? '');
$webhook_secret = trim(getSetting($pdo, 'payment_gateway_secret', ''));

if (empty($webhook_secret)) {
    http_response_code(503);
    echo json_encode([
        'status' => 'error',
        'message' => 'Layanan webhook pembayaran belum dikonfigurasi oleh administrator sistem.'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if (empty($received_signature)) {
    http_response_code(401);
    echo json_encode([
        'status' => 'error',
        'message' => 'Header signature otentikasi webhook wajib disertakan.'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$expected_signature = hash_hmac('sha256', $raw_input, $webhook_secret);
if (!hash_equals($expected_signature, $received_signature)) {
    http_response_code(403);
    echo json_encode([
        'status' => 'error',
        'message' => 'Signature webhook tidak valid. Permintaan ditolak demi keamanan.'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// 2. Ekstraksi ID Tagihan
$bill_id = 0;
if (!empty($data['bill_id'])) {
    $bill_id = (int)$data['bill_id'];
} elseif (!empty($data['order_id'])) {
    // Format order_id umum: BILL-12-1695712345
    $parts = explode('-', (string)$data['order_id']);
    if (count($parts) >= 2) {
        $bill_id = (int)$parts[1];
    }
}

if ($bill_id <= 0) {
    http_response_code(422);
    echo json_encode([
        'status' => 'error',
        'message' => 'Parameter bill_id atau order_id tagihan tidak valid.'
    ]);
    exit;
}

// 3. Cek Data Tagihan
$stmt = $pdo->prepare("
    SELECT sb.*, u.name as student_name, u.phone as student_phone, u.id as student_user_id
    FROM student_bills sb
    JOIN users u ON sb.student_id = u.id
    WHERE sb.id = ?
");
$stmt->execute([$bill_id]);
$bill = $stmt->fetch();

if (!$bill) {
    http_response_code(404);
    echo json_encode([
        'status' => 'error',
        'message' => 'Tagihan dengan ID tersebut tidak ditemukan dalam basis data.'
    ]);
    exit;
}

// Idempotency: Jika sudah lunas, langsung kembalikan sukses tanpa duplikasi notifikasi/insert
if ($bill['status'] === 'lunas') {
    echo json_encode([
        'status' => 'success',
        'message' => 'Tagihan sudah berstatus LUNAS sebelumnya (Idempotent Request).',
        'data' => [
            'bill_id' => $bill_id,
            'status' => 'lunas'
        ]
    ]);
    exit;
}

$channel = trim($data['payment_channel'] ?? 'QRIS');
$trx_id  = trim($data['gateway_trx_id'] ?? ('TRX-' . strtoupper(bin2hex(random_bytes(6)))));
$va_num  = trim($data['va_number'] ?? '');
$trx_status = strtolower(trim($data['transaction_status'] ?? 'settlement'));

if (in_array($trx_status, ['settlement', 'paid', 'success', 'capture'], true)) {
    try {
        $pdo->beginTransaction();

        // A. Update status tagihan menjadi lunas
        $stmt_upd = $pdo->prepare("
            UPDATE student_bills 
            SET status = 'lunas',
                payment_channel = ?,
                gateway_trx_id = ?,
                va_number = ?,
                paid_at = CURRENT_TIMESTAMP,
                notes = CONCAT(COALESCE(notes, ''), ' | Lunas via Gateway ', ?, ' (Trx: ', ?, ')')
            WHERE id = ?
        ");
        $stmt_upd->execute([$channel, $trx_id, $va_num ?: null, $channel, $trx_id, $bill_id]);

        // B. Catat ke tabel bill_payments agar otomatis tersinkron ke Buku Kas Umum & Kuitansi
        $stmt_check_pay = $pdo->prepare("SELECT id FROM bill_payments WHERE bill_id = ? AND status = 'diterima' LIMIT 1");
        $stmt_check_pay->execute([$bill_id]);
        $existing_pay_id = $stmt_check_pay->fetchColumn();

        if (!$existing_pay_id) {
            $raw_channel_lower = strtolower($channel);
            if (strpos($raw_channel_lower, 'qris') !== false || strpos($raw_channel_lower, 'gopay') !== false || strpos($raw_channel_lower, 'ovo') !== false || strpos($raw_channel_lower, 'shopee') !== false) {
                $pay_method = 'qris';
            } elseif (strpos($raw_channel_lower, 'tunai') !== false || strpos($raw_channel_lower, 'cash') !== false || strpos($raw_channel_lower, 'cstore') !== false || strpos($raw_channel_lower, 'alfamart') !== false || strpos($raw_channel_lower, 'indomaret') !== false) {
                $pay_method = 'tunai';
            } else {
                $pay_method = 'transfer_bank';
            }

            $stmt_ins_pay = $pdo->prepare("
                INSERT INTO bill_payments 
                (bill_id, student_id, amount_paid, payment_method, payment_date, status, notes, verified_by, verified_at)
                VALUES (?, ?, ?, ?, CURRENT_DATE(), 'diterima', ?, NULL, CURRENT_TIMESTAMP())
            ");
            $pay_notes = "Pelunasan otomatis via Payment Gateway $channel (Trx ID: $trx_id)";
            $stmt_ins_pay->execute([$bill_id, $bill['student_id'], $bill['amount'], $pay_method, $pay_notes]);
        }

        $pdo->commit();
    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        http_response_code(500);
        echo json_encode([
            'status' => 'error',
            'message' => 'Gagal memperbarui status pembayaran: ' . $e->getMessage()
        ]);
        exit;
    }

    // C. Cari nomor telepon orang tua
    $stmt_parent = $pdo->prepare("
        SELECT u_p.name, u_p.phone 
        FROM parent_students ps
        JOIN users u_p ON ps.parent_id = u_p.id
        WHERE ps.student_id = ?
        LIMIT 1
    ");
    $stmt_parent->execute([$bill['student_user_id']]);
    $parent = $stmt_parent->fetch();

    $target_phone = $parent['phone'] ?? $bill['student_phone'] ?? '';
    $target_name  = $parent['name'] ?? $bill['student_name'];

    // D. Kirim notifikasi WhatsApp otomatis
    $wa_status = 'skipped_no_phone';
    if (!empty($target_phone)) {
        $nominal_fmt = formatRupiah($bill['amount']);
        $msg_wa = "🧾 *PEMBAYARAN DIVERIFIKASI (LUNAS)*\n\n" .
                  "Yth. Bpk/Ibu *$target_name*,\n" .
                  "Pembayaran tagihan *" . $bill['title'] . "* untuk ananda *" . $bill['student_name'] . "* sebesar *$nominal_fmt* telah BERHASIL diverifikasi otomatis melalui gateway *$channel* (Trx ID: $trx_id).\n\n" .
                  "Kuitansi resmi digital telah diterbitkan pada portal keuangan sekolah. Terima kasih.\n\n" .
                  "- Bendahara Keuangan Sekolah";
        
        $wa_res = sendWhatsAppNotification($pdo, $target_phone, $target_name, $msg_wa, 'keuangan');
        $wa_status = $wa_res['status'] ?? 'sent';
    }

    // E. Catat audit trail
    logActivity($pdo, 'PAYMENT_SETTLEMENT', "Tagihan ID $bill_id (" . $bill['title'] . ") lunas otomatis via $channel (Trx: $trx_id)");

    echo json_encode([
        'status' => 'success',
        'message' => 'Pembayaran berhasil diverifikasi dan status tagihan diperbarui menjadi LUNAS.',
        'data' => [
            'bill_id' => $bill_id,
            'title' => $bill['title'],
            'amount' => (float)$bill['amount'],
            'channel' => $channel,
            'transaction_id' => $trx_id,
            'paid_at' => date('Y-m-d H:i:s'),
            'wa_notification' => $wa_status
        ]
    ]);
} else {
    echo json_encode([
        'status' => 'pending',
        'message' => 'Status transaksi diterima: ' . $trx_status . '. Menunggu penyelesaian pembayaran.'
    ]);
}
