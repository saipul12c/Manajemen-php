<?php

namespace Sentinel\NLP;

/**
 * EpisodicSlicer - Mesin Pemecah Informasi Mikro, Penyaring Relevansi, dan Pembangkit Respons Baru
 * 
 * Bertanggung jawab membedah riwayat percakapan panjang dan wacana aktif menjadi
 * kepingan-kepingan atomik informasi (micro-propositions), menapis derau (noise & chitchat),
 * memberikan skor bobot relevansi matematis, serta meramu intisari makna menjadi
 * respons baru yang orisinal dan kontekstual.
 */
class EpisodicSlicer
{
    public const TYPE_FACT = 'fact';
    public const TYPE_CONSTRAINT = 'constraint';
    public const TYPE_PREFERENCE = 'preference';
    public const TYPE_ACTION_PLAN = 'action_plan';
    public const TYPE_OPINION = 'opinion';
    public const TYPE_CHITCHAT = 'chitchat';

    /**
     * Memeriksa apakah kueri pengguna meminta sintesis, perakitan, atau
     * penarikan benang merah dari potongan-potongan obrolan/fakta terdahulu.
     */
    public static function isEpisodicSynthesisQuery(string $text): bool
    {
        $clean = mb_strtolower(trim($text), 'UTF-8');

        $patterns = [
            '/(?:rangkum|ringkas|simpulkan|rekap|tarik\s+kesimpulan)\s+(?:obrolan|percakapan|riwayat|apa\s+yang\s+(?:tadi|kita)|diskusi|poin)/ui',
            '/(?:hubungkan|korelasikan|gabungkan|sintesiskan)\s+(?:fakta|informasi|hal\s+yang\s+(?:tadi|pernah)|obrolan|poin)/ui',
            '/(?:apa\s+(?:saja\s+)?poin\s+(?:penting|kunci|utama)|intisari)\s+(?:dari\s+)?(?:obrolan|percakapan|diskusi|pembicaraan)/ui',
            '/(?:apa\s+(?:saja\s+)?(?:batasan|syarat|kendala|rencana|preferensi|aturan)(?:\s+(?:dan|atau|,)\s+(?:batasan|syarat|kendala|rencana|preferensi|aturan))*)\s+(?:yang\s+(?:tadi|pernah|sudah|sempat)(?:\s+saya)?\s+(?:sebutkan|ceritakan|sampaikan|bahas))/ui',
            '/(?:berdasarkan\s+(?:apa\s+yang\s+(?:tadi|pernah\s+saya)\s+ceritakan|obrolan\s+kita\s+tadi|diskusi\s+kita\s+sebelumnya))/ui',
            '/(?:kumpulkan\s+(?:semua\s+)?informasi\s+(?:tentang|terkait)\s+[^\.\n]+)/ui'
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $clean)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Membersihkan respons asisten dari header markdown, dekorator UI, rumus LaTeX display,
     * dan footer meta sebelum dipecah menjadi kepingan proposisi memori episodik.
     * Mencegah Sentinel mengindeks header atau tata letak responsnya sendiri (Anti Self-Feedback Echo).
     */
    public static function sanitizeAssistantReply(string $text): string
    {
        $raw = trim($text);
        if (empty($raw)) {
            return '';
        }

        // 1. Buang blok kode (```...```) dan display math ($$...$$ atau \[...\])
        $cleaned = preg_replace('/```[\s\S]*?```/u', '', $raw);
        $cleaned = preg_replace('/\$\$[\s\S]*?\$\$/u', '', $cleaned);
        $cleaned = preg_replace('/\\\[[\s\S]*?\\\]/u', '', $cleaned);

        // 2. Buang baris heading markdown (# ..., ## ..., ### ...)
        $cleaned = preg_replace('/^\s*#{1,6}\s+.*$/mu', '', $cleaned);

        // 3. Buang banner dekorator UI Sentinel dan disclaimer footer
        $uiPatterns = [
            '/^.*🧠\s*\*\*Sintesis.*$/mu',
            '/^.*📌\s*\*\*Fakta.*$/mu',
            '/^.*🛑\s*\*\*Batasan.*$/mu',
            '/^.*💡\s*\*\*Preferensi.*$/mu',
            '/^.*🚀\s*\*\*Rencana.*$/mu',
            '/^.*💭\s*\*\*Sudut Pandang.*$/mu',
            '/^.*🤝\s*\*\*Pendampingan.*$/mu',
            '/^.*🛡️\s*\*\*Keseimbangan.*$/mu',
            '/^.*🧮\s*\*\*Hasil Perhitungan.*$/mu',
            '/^\s*\*\([^\)]*\)\*[\.\s]*$/mu',
            '/^\s*\(?\*[^\*]+\*\)?[\.\s]*$/mu',
            '/^.*(?:basa-basi|disaring ke latar belakang).*$/mu',
            '/^\s*💡\s*\*Ada sudut pandang.*\*\s*$/mu'
        ];
        foreach ($uiPatterns as $pat) {
            $cleaned = preg_replace($pat, '', $cleaned);
        }

        // 4. Bersihkan bullet points / simbol dekoratif di awal baris (•, ⚠️, ✨, ➡️, 💬, -, *, 1., dsb)
        $cleaned = preg_replace('/^\s*(?:[•\-\*]|⚠️|✨|➡️|💬|\d+\.)\s+/mu', '', $cleaned);

        // 5. Bersihkan baris kosong berlebih
        $cleaned = preg_replace("/[\r\n]+/", "\n", trim($cleaned));

        return $cleaned;
    }

    /**
     * Memotong teks percakapan tunggal menjadi kepingan-kepingan atomik mikro (Micro-Fragments).
     * Jika $activeEntity disertakan, melakukan pengikatan kata ganti (Anaphora Coreference Binding)
     * agar kepingan mikro tetap utuh secara semantik dan tidak menjadi kalimat yatim.
     */
    public static function sliceText(string $text, int $turnIndex = 0, string $role = 'user', ?string $activeEntity = null): array
    {
        $raw = trim($text);
        if (empty($raw)) {
            return [];
        }

        // Masking URL & Kutipan agar tanda baca internal tidak memecah sembarangan
        $masks = [];
        $maskIndex = 0;
        $maskedText = preg_replace_callback('/https?:\/\/[^\s]+/i', function ($m) use (&$masks, &$maskIndex) {
            $key = "__SENTINEL_URL_{$maskIndex}__";
            $masks[$key] = $m[0];
            $maskIndex++;
            return $key;
        }, $raw);

        $maskedText = preg_replace_callback('/([\'"“«])(.*?)(\1|[”»])/u', function ($m) use (&$masks, &$maskIndex) {
            $key = "__SENTINEL_QUOTE_{$maskIndex}__";
            $masks[$key] = $m[0];
            $maskIndex++;
            return $key;
        }, $maskedText);

        // 1. Pemecahan Berbasis Tanda Baca Kalimat (. ? ! ; \n)
        $rawSentences = preg_split('/(?<!\d)[.?!;\n]+(?!\d)/u', $maskedText, -1, PREG_SPLIT_NO_EMPTY);
        $fragments = [];
        $subIndex = 0;

        foreach ($rawSentences as $sentence) {
            $sentence = trim($sentence);
            if (empty($sentence)) {
                continue;
            }

            // 2. Pemecahan Sub-Klausa Berdasarkan Konjungsi Kontrastif & Aditif Majemuk
            $subClauses = preg_split('/\b(?:namun|tetapi|karena|sebab|selain\s+itu|di\s+samping\s+itu|sedangkan|padahal|lalu|kemudian|dan\s+juga)\b/ui', $sentence);

            foreach ($subClauses as $subClause) {
                // Restore masks
                foreach ($masks as $k => $v) {
                    $subClause = str_replace($k, $v, $subClause);
                }

                $subClause = trim($subClause);
                if (mb_strlen($subClause) < 3) {
                    continue;
                }

                // 3. Pengikatan Kata Ganti / Anaphora Coreference Binding
                $boundClause = $subClause;
                $boundSubject = null;
                if (!empty($activeEntity)) {
                    // Ikat frasa demonstrativa ("aplikasi ini", "sistem tersebut", "proyek ini")
                    $boundClause = preg_replace_callback('/\b(?:aplikasi|sistem|program|proyek|software|platform)\s+(?:ini|tersebut|itu)\b/ui', function ($m) use ($activeEntity) {
                        return "{$activeEntity} ({$m[0]})";
                    }, $boundClause);

                    // Ikat kata ganti persona ("dia", "ia") di awal atau posisi subjek
                    $boundClause = preg_replace_callback('/^(?:dia|ia)\b/ui', function ($m) use ($activeEntity) {
                        return "{$activeEntity} ({$m[0]})";
                    }, $boundClause);

                    if ($boundClause !== $subClause) {
                        $boundSubject = $activeEntity;
                    }
                }

                $type = self::classifyFragmentType($boundClause);
                $salienceWeight = self::calculateIntrinsicSalience($boundClause, $type);
                $subject = $boundSubject ?: self::extractSubject($boundClause);
                $tokens = TextProcessor::tokenize($boundClause, true);

                $fragments[] = [
                    'id'              => "frag_{$turnIndex}_{$subIndex}",
                    'text'            => $boundClause,
                    'type'            => $type,
                    'subject'         => $subject,
                    'tokens'          => $tokens,
                    'salience_weight' => $salienceWeight,
                    'turn_index'      => $turnIndex,
                    'role'            => $role,
                    'timestamp'       => time()
                ];
                $subIndex++;
            }
        }

        return $fragments;
    }

    /**
     * Memotong seluruh riwayat obrolan (Array of turns) menjadi kumpulan kepingan mikro.
     */
    public static function sliceHistory(array $history, ?string $activeEntity = null): array
    {
        $allFragments = [];
        foreach ($history as $idx => $turn) {
            $role = $turn['role'] ?? 'user';
            $content = $turn['content'] ?? '';
            $turnFragments = self::sliceText($content, $idx + 1, $role, $activeEntity);
            foreach ($turnFragments as $frag) {
                $allFragments[] = $frag;
            }
        }
        return $allFragments;
    }

    /**
     * Menentukan klasifikasi semantik kepingan mikro: FACT, CONSTRAINT, PREFERENCE, ACTION_PLAN, OPINION, CHITCHAT
     */
    protected static function classifyFragmentType(string $clause): string
    {
        $lower = mb_strtolower(trim($clause), 'UTF-8');

        // Chitchat / Basa-basi (Salam, ucapan terima kasih, filler tanpa muatan teknis/faktual)
        if (preg_match('/\b(?:halo|hai|salam|selamat\s+(?:pagi|siang|sore|malam)|terima\s+kasih|makasih|ok(?:e)?|siap|baiklah|hehe|haha|sip|yup|ya\s+sudah)\b/ui', $lower) &&
            !preg_match('/\b(?:anggaran|proyek|database|server|berat|kg|rp|biaya|masalah|fitur|aplikasi|sistem|deadline|tenggat|pilihan|alternatif)\b/ui', $lower)) {
            return self::TYPE_CHITCHAT;
        }

        // Constraint (Batasan & Syarat Mutlak)
        if (preg_match('/\b(?:jangan|tidak\s+boleh|dilarang|harus|wajib|maksimal|minimal|deadline|tenggat|paling\s+lambat|batasan|anggaran\s+terbatas|tanpa)\b/ui', $lower)) {
            return self::TYPE_CONSTRAINT;
        }

        // Preference (Preferensi & Minat Pengguna)
        if (preg_match('/\b(?:lebih\s+suka|suka|gemar|hobi|minat|favorit|pilih|memprioritaskan|cenderung|lebih\s+memilih)\b/ui', $lower)) {
            return self::TYPE_PREFERENCE;
        }

        // Action Plan (Rencana Aksi & Langkah)
        if (preg_match('/\b(?:akan|rencana|langkah\s+(?:pertama|kedua|selanjutnya)|tahap|jadwal|agenda|mulai\s+dari|implementasi|desain|eksekusi)\b/ui', $lower)) {
            return self::TYPE_ACTION_PLAN;
        }

        // Fact (Fakta Objektif, Kuantitas, Properti Entitas)
        if (preg_match('/\b(?:adalah|merupakan|memiliki|mempunyai|bernama|tinggal\s+di|bekerja\s+sebagai|seorang|berjumlah|sebesar|rp|\d+|unit|persen|%)\b/ui', $lower)) {
            return self::TYPE_FACT;
        }

        // Opinion (Opini Subjektif)
        if (preg_match('/\b(?:menurut\s+saya|saya\s+rasa|saya\s+pikir|sepertinya|mungkin|tampaknya|bisa\s+jadi)\b/ui', $lower)) {
            return self::TYPE_OPINION;
        }

        return self::TYPE_FACT;
    }

    /**
     * Hitung bobot kepentingan intrinsik (Intrinsic Salience Weight).
     * Batasan dan Fakta diberi prioritas komputasi tertinggi dibanding basa-basi.
     */
    protected static function calculateIntrinsicSalience(string $text, string $type): float
    {
        if ($type === self::TYPE_CHITCHAT) {
            return 0.05;
        }

        $baseWeights = [
            self::TYPE_CONSTRAINT  => 0.95,
            self::TYPE_FACT        => 0.88,
            self::TYPE_PREFERENCE  => 0.84,
            self::TYPE_ACTION_PLAN => 0.80,
            self::TYPE_OPINION     => 0.60
        ];

        $weight = $baseWeights[$type] ?? 0.70;

        // Bonus untuk angka, nilai moneter, atau terminologi kuantitatif
        if (preg_match('/\b(?:\d+|rp|juta|ribu|miliar|persen|%|kg|km|celcius)\b/ui', $text)) {
            $weight += 0.08;
        }

        // Bonus jika terdapat entitas bernama (huruf kapital)
        if (preg_match('/[A-Z][a-z]{2,}/', $text)) {
            $weight += 0.05;
        }

        // Penalti jika terlalu pendek (< 10 karakter)
        if (mb_strlen($text) < 10) {
            $weight -= 0.15;
        }

        return max(0.01, min(1.0, round($weight, 3)));
    }

    /**
     * Ekstraksi entitas subjek dari kepingan mikro
     */
    protected static function extractSubject(string $clause): ?string
    {
        if (preg_match('/\b([A-Z][a-zA-Z0-9_\-]{2,}(?:\s+[A-Z][a-zA-Z0-9_\-]{2,})?)\b/u', $clause, $m)) {
            $candidate = trim($m[1]);
            $lower = mb_strtolower($candidate);
            if (!in_array($lower, ['halo', 'hai', 'kami', 'saya', 'kamu', 'anda', 'tetapi', 'namun', 'karena', 'jika', 'dalam', 'untuk'])) {
                return $candidate;
            }
        }
        return null;
    }

    /**
     * Saring dan Beri Peringkat Kepingan Informasi Berdasarkan Kueri (Filter & Rank)
     * Mengeliminasi derau (basa-basi dan kepingan tak relevan), mempertahankan kepingan emas.
     */
    public static function filterAndRank(array $fragments, string $query, float $threshold = 0.22): array
    {
        $queryTokens = TextProcessor::tokenize($query, true);
        $queryLower = mb_strtolower($query, 'UTF-8');

        $isGeneralSummary = (bool) preg_match('/\b(semua|riwayat|keseluruhan|sejauh\s+ini|apa\s+saja|rangkum|ringkas)\b/ui', $queryLower);

        $selected = [];
        $discarded = [];

        $totalCount = count($fragments);
        $maxTurn = 1;
        foreach ($fragments as $f) {
            if (($f['turn_index'] ?? 1) > $maxTurn) {
                $maxTurn = $f['turn_index'];
            }
        }

        foreach ($fragments as $frag) {
            // 1. Filter mutlak untuk chitchat (kecuali jika kueri memang mencari salam)
            if ($frag['type'] === self::TYPE_CHITCHAT) {
                $frag['filter_reason'] = 'Eliminasi derau basa-basi (chitchat noise)';
                $frag['score'] = 0.02;
                $discarded[] = $frag;
                continue;
            }

            // 2. Hitung Token Jaccard / Overlap Similarity
            $fragTokens = $frag['tokens'] ?? [];
            $overlap = 0;
            if (!empty($queryTokens) && !empty($fragTokens)) {
                $intersection = array_intersect($queryTokens, $fragTokens);
                $overlap = count($intersection) / max(1, count($queryTokens));
            }

            // 3. Hitung Semantic Concept Similarity
            $semanticScore = SemanticEmbeddings::calculateSemanticScore($query, $frag['text']);

            // 4. Hitung Recency Factor (Giliran lebih baru memiliki bobot kesegaran lebih tinggi)
            $turn = $frag['turn_index'] ?? 1;
            $recency = $maxTurn > 1 ? (0.7 + (0.3 * ($turn / $maxTurn))) : 1.0;

            // 5. Total Relevancy Equation:
            // S(f, q) = w_sim * Overlap + w_sem * Semantic + w_salience * Salience * Recency
            $intrinsicSalience = $frag['salience_weight'] ?? 0.5;

            if ($isGeneralSummary) {
                // Untuk permintaan ringkasan umum riwayat, bobot intrinsik dan jenis fakta/batasan lebih dominan
                $finalScore = (0.25 * $overlap) + (0.25 * $semanticScore) + (0.50 * $intrinsicSalience * $recency);
            } else {
                // Untuk pertanyaan spesifik, relevansi semantik dan tumpang-tindih kueri lebih dominan
                $finalScore = (0.45 * $overlap) + (0.35 * $semanticScore) + (0.20 * $intrinsicSalience * $recency);
            }

            $frag['score'] = round($finalScore, 4);

            if ($finalScore >= $threshold) {
                $selected[] = $frag;
            } else {
                $frag['filter_reason'] = 'Skor relevansi di bawah ambang batas (' . $frag['score'] . ' < ' . $threshold . ')';
                $discarded[] = $frag;
            }
        }

        // Urutkan kepingan terpilih berdasarkan skor tertinggi
        usort($selected, fn($a, $b) => $b['score'] <=> $a['score']);

        return [
            'selected'  => $selected,
            'discarded' => $discarded,
            'stats'     => [
                'total_fragments' => $totalCount,
                'retained_count'  => count($selected),
                'discarded_count' => count($discarded),
                'noise_ratio'     => round(count($discarded) / max(1, $totalCount), 2)
            ]
        ];
    }

    /**
     * Meramu Kepingan Terpilih Menjadi Respons Baru yang Utuh dan Orisinal (Generative Re-Synthesis)
     * Tidak sekadar menyalin kutipan lama, tetapi mengekstraksi struktur dan mereformulasikannya.
     */
    public static function synthesizeNovelResponse(string $query, array $selectedFragments, ?array $userProfile = null): array
    {
        if (empty($selectedFragments)) {
            return [
                'thought'    => "Pembedahan informasi dilakukan, namun tidak ditemukan kepingan riwayat percakapan yang relevan dengan topik '{$query}'.",
                'reply'      => "Berdasarkan riwayat percakapan kita sejauh ini, saya belum menemukan kepingan informasi spesifik terkait topik tersebut. Apakah ada detail atau fakta baru yang ingin Anda bagikan?",
                'confidence' => 0.5,
                'type'       => 'episodic_synthesis_empty'
            ];
        }

        // Klasifikasi kepingan terpilih ke dalam ranah tematik
        $facts = [];
        $constraints = [];
        $preferences = [];
        $plans = [];
        $opinions = [];

        foreach ($selectedFragments as $item) {
            $text = trim($item['text']);
            switch ($item['type']) {
                case self::TYPE_CONSTRAINT:
                    $constraints[] = $text;
                    break;
                case self::TYPE_PREFERENCE:
                    $preferences[] = $text;
                    break;
                case self::TYPE_ACTION_PLAN:
                    $plans[] = $text;
                    break;
                case self::TYPE_OPINION:
                    $opinions[] = $text;
                    break;
                default:
                    $facts[] = $text;
                    break;
            }
        }

        $totalSelected = count($selectedFragments);
        $thought = "Mengeksekusi Episodic Information Slicer: Berhasil memotong dan menyaring {$totalSelected} kepingan informasi relevan dari riwayat obrolan (membuang derau basa-basi). Merajut intisari proposisi menjadi respons sintesis orisinal.";

        // Rangkai respons orisinal yang segar
        $sections = [];

        $nameGreeting = !empty($userProfile['name']) ? " untuk **{$userProfile['name']}**" : "";
        $header = "🧠 **Sintesis Informasi Terpadu Sentinel{$nameGreeting}:**\n" .
                  "Dari penelusuran terhadap riwayat percakapan kita, berikut intisari poin penting yang telah dipilah dan dirangkai kembali secara fokus:";

        if (!empty($facts)) {
            $dedupedFacts = array_unique($facts);
            $lines = array_map(fn($f) => "  • " . ucfirst($f), array_slice($dedupedFacts, 0, 6));
            $sections[] = "📌 **Fakta & Parameter Kunci:**\n" . implode("\n", $lines);
        }

        if (!empty($constraints)) {
            $dedupedConstraints = array_unique($constraints);
            $lines = array_map(fn($c) => "  ⚠️ " . ucfirst($c), array_slice($dedupedConstraints, 0, 4));
            $sections[] = "🛑 **Batasan & Syarat Mutlak yang Berlaku:**\n" . implode("\n", $lines);
        }

        if (!empty($preferences)) {
            $dedupedPreferences = array_unique($preferences);
            $lines = array_map(fn($p) => "  ✨ " . ucfirst($p), array_slice($dedupedPreferences, 0, 4));
            $sections[] = "💡 **Preferensi & Kecenderungan yang Diingat:**\n" . implode("\n", $lines);
        }

        if (!empty($plans)) {
            $dedupedPlans = array_unique($plans);
            $lines = array_map(fn($pl) => "  ➡️ " . ucfirst($pl), array_slice($dedupedPlans, 0, 4));
            $sections[] = "🚀 **Rencana Aksi & Langkah yang Telah Disepakati:**\n" . implode("\n", $lines);
        }

        if (!empty($opinions)) {
            $dedupedOpinions = array_unique($opinions);
            $lines = array_map(fn($op) => "  💬 *\"" . ucfirst($op) . "\"*", array_slice($dedupedOpinions, 0, 3));
            $sections[] = "💭 **Sudut Pandang & Catatan Tambahan:**\n" . implode("\n", $lines);
        }

        $footer = "*(Semua potongan informasi lama yang bersifat basa-basi telah disaring ke latar belakang agar kesimpulan ini tetap tajam dan relevan dengan fokus kita saat ini)*.";

        $reply = $header . "\n\n" . implode("\n\n", $sections) . "\n\n" . $footer;

        return [
            'thought'    => $thought,
            'reply'      => $reply,
            'confidence' => 0.96,
            'type'       => 'episodic_synthesis_success'
        ];
    }
}
