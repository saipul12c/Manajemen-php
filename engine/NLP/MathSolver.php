<?php

namespace Sentinel\NLP;

class MathSolver
{
    /**
     * Cek apakah pesan adalah pertanyaan matematika, kalkulasi, waktu, atau konversi
     */
    public static function isMathQuery(string $text): bool
    {
        $lower = mb_strtolower(trim($text), 'UTF-8');

        // Pertanyaan waktu / tanggal
        if (preg_match('/\b(jam berapa|pukul berapa|hari apa|tanggal berapa|sekarang jam|waktu sekarang)\b/i', $lower)) {
            return true;
        }

        // Kata kunci matematika eksplisit
        if (preg_match('/\b(hitung|kalkulasi|berapa hasil|berapakah hasil|hasil dari|akar dari|pangkat|persen|diskon|keliling|luas|kalikan|tambahkan|kurangi|kurangkan|bagikan)\b/i', $lower)) {
            return true;
        }

        // Pola ekspresi matematika angka dan operator, misalnya "25 * 4 + 10", "150 / 3", "(10 + 20) * 5"
        if (preg_match('/^[\d\s\+\-\*\/\^\(\)\.\,\%]+$/', $lower) && preg_match('/[\+\-\*\/\^]/', $lower)) {
            return true;
        }

        // Pola "X + Y", "X * Y", "X / Y" dalam kalimat
        if (preg_match('/\d+\s*[\+\-\*\/xX\^]\s*\d+/', $lower)) {
            return true;
        }

        return false;
    }

    /**
     * Selesaikan pertanyaan matematika atau waktu dan kembalikan jawaban terstruktur
     */
    public static function solve(string $text): ?array
    {
        $lower = mb_strtolower(trim($text), 'UTF-8');

        // 1. Cek pertanyaan waktu & tanggal
        $timeResult = self::solveTimeQuery($lower);
        if ($timeResult !== null) {
            return $timeResult;
        }

        // 2. Cek pertanyaan persentase
        $percentResult = self::solvePercentage($lower);
        if ($percentResult !== null) {
            return $percentResult;
        }

        // 3. Cek geometri sederhana (luas / keliling)
        $geomResult = self::solveGeometry($lower);
        if ($geomResult !== null) {
            return $geomResult;
        }

        // 4. Cek ekspresi aritmatika
        $arithResult = self::solveArithmetic($lower);
        if ($arithResult !== null) {
            return $arithResult;
        }

        return null;
    }

    /**
     * Selesaikan pertanyaan terkait jam dan kalender
     */
    protected static function solveTimeQuery(string $text): ?array
    {
        date_default_timezone_set('Asia/Jakarta');

        $days = [
            'Sunday' => 'Minggu', 'Monday' => 'Senin', 'Tuesday' => 'Selasa',
            'Wednesday' => 'Rabu', 'Thursday' => 'Kamis', 'Friday' => 'Jumat', 'Saturday' => 'Sabtu'
        ];
        $months = [
            'January' => 'Januari', 'February' => 'Februari', 'March' => 'Maret',
            'April' => 'April', 'May' => 'Mei', 'June' => 'Juni',
            'July' => 'Juli', 'August' => 'Agustus', 'September' => 'September',
            'October' => 'Oktober', 'November' => 'November', 'December' => 'Desember'
        ];

        $currentDay = $days[date('l')] ?? date('l');
        $currentMonth = $months[date('F')] ?? date('F');
        $currentDate = date('j') . ' ' . $currentMonth . ' ' . date('Y');
        $currentTime = date('H:i:s') . ' WIB';

        if (preg_match('/\b(jam berapa|pukul berapa|sekarang jam|waktu sekarang)\b/i', $text)) {
            return [
                'thought' => "Pengguna menanyakan waktu saat ini. Sistem membaca waktu sistem lokal (WIB).",
                'reply'   => "🕒 Saat ini menunjukkan pukul **{$currentTime}** (Waktu Indonesia Barat).",
                'type'    => 'time_query'
            ];
        }

        if (preg_match('/\b(hari apa|hari ini apa)\b/i', $text)) {
            return [
                'thought' => "Pengguna menanyakan nama hari saat ini.",
                'reply'   => "📅 Hari ini adalah hari **{$currentDay}**, tanggal **{$currentDate}**.",
                'type'    => 'day_query'
            ];
        }

        if (preg_match('/\b(tanggal berapa|hari ini tanggal|sekarang tanggal)\b/i', $text)) {
            return [
                'thought' => "Pengguna menanyakan tanggal hari ini.",
                'reply'   => "📆 Sekarang adalah tanggal **{$currentDate}** ({$currentDay}).",
                'type'    => 'date_query'
            ];
        }

        return null;
    }

    /**
     * Hitung persentase: "15% dari 250000", "diskon 20% dari 500000"
     */
    protected static function solvePercentage(string $text): ?array
    {
        // Misal: "15% dari 250000" atau "15 persen dari 250.000"
        if (preg_match('/(\d+(?:[\.,]\d+)?)\s*(?:%|persen)\s*dari\s*(?:rp\.?\s*)?([\d\.,]+)/i', $text, $matches)) {
            $p = (float)str_replace(',', '.', $matches[1]);
            $numClean = preg_replace('/[^\d]/', '', $matches[2]);
            $base = (float)$numClean;

            if ($base > 0) {
                $result = ($p / 100) * $base;
                $formattedResult = number_format($result, 2, ',', '.');
                $formattedBase = number_format($base, 0, ',', '.');

                return [
                    'thought' => "Menghitung persentase: {$p}% dari {$formattedBase}. Rumus: ({$p} / 100) * {$base} = {$result}.",
                    'reply'   => "🧮 **Hasil Perhitungan Persentase:**\n\n" .
                                 "- **Persentase:** {$p}%\n" .
                                 "- **Nilai Dasar:** {$formattedBase}\n" .
                                 "- **Rumus:** `({$p} / 100) × {$formattedBase}`\n" .
                                 "- **Hasil Akhir:** **{$formattedResult}**",
                    'type'    => 'percentage'
                ];
            }
        }

        // Misal: "diskon 20% dari 500000" -> hitung potongan dan harga akhir
        if (preg_match('/diskon\s*(\d+(?:[\.,]\d+)?)\s*(?:%|persen)\s*dari\s*(?:rp\.?\s*)?([\d\.,]+)/i', $text, $matches)) {
            $discount = (float)str_replace(',', '.', $matches[1]);
            $price = (float)preg_replace('/[^\d]/', '', $matches[2]);

            if ($price > 0) {
                $discountAmount = ($discount / 100) * $price;
                $finalPrice = $price - $discountAmount;

                return [
                    'thought' => "Menghitung diskon {$discount}% dari Rp " . number_format($price, 0, ',', '.') . ". Potongan = {$discountAmount}, Total bayar = {$finalPrice}.",
                    'reply'   => "🏷️ **Kalkulasi Diskon:**\n\n" .
                                 "- **Harga Asli:** Rp " . number_format($price, 0, ',', '.') . "\n" .
                                 "- **Besar Diskon:** {$discount}% (Hemat Rp " . number_format($discountAmount, 0, ',', '.') . ")\n" .
                                 "- **Harga yang Harus Dibayar:** **Rp " . number_format($finalPrice, 0, ',', '.') . "**",
                    'type'    => 'discount'
                ];
            }
        }

        return null;
    }

    /**
     * Hitung geometri dasar: luas lingkaran, segitiga, persegi panjang
     */
    protected static function solveGeometry(string $text): ?array
    {
        // Luas lingkaran r = ...
        if (preg_match('/luas\s+lingkaran.*?(?:r|jari[\s\-]*jari|radius)\s*(?:=|adalah)?\s*(\d+(?:[\.,]\d+)?)/i', $text, $m)) {
            $r = (float)str_replace(',', '.', $m[1]);
            $area = pi() * ($r ** 2);
            $formatted = number_format($area, 2, ',', '.');
            return [
                'thought' => "Menghitung luas lingkaran dengan jari-jari r = {$r}. Rumus: π × r².",
                'reply'   => "📐 **Luas Lingkaran:**\n\n" .
                             "- **Jari-jari (r):** {$r}\n" .
                             "- **Rumus:** `L = π × r² = 3.14159 × {$r}²`\n" .
                             "- **Hasil:** **{$formatted} satuan luas**",
                'type'    => 'geometry'
            ];
        }

        // Luas segitiga alas ... tinggi ...
        if (preg_match('/luas\s+segitiga.*?alas\s*(?:=|adalah)?\s*(\d+(?:[\.,]\d+)?).*?tinggi\s*(?:=|adalah)?\s*(\d+(?:[\.,]\d+)?)/i', $text, $m)) {
            $a = (float)str_replace(',', '.', $m[1]);
            $t = (float)str_replace(',', '.', $m[2]);
            $area = 0.5 * $a * $t;
            $formatted = number_format($area, 2, ',', '.');
            return [
                'thought' => "Menghitung luas segitiga: alas = {$a}, tinggi = {$t}. Rumus: 0.5 × a × t.",
                'reply'   => "📐 **Luas Segitiga:**\n\n" .
                             "- **Alas:** {$a}, **Tinggi:** {$t}\n" .
                             "- **Rumus:** `L = 1/2 × alas × tinggi = 0.5 × {$a} × {$t}`\n" .
                             "- **Hasil:** **{$formatted} satuan luas**",
                'type'    => 'geometry'
            ];
        }

        return null;
    }

    /**
     * Parser & Evaluator ekspresi matematika aman
     */
    protected static function solveArithmetic(string $text): ?array
    {
        // Bersihkan teks untuk mengambil ekspresi matematika
        $expr = $text;

        // Hilangkan kata awalan seperti "hitung", "berapa hasil dari", "berapakah", "akar", dll
        $expr = preg_replace('/^(?:tolong\s+)?(?:hitung|kalkulasi|berapa(?:kah)?\s+(?:hasil(?:nya)?)?(?:\s+dari)?)\s*/iu', '', $expr);
        $expr = trim($expr, " ?.,;:\t\n\r\0\x0B");

        // Pola verbal: "kalikan A dengan B", "tambahkan A dengan B", "kurangi A dengan B", "bagi A dengan B"
        $expr = preg_replace('/\bkalikan\s+(\d+(?:[\.,]\d+)?)\s+(?:dengan\s+)?(\d+(?:[\.,]\d+)?)\b/ui', '$1 * $2', $expr);
        $expr = preg_replace('/\btambahkan\s+(\d+(?:[\.,]\d+)?)\s+(?:dengan|ke)?\s*(\d+(?:[\.,]\d+)?)\b/ui', '$1 + $2', $expr);
        $expr = preg_replace('/\bkurangi\s+(\d+(?:[\.,]\d+)?)\s+(?:dengan\s+)?(\d+(?:[\.,]\d+)?)\b/ui', '$1 - $2', $expr);
        $expr = preg_replace('/\bbagikan?\s+(\d+(?:[\.,]\d+)?)\s+(?:dengan\s+)?(\d+(?:[\.,]\d+)?)\b/ui', '$1 / $2', $expr);

        // Ganti kata-kata operator bahasa Indonesia / simbol
        $expr = preg_replace('/\b(?:kali|dikalikan|kalikan)\b/iu', '*', $expr);
        $expr = preg_replace('/\b(?:bagi|dibagi|bagikan)\b/iu', '/', $expr);
        $expr = preg_replace('/\b(?:tambah|ditambah|tambahkan)\b/iu', '+', $expr);
        $expr = preg_replace('/\b(?:kurang|dikurangi|kurangi|kurangkan)\b/iu', '-', $expr);
        $expr = preg_replace('/\b(?:dengan)\b/iu', ' ', $expr);
        $expr = preg_replace('/\b(?:pangkat)\b/iu', '^', $expr);
        $expr = preg_replace('/\s*[xX]\s*/', '*', $expr); // 5 x 4 -> 5 * 4

        // Deteksi "akar 81" atau "akar dari 81" atau "sqrt(81)"
        if (preg_match('/(?:akar|sqrt)(?:\s+dari)?\s*(\d+(?:[\.,]\d+)?)/iu', $expr, $m)) {
            $val = (float)str_replace(',', '.', $m[1]);
            if ($val < 0) {
                return [
                    'thought' => "Mencoba menghitung akar kuadrat dari bilangan negatif.",
                    'reply'   => "Akar kuadrat dari bilangan negatif menghasilkan bilangan imajiner (√{$val} = " . sqrt(abs($val)) . "i).",
                    'type'    => 'math'
                ];
            }
            $ans = sqrt($val);
            return [
                'thought' => "Menghitung akar kuadrat dari {$val}: √{$val} = {$ans}.",
                'reply'   => "🧮 **Hasil Akar Kuadrat:**\n\n`√{$val} = " . (round($ans, 6)) . "`",
                'type'    => 'math'
            ];
        }

        // Pastikan hanya karakter aman matematika yang tersisa: 0-9, +, -, *, /, ^, (, ), ., spasi
        $sanitized = preg_replace('/[^\d\+\-\*\/\^\(\)\.\s]/', '', $expr);
        $sanitized = trim($sanitized);

        if (empty($sanitized) || !preg_match('/[\+\-\*\/\^]/', $sanitized) || !preg_match('/\d/', $sanitized)) {
            return null;
        }

        // Evaluasi aman menggunakan parser infix / Shunting-yard algorithm
        try {
            $result = self::evaluateMathExpression($sanitized);
            if ($result === null || is_nan($result) || is_infinite($result)) {
                return null;
            }

            if (fmod($result, 1) !== 0.0) {
                $formattedResult = number_format($result, 4, ',', '.');
                $cleanResult = rtrim(rtrim($formattedResult, '0'), ',');
            } else {
                $cleanResult = number_format($result, 0, ',', '.');
            }

            return [
                'thought' => "Menemukan ekspresi aritmatika: `{$sanitized}`. Evaluasi hierarki operator (* / sebelum + -) menghasilkan {$result}.",
                'reply'   => "🧮 **Hasil Perhitungan:**\n\n" .
                             '$$\text{Operasi: } ' . $sanitized . ' = \mathbf{' . $cleanResult . "}$$\n\n" .
                             "- **Ekspresi:** `{$sanitized}`\n" .
                             "- **Hasil Akhir:** **{$cleanResult}**",
                'type'    => 'arithmetic'
            ];
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Evaluator ekspresi matematika murni tanpa eval()
     */
    protected static function evaluateMathExpression(string $expr): ?float
    {
        // Tokenisasi ekspresi
        $tokens = [];
        $length = strlen($expr);
        $numBuffer = '';

        for ($i = 0; $i < $length; $i++) {
            $ch = $expr[$i];

            if ($ch === ' ' || $ch === "\t") {
                if ($numBuffer === '-') {
                    continue; // pertahankan tanda minus menunggu digit
                }
                if ($numBuffer !== '') {
                    $tokens[] = (float)$numBuffer;
                    $numBuffer = '';
                }
                continue;
            }

            if (ctype_digit($ch) || $ch === '.') {
                $numBuffer .= $ch;
            } else {
                if ($numBuffer !== '' && $numBuffer !== '-') {
                    $tokens[] = (float)$numBuffer;
                    $numBuffer = '';
                }

                if (in_array($ch, ['+', '-', '*', '/', '^', '(', ')'], true)) {
                    // Deteksi angka negatif (misal "-5" atau "( - 3 )")
                    if ($ch === '-' && (empty($tokens) || end($tokens) === '(' || in_array(end($tokens), ['+', '-', '*', '/', '^'], true))) {
                        $numBuffer = '-';
                        continue;
                    }
                    $tokens[] = $ch;
                }
            }
        }

        if ($numBuffer !== '' && $numBuffer !== '-') {
            $tokens[] = (float)$numBuffer;
        }

        if (empty($tokens)) {
            return null;
        }

        // Shunting-yard algorithm untuk konversi Infix ke Postfix (RPN)
        $outputQueue = [];
        $operatorStack = [];
        $precedence = ['+' => 1, '-' => 1, '*' => 2, '/' => 2, '^' => 3];

        foreach ($tokens as $token) {
            if (is_numeric($token)) {
                $outputQueue[] = (float)$token;
            } elseif ($token === '(') {
                $operatorStack[] = $token;
            } elseif ($token === ')') {
                while (!empty($operatorStack) && end($operatorStack) !== '(') {
                    $outputQueue[] = array_pop($operatorStack);
                }
                if (empty($operatorStack)) {
                    return null; // Tanda kurung tutup tanpa pasangan
                }
                array_pop($operatorStack); // Buang '('
            } elseif (isset($precedence[$token])) {
                $isRightAssoc = ($token === '^');
                while (!empty($operatorStack)) {
                    $top = end($operatorStack);
                    if ($top !== '(' && isset($precedence[$top])) {
                        $shouldPop = $isRightAssoc
                            ? ($precedence[$top] > $precedence[$token])
                            : ($precedence[$top] >= $precedence[$token]);

                        if ($shouldPop) {
                            $outputQueue[] = array_pop($operatorStack);
                            continue;
                        }
                    }
                    break;
                }
                $operatorStack[] = $token;
            }
        }

        while (!empty($operatorStack)) {
            $op = array_pop($operatorStack);
            if ($op === '(' || $op === ')') {
                return null; // Tanda kurung buka tidak pernah ditutup
            }
            $outputQueue[] = $op;
        }

        // Evaluasi RPN (Reverse Polish Notation)
        $evalStack = [];
        foreach ($outputQueue as $token) {
            if (is_numeric($token)) {
                $evalStack[] = $token;
            } else {
                if (count($evalStack) < 2) {
                    return null;
                }
                $b = array_pop($evalStack);
                $a = array_pop($evalStack);

                switch ($token) {
                    case '+': $evalStack[] = $a + $b; break;
                    case '-': $evalStack[] = $a - $b; break;
                    case '*': $evalStack[] = $a * $b; break;
                    case '/':
                        if ($b == 0) return null; // Pembagian dengan 0
                        $evalStack[] = $a / $b;
                        break;
                    case '^':
                        if (abs($b) > 200) return null;
                        $powVal = pow($a, $b);
                        if (is_nan($powVal) || is_infinite($powVal)) return null;
                        $evalStack[] = $powVal;
                        break;
                }
            }
        }

        return count($evalStack) === 1 ? (float)$evalStack[0] : null;
    }
}
