<?php

namespace Sentinel\NLP;

class LogicPuzzleSolver
{
    /**
     * Bank Teka-Teki Logika (Riddles & Brain Teasers)
     */
    protected static array $riddles = [
        [
            'keywords'   => ['naik', 'turun'],
            'question'   => 'Apa yang selalu naik tapi tidak pernah turun?',
            'answer'     => 'Umur (atau Usia)',
            'explanation'=> 'Seiring berjalannya waktu, umur seseorang akan selalu bertambah (naik) dan secara alami tidak akan pernah berkurang (turun).'
        ],
        [
            'keywords'   => ['gigi', 'menggigit', 'makan'],
            'question'   => 'Punya banyak gigi tapi tidak bisa menggigit atau makan?',
            'answer'     => 'Sisir (atau Gergaji)',
            'explanation'=> 'Sisir dan gergaji memiliki gerigi tajam, namun merupakan benda mati sehingga tidak memiliki mulut atau kemampuan mengunyah makanan.'
        ],
        [
            'keywords'   => ['basah', 'mengeringkan', 'kering'],
            'question'   => 'Benda apa yang justru semakin basah ketika ia sedang mengeringkan sesuatu?',
            'answer'     => 'Handuk',
            'explanation'=> 'Handuk menyerap cairan dari tubuh atau benda lain agar kering, sehingga air berpindah dan membuat handuk itu sendiri menjadi basah.'
        ],
        [
            'keywords'   => ['pecah', 'disentuh', 'janji'],
            'question'   => 'Apa yang bisa dipecahkan atau dirusak tanpa perlu disentuh sedikitpun?',
            'answer'     => 'Janji (atau Kepercayaan / Rekor / Kesunyian)',
            'explanation'=> 'Janji atau kepercayaan adalah komitmen abstrak. Mengingkari janji berarti "memecahkannya" secara metaforis tanpa ada kontak fisik.'
        ],
        [
            'keywords'   => ['potong', 'panjang', 'celana'],
            'question'   => 'Benda apa yang kalau dipotong malah bertambah panjang?',
            'answer'     => 'Kain celana panjang yang dipotong jadi celana pendek (atau Lubang / Parit)',
            'explanation'=> 'Jika kita memotong parit/lubang, ukurannya semakin memanjang/besar. Pada celana, memotong bagian bawah menciptakan sisa kain atau "celana pendek" baru.'
        ],
        [
            'keywords'   => ['leher', 'kepala'],
            'question'   => 'Punya leher tapi tidak punya kepala?',
            'answer'     => 'Botol (atau Kemeja / Baju)',
            'explanation'=> 'Bagian atas botol yang menyempit disebut "leher botol", dan bagian kerah kemeja melingkari leher, tetapi keduanya tidak memiliki kepala.'
        ],
        [
            'keywords'   => ['mata', 'melihat'],
            'question'   => 'Punya mata tapi sama sekali tidak bisa melihat?',
            'answer'     => 'Jarum jahit (atau Badai / Kentang)',
            'explanation'=> 'Lubang kecil tempat memasukkan benang pada jarum disebut "mata jarum" (needle\'s eye), namun bukan organ penglihatan.'
        ],
        [
            'keywords'   => ['daun', 'pohon', 'punggung', 'buku'],
            'question'   => 'Punya banyak daun tapi bukan pohon, punya punggung tapi tidak bertulang?',
            'answer'     => 'Buku',
            'explanation'=> 'Lembaran kertas dalam buku disebut helaian/daun buku, dan bagian jilid perekat samping disebut punggung buku.'
        ],
        [
            'keywords'   => ['depan', 'dilihat', 'masa depan'],
            'question'   => 'Selalu ada di depan mata Anda tapi tidak pernah bisa dilihat secara langsung?',
            'answer'     => 'Masa Depan',
            'explanation'=> 'Masa depan selalu terbentang di hadapan kita, tetapi tidak berwujud fisik dan belum terjadi sehingga tidak dapat ditangkap mata telanjang.'
        ],
        [
            'keywords'   => ['dimasukkan', 'ditarik', 'keluar', 'masuk'],
            'question'   => 'Bila dimasukkan ia keluar, bila ditarik ia masuk?',
            'answer'     => 'Kancing Baju',
            'explanation'=> 'Saat memasang kancing, kita memasukkannya ke lubang kain hingga muncul di sisi luar. Saat melepas kancing, kita menariknya kembali masuk ke dalam.'
        ],
        [
            'keywords'   => ['menangis', 'habis', 'lilin'],
            'question'   => 'Semakin tua semakin pendek dan menangis mengeluarkan air mata hingga habis?',
            'answer'     => 'Lilin yang menyala',
            'explanation'=> 'Saat lilin menyala, tetesan lelehan lilin menyerupai air mata, dan batangnya semakin memendek seiring waktu sampai habis terbakar.'
        ],
        [
            'keywords'   => ['injak', 'sepatu', 'sandal'],
            'question'   => 'Selalu diinjak ke mana-mana tapi tidak pernah mengeluh atau marah?',
            'answer'     => 'Sepatu (atau Sandal / Lantai / Tangga)',
            'explanation'=> 'Alas kaki dirancang untuk diinjak sebagai pelindung telapak kaki saat melangkah ke berbagai tempat.'
        ],
        [
            'keywords'   => ['rumah', 'tongkat', 'payung'],
            'question'   => 'Kalau dibuka jadi rumah atau atap pelindung, kalau ditutup jadi tongkat?',
            'answer'     => 'Payung',
            'explanation'=> 'Ketika mengembang, payung membentuk kanopi menyerupai atap rumah peneduh. Saat dikuncupkan, bentuknya panjang silindris seperti tongkat pegangan.'
        ],
        [
            'keywords'   => ['bayangan', 'mengikuti', 'gelap'],
            'question'   => 'Selalu mengikuti langkah Anda di bawah cahaya tetapi menghilang seketika saat gelap gulita?',
            'answer'     => 'Bayangan',
            'explanation'=> 'Bayangan terbentuk akibat tubuh menghalangi pancaran cahaya. Tanpa adanya sumber cahaya (dalam gelap), bayangan tidak dapat terbentuk.'
        ]
    ];

    /**
     * Deteksi apakah kueri adalah soal logika, silogisme, deret, atau teka-teki
     */
    public static function isLogicQuery(string $text): bool
    {
        $lower = mb_strtolower(trim($text), 'UTF-8');

        // Pola relasional: "siapa yang lebih [tua/muda/tinggi/pendek/cepat]..."
        if (preg_match('/\b(siapa|mana)\s+(yang\s+)?(paling|lebih)\s+(tua|muda|tinggi|pendek|cepat|lambat|besar|kecil|berat|ringan)\b/iu', $lower)) {
            return true;
        }

        // Pernyataan komparasi berantai: "A lebih ... dari B ... B lebih ... dari C"
        if (preg_match_all('/\blebih\s+(tua|muda|tinggi|pendek|cepat|lambat|besar|kecil|berat|ringan)\s+dari\b/iu', $lower) >= 1) {
            return true;
        }

        // Silogisme deduktif: "Semua ... adalah ... Apakah ... adalah ..."
        if (preg_match('/\bsemua\b/iu', $lower) && preg_match('/\badalah\b/iu', $lower) && (preg_match('/\bapakah\b/iu', $lower) || preg_match('/\bmaka\b/iu', $lower))) {
            return true;
        }

        // Deret angka atau huruf: "2, 4, 8, 16" atau "deret angka"
        if (preg_match('/\b(deret|pola\s+angka|angka\s+selanjutnya|urutan\s+angka)\b/iu', $lower) || preg_match('/\b\d+\s*,\s*\d+\s*,\s*\d+\s*,\s*\d+/u', $lower)) {
            return true;
        }

        // Teka-teki atau tebak-tebakan eksplisit
        if (preg_match('/\b(teka-teki|tebak-tebakan|tebakan|asah\s+otak|riddle)\b/iu', $lower)) {
            return true;
        }

        // Cek pertanyaan teka-teki spesifik HANYA jika berupa kalimat tanya / metaforis teka-teki
        $isQuestionFraming = (bool) preg_match('/\b(apa\s+yang|benda\s+apa|siapa\s+yang|apaan\s+yang|ada\s+apa|punya|selalu\s+ada|makin|semakin|\?)\b/iu', $lower);
        $isNonRiddleDomain = (bool) preg_match('/\b(saham|bursa|pasar|investasi|harga|bus|stasiun|halte|penumpang|naikkan|turunkan|kurs|inflasi)\b/iu', $lower);

        if ($isQuestionFraming && !$isNonRiddleDomain) {
            foreach (self::$riddles as $riddle) {
                $matchedCount = 0;
                foreach ($riddle['keywords'] as $kw) {
                    if (str_contains($lower, $kw)) {
                        $matchedCount++;
                    }
                }
                $threshold = max(2, (int)ceil(count($riddle['keywords']) * 0.6));
                if ($matchedCount >= $threshold) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Memecahkan masalah logika, teka-teki, relasi, atau deret
     */
    public static function solve(string $query): ?array
    {
        $lower = mb_strtolower(trim($query), 'UTF-8');

        // 1. Cek Teka-Teki Logika Spesifik
        $riddleResult = self::solveRiddle($lower);
        if ($riddleResult !== null) {
            return $riddleResult;
        }

        // 2. Permintaan Mendapatkan Teka-Teki Baru ("beri saya teka-teki", "uji saya...")
        if (preg_match('/\b(beri|kasih|minta|coba|ajukan)\s+(saya\s+)?(teka-teki|tebak-tebakan)\b/iu', $lower) || preg_match('/\b(uji\s+logika|asah\s+otak)\b/iu', $lower)) {
            $randomRiddle = self::$riddles[array_rand(self::$riddles)];
            $thought = "Pengguna meminta tantangan asah otak / teka-teki logika. Memilih satu teka-teki dari bank data untuk menguji logika pengguna.";
            $reply = "🧩 **Tantangan Asah Otak / Teka-Teki Logika:**\n\n" .
                     "\"**{$randomRiddle['question']}**\"\n\n" .
                     "💡 *Coba tebak jawabannya! Jika Anda menyerah atau ingin tahu jawabannya, katakan saja: \"apa jawaban teka-teki tadi?\"*";

            return [
                'type'       => 'logic_riddle_prompt',
                'reply'      => $reply,
                'thought'    => $thought,
                'confidence' => 1.0
            ];
        }

        // 3. Pemecahan Deret Angka & Huruf
        $seqResult = self::solveSequence($query);
        if ($seqResult !== null) {
            return $seqResult;
        }

        // 4. Pemecahan Logika Relasional (Perbandingan & Pengurutan Berantai)
        $relResult = self::solveRelational($query);
        if ($relResult !== null) {
            return $relResult;
        }

        // 5. Silogisme Deduktif (Modus Ponens)
        $sylResult = self::solveSyllogism($query);
        if ($sylResult !== null) {
            return $sylResult;
        }

        return null;
    }

    /**
     * Mencari teka-teki yang cocok dari bank teka-teki
     */
    protected static function solveRiddle(string $lower): ?array
    {
        $isQuestionFraming = (bool) preg_match('/\b(apa\s+yang|benda\s+apa|siapa\s+yang|apaan\s+yang|ada\s+apa|punya|selalu\s+ada|makin|semakin|\?|teka-teki|tebakan)\b/iu', $lower);
        $isNonRiddleDomain = (bool) preg_match('/\b(saham|bursa|pasar|investasi|harga|bus|stasiun|halte|penumpang|naikkan|turunkan|kurs|inflasi)\b/iu', $lower);

        if (!$isQuestionFraming || $isNonRiddleDomain) {
            return null;
        }

        $bestRiddle = null;
        $bestMatchCount = 0;

        foreach (self::$riddles as $riddle) {
            $matchCount = 0;
            foreach ($riddle['keywords'] as $kw) {
                if (str_contains($lower, $kw)) {
                    $matchCount++;
                }
            }

            $threshold = max(2, (int)ceil(count($riddle['keywords']) * 0.6));
            if ($matchCount >= $threshold && $matchCount > $bestMatchCount) {
                $bestMatchCount = $matchCount;
                $bestRiddle = $riddle;
            }
        }

        if ($bestRiddle !== null) {
            $thought = "Mengenali teka-teki logika klasik tentang '{$bestRiddle['answer']}'. Menganalisis permainan kata, metafora semantik, dan membuktikan keabsahan kesimpulannya.";
            $reply = "🧩 **Jawaban Teka-Teki Logika:**\n\n" .
                     "Jawabannya adalah: **{$bestRiddle['answer']}**! 🎉\n\n" .
                     "💡 **Penjelasan Logika:**\n" .
                     "{$bestRiddle['explanation']}";

            return [
                'type'       => 'logic_riddle',
                'reply'      => $reply,
                'thought'    => $thought,
                'confidence' => 0.98
            ];
        }

        return null;
    }

    /**
     * Memecahkan Deret Angka (Aritmatika, Geometri, Fibonacci, Kuadrat)
     */
    protected static function solveSequence(string $text): ?array
    {
        // Ekstrak angka-angka berturutan
        if (preg_match_all('/-?\b\d+\b/', $text, $matches)) {
            $nums = array_map('intval', $matches[0]);
            if (count($nums) >= 3) {
                $n = count($nums);

                // Cek Aritmatika (Selisih Konstan)
                $diff = $nums[1] - $nums[0];
                $isArithmetic = true;
                for ($i = 1; $i < $n - 1; $i++) {
                    if (($nums[$i + 1] - $nums[$i]) !== $diff) {
                        $isArithmetic = false;
                        break;
                    }
                }
                if ($isArithmetic) {
                    $lastNum = $nums[$n - 1];
                    $nextVal = $lastNum + $diff;
                    $thought = "Mendeteksi deret aritmatika dengan selisih konstan {$diff} di antara setiap suku ({$nums[0]}, {$nums[1]}, ..., {$lastNum}).";
                    $reply = "🔢 **Analisis Deret Angka (Aritmatika):**\n\n" .
                             "- **Deret masukan:** " . implode(', ', $nums) . "\n" .
                             "- **Pola selisih:** Menambah (" . ($diff >= 0 ? "+$diff" : "$diff") . ") setiap langkah.\n" .
                             "- **Rumus suku ke-n:** `U_n = {$nums[0]} + (n - 1) * {$diff}`\n\n" .
                             "👉 **Angka berikutnya adalah: " . $nextVal . "**";

                    return ['type' => 'logic_sequence_arithmetic', 'reply' => $reply, 'thought' => $thought, 'confidence' => 1.0];
                }

                // Cek Geometri (Rasio Konstan)
                if ($nums[0] !== 0 && $nums[1] % $nums[0] === 0) {
                    $ratio = $nums[1] / $nums[0];
                    $isGeometric = true;
                    for ($i = 1; $i < $n - 1; $i++) {
                        if ($nums[$i] === 0 || ($nums[$i + 1] / $nums[$i]) != $ratio) {
                            $isGeometric = false;
                            break;
                        }
                    }
                    if ($isGeometric) {
                        $lastNum = $nums[$n - 1];
                        $nextVal = $lastNum * $ratio;
                        $thought = "Mendeteksi deret geometri dengan rasio perkalian {$ratio} di setiap langkah ({$nums[0]}, {$nums[1]}, ..., {$lastNum}).";
                        $reply = "🔢 **Analisis Deret Angka (Geometri):**\n\n" .
                                 "- **Deret masukan:** " . implode(', ', $nums) . "\n" .
                                 "- **Pola rasio:** Dikalikan (" . $ratio . ") setiap langkah.\n" .
                                 "- **Rumus suku ke-n:** `U_n = {$nums[0]} * ({$ratio})^(n - 1)`\n\n" .
                                 "👉 **Angka berikutnya adalah: " . $nextVal . "**";

                        return ['type' => 'logic_sequence_geometric', 'reply' => $reply, 'thought' => $thought, 'confidence' => 1.0];
                    }
                }

                // Cek Fibonacci (U_n = U_{n-1} + U_{n-2})
                $isFibo = true;
                for ($i = 2; $i < $n; $i++) {
                    if ($nums[$i] !== ($nums[$i - 1] + $nums[$i - 2])) {
                        $isFibo = false;
                        break;
                    }
                }
                if ($isFibo) {
                    $prevA = $nums[$n - 2];
                    $prevB = $nums[$n - 1];
                    $nextVal = $prevA + $prevB;
                    $thought = "Mendeteksi deret Fibonacci di mana setiap suku merupakan penjumlahan dari dua suku sebelumnya ({$prevA} + {$prevB}).";
                    $reply = "🔢 **Analisis Deret Angka (Fibonacci):**\n\n" .
                             "- **Deret masukan:** " . implode(', ', $nums) . "\n" .
                             "- **Pola relasi:** `U_n = U_{n-1} + U_{n-2}` ({$prevA} + {$prevB})\n\n" .
                             "👉 **Angka berikutnya adalah: " . $nextVal . "**";

                    return ['type' => 'logic_sequence_fibonacci', 'reply' => $reply, 'thought' => $thought, 'confidence' => 1.0];
                }
            }
        }

        return null;
    }

    /**
     * Memecahkan Logika Relasional Komparatif (Misal: "A lebih tinggi dari B, B lebih tinggi dari C. Siapa paling tinggi?")
     */
    protected static function solveRelational(string $text): ?array
    {
        // Cari klausa "X lebih [relasi] dari Y"
        $pattern = '/([A-Z][a-zA-Z0-9_\-]*|[a-zA-Z]+)\s+lebih\s+(tua|muda|tinggi|pendek|cepat|lambat|besar|kecil|berat|ringan)\s+dari\s+([A-Z][a-zA-Z0-9_\-]*|[a-zA-Z]+)/ui';

        if (preg_match_all($pattern, $text, $matches, PREG_SET_ORDER)) {
            if (empty($matches)) {
                return null;
            }

            $relations = [];
            $entities = [];
            $property = '';

            foreach ($matches as $m) {
                $subA = ucfirst(trim($m[1]));
                $prop = mb_strtolower(trim($m[2]));
                $subB = ucfirst(trim($m[3]));

                $property = $prop;
                $entities[$subA] = true;
                $entities[$subB] = true;

                // Normalisasi ke arah 'lebih besar/tua/tinggi/cepat/berat'
                $isDirect = in_array($prop, ['tua', 'tinggi', 'cepat', 'besar', 'berat'], true);
                if ($isDirect) {
                    $relations[] = ['greater' => $subA, 'lesser' => $subB, 'prop' => $prop];
                } else {
                    // kebalikannya (muda, pendek, lambat, kecil, ringan)
                    $relations[] = ['greater' => $subB, 'lesser' => $subA, 'prop' => $prop];
                }
            }

            $entityList = array_keys($entities);
            if (count($entityList) < 2) {
                return null;
            }

            // Hitung derajat in-degree / out-degree
            $scores = array_fill_keys($entityList, 0);
            foreach ($relations as $rel) {
                $scores[$rel['greater']] += 1;
                $scores[$rel['lesser']] -= 1;
            }

            arsort($scores);
            $sortedEntities = array_keys($scores);

            $highest = $sortedEntities[0];
            $lowest = $sortedEntities[count($sortedEntities) - 1];

            // Tentukan apa yang ditanyakan
            $asksFor = 'highest';
            if (preg_match('/\b(paling|lebih)\s+(muda|pendek|lambat|kecil|ringan)\b/iu', $text)) {
                $asksFor = 'lowest';
            }

            $directLabelMap = [
                'tua' => ['max' => 'Paling Tua', 'min' => 'Paling Muda'],
                'muda' => ['max' => 'Paling Tua', 'min' => 'Paling Muda'],
                'tinggi' => ['max' => 'Paling Tinggi', 'min' => 'Paling Pendek'],
                'pendek' => ['max' => 'Paling Tinggi', 'min' => 'Paling Pendek'],
                'cepat' => ['max' => 'Paling Cepat', 'min' => 'Paling Lambat'],
                'lambat' => ['max' => 'Paling Cepat', 'min' => 'Paling Lambat'],
                'besar' => ['max' => 'Paling Besar', 'min' => 'Paling Kecil'],
                'kecil' => ['max' => 'Paling Besar', 'min' => 'Paling Kecil'],
                'berat' => ['max' => 'Paling Berat', 'min' => 'Paling Ringan'],
                'ringan' => ['max' => 'Paling Berat', 'min' => 'Paling Ringan']
            ];

            $labels = $directLabelMap[$property] ?? ['max' => 'Peringkat Teratas', 'min' => 'Peringkat Terbawah'];
            $targetAnswer = ($asksFor === 'lowest') ? $lowest : $highest;
            $targetLabel = ($asksFor === 'lowest') ? $labels['min'] : $labels['max'];

            $thought = "Menyusun graf transitivitas terarah untuk relasi '{$property}' antar subjek: " . json_encode($relations) . ". Menghitung urutan transitif: " . implode(' > ', $sortedEntities) . ".";
            $reply = "🧠 **Deduksi Logika Relasional:**\n\n" .
                     "Berdasarkan premis perbandingan yang Anda berikan:\n";

            foreach ($matches as $m) {
                $reply .= "- " . ucfirst($m[1]) . " lebih " . $m[2] . " dari " . ucfirst($m[3]) . "\n";
            }

            $reply .= "\n📊 **Urutan Deduktif (Dari " . $labels['max'] . " ke " . $labels['min'] . "):**\n" .
                      "**" . implode(' ➔ ', $sortedEntities) . "**\n\n" .
                      "👉 **Kesimpulan:** Yang **{$targetLabel}** adalah **{$targetAnswer}**.";

            return [
                'type'       => 'logic_relational',
                'reply'      => $reply,
                'thought'    => $thought,
                'confidence' => 0.98
            ];
        }

        return null;
    }

    /**
     * Memecahkan Silogisme Deduktif Kategori (Modus Ponens)
     * Contoh: "Semua manusia fana. Socrates adalah manusia. Apakah Socrates fana?"
     */
    protected static function solveSyllogism(string $text): ?array
    {
        // Premis Mayor: "Semua [A] [adalah/bersifat] [B]"
        if (preg_match('/semua\s+([a-zA-Z]+)\s+(?:adalah\s+)?([a-zA-Z]+)/ui', $text, $mayor)) {
            $catA = mb_strtolower(trim($mayor[1]));
            $propB = mb_strtolower(trim($mayor[2]));

            // Premis Minor: "[C] adalah [A]"
            if (preg_match('/([a-zA-Z]+)\s+adalah\s+' . preg_quote($catA, '/') . '/ui', $text, $minor)) {
                $subjC = ucfirst(trim($minor[1]));

                $thought = "Menganalisis silogisme kategoris klasik (Modus Ponens): Premis Mayor (Semua {$catA} adalah {$propB}), Premis Minor ({$subjC} adalah {$catA}) menghasilkan konklusi pasti bahwa {$subjC} adalah {$propB}.";
                $reply = "🏛️ **Penalaran Silogisme Deduktif (Modus Ponens):**\n\n" .
                         "1. **Premis Mayor:** Semua *{$catA}* adalah *{$propB}*.\n" .
                         "2. **Premis Minor:** *{$subjC}* adalah *{$catA}*.\n\n" .
                         "👉 **Konklusi Logis Pasti:**\n" .
                         "**Ya, benar! {$subjC} pasti {$propB}.**";

                return [
                    'type'       => 'logic_syllogism',
                    'reply'      => $reply,
                    'thought'    => $thought,
                    'confidence' => 1.0
                ];
            }
        }

        return null;
    }
}
