<?php

namespace Sentinel\NLP;

class EmotionalIntelligence
{
    // Kategori Emosi Utama (Ekman & Plutchik Emotional Spectrum)
    public const EMOTION_JOY            = 'joy';             // Kebahagiaan, sukacita, antusiasme, optimisme
    public const EMOTION_SADNESS        = 'sadness';         // Kesedihan, duka, kehilangan, keputusasaan
    public const EMOTION_ANGER          = 'anger';           // Kemarahan, frustrasi, kejengkelan, kegeraman
    public const EMOTION_FEAR           = 'fear';            // Ketakutan, kecemasan, kekhawatiran, panik
    public const EMOTION_SURPRISE       = 'surprise';        // Keterkejutan, keheranan, syok, takjub
    public const EMOTION_GRATITUDE_LOVE = 'gratitude_love';  // Rasa syukur, afeksi, cinta, apresiasi tulus
    public const EMOTION_NEUTRAL        = 'neutral';         // Netral, analitis, objektif

    /**
     * Leksikon Emosional Bahasa Indonesia & Slang Terbuka
     */
    protected static array $lexicon = [
        self::EMOTION_JOY => [
            'words' => [
                'senang', 'gembira', 'bahagia', 'sukacita', 'hore', 'asik', 'asikk', 'mantap', 'mantul',
                'keren', 'hebat', 'bangga', 'lega', 'puas', 'bersyukur', 'bersemangat', 'antusias',
                'sukses', 'berhasil', 'menang', 'juara', 'lulus', 'cemerlang', 'wkwk', 'wkwkwk',
                'haha', 'hahaha', 'alhamdulillah', 'alhamdulilah', 'berkah', 'beruntung'
            ],
            'emojis' => ['😊', '😄', '😁', '🎉', '🥳', '✨', '👏', '🏆', '😃']
        ],

        self::EMOTION_SADNESS => [
            'words' => [
                'sedih', 'duka', 'berduka', 'kecewa', 'mengecewakan', 'pilu', 'menangis', 'tangis', 'lara', 'patah_hati',
                'patah', 'hancur', 'terpuruk', 'putus_asa', 'frustrasi', 'kehilangan', 'menyesal', 'buruk', 'jelek', 'gagal',
                'nyesek', 'galau', 'depresi', 'lelah', 'capek', 'letih', 'hampa', 'merana', 'trauma',
                'kesepian', 'sendirian', 'nangis'
            ],
            'emojis' => ['😢', '😭', '🥺', '💔', '😞', '😔', '😿']
        ],

        self::EMOTION_ANGER => [
            'words' => [
                'marah', 'kesal', 'kesel', 'jengkel', 'murka', 'geram', 'emosi', 'dongkol', 'ngamuk',
                'benci', 'muak', 'jijik', 'bodoh', 'bego', 'tolol', 'brengsek', 'sialan', 'kampret',
                'parah', 'payah', 'kecewa_berat', 'lambat_sekali', 'lelet', 'rusak', 'curang',
                'buruk', 'jelek', 'mengecewakan', 'gagal'
            ],
            'emojis' => ['😡', '😠', '🤬', '👿', '😤']
        ],

        self::EMOTION_FEAR => [
            'words' => [
                'takut', 'cemas', 'khawatir', 'kawatir', 'panik', 'waswas', 'was_was', 'deg_degan',
                'debar', 'gemetar', 'ngeri', 'seram', 'mencekam', 'gelisah', 'resah', 'waspada',
                'terancam', 'gugup', 'nervous', 'bingung'
            ],
            'emojis' => ['😨', '😰', '😱', '😥', '😟']
        ],

        self::EMOTION_SURPRISE => [
            'words' => [
                'kaget', 'terkejut', 'heran', 'takjub', 'syok', 'shock', 'astaga', 'yaampun',
                'demi_apa', 'mustahil', 'tercengang', 'luar_biasa', 'anjir', 'buset', 'wah'
            ],
            'emojis' => ['😲', '😮', '🤯', '😱', '😳']
        ],

        self::EMOTION_GRATITUDE_LOVE => [
            'words' => [
                'terima_kasih', 'terimakasih', 'makasih', 'thanks', 'tengkyu', 'sayang', 'cinta',
                'suka', 'respek', 'hormat', 'kagum', 'apresiasi', 'menyayangi', 'mencintai',
                'berterima_kasih', 'syukur'
            ],
            'emojis' => ['❤️', '💖', '🙏', '🥰', '😍', '💐']
        ]
    ];

    /**
     * Penguat Intensitas (Amplifiers) dan Pelemah (Diminishers)
     */
    protected static array $amplifiers = [
        'sangat' => 1.45, 'banget' => 1.45, 'amat' => 1.40, 'sekali' => 1.35, 'luar_biasa' => 1.50,
        'parah' => 1.40, 'benar_benar' => 1.40, 'beneran' => 1.30, 'sungguh' => 1.35, 'total' => 1.40
    ];

    protected static array $diminishers = [
        'agak' => 0.65, 'sedikit' => 0.60, 'kurang' => 0.50, 'lumayan' => 0.75, 'cukup' => 0.80
    ];

    /**
     * Analisis Emosional Mendalam Bebas Kelemahan Mendasar
     * (Menangani Sarkasme, Emosi Ganda/Campuran, Deteksi Krisis, dan Polarisasi Majemuk)
     */
    public static function analyze(string $text): array
    {
        $raw = trim($text);
        $lower = mb_strtolower($raw, 'UTF-8');

        // 1. Cek Protokol Keselamatan Krisis / Keputusasaan Ekstrem (Safety Crisis Guard)
        $isCrisis = self::detectCrisis($lower);

        // 2. Cek Deteksi Sarkasme & Ironi Kontekstual
        $sarcasm = self::detectSarcasm($raw, $lower);

        // 2b. Cek Koreksi Pembalikan Polarisasi (Dobel Negasi: "tidak pernah benci", "tidak buruk", "bukan tidak mungkin")
        $inversion = TextProcessor::detectDoubleNegation($raw);

        // 3. Kalkulasi Skor Emosi Dasar per Kategori
        $emotionScores = [];
        $wordsFound = [];

        foreach (self::$lexicon as $category => $data) {
            $score = 0.0;
            $found = [];

            // Cocokkan kata leksikon
            foreach ($data['words'] as $w) {
                $term = str_replace('_', ' ', $w);
                if (preg_match('/(?:\b|^)' . preg_quote($term, '/') . '(?:\b|$)/ui', $lower)) {
                    $score += 1.0;
                    $found[] = $term;
                }
            }

            // Cocokkan emoji
            foreach ($data['emojis'] as $emoji) {
                if (str_contains($raw, $emoji)) {
                    $score += 1.25;
                    $found[] = $emoji;
                }
            }

            if ($score > 0) {
                $emotionScores[$category] = $score;
                $wordsFound[$category] = array_unique($found);
            }
        }

        // 4. Analisis Penguat & Pelemah Intensitas (Arousal & Intensity Modeling)
        $intensityMultiplier = 1.0;
        foreach (self::$amplifiers as $amp => $mult) {
            $term = str_replace('_', ' ', $amp);
            if (preg_match('/(?:\b|^)' . preg_quote($term, '/') . '(?:\b|$)/ui', $lower)) {
                $intensityMultiplier = max($intensityMultiplier, $mult);
            }
        }
        foreach (self::$diminishers as $dim => $mult) {
            $term = str_replace('_', ' ', $dim);
            if (preg_match('/(?:\b|^)' . preg_quote($term, '/') . '(?:\b|$)/ui', $lower)) {
                $intensityMultiplier = min($intensityMultiplier, $mult);
            }
        }

        // Tanda seru ganda (!!) atau huruf kapital berlebih meningkatkan intensitas
        if (preg_match('/!{2,}/', $raw) || (mb_strlen($raw) > 5 && mb_strtoupper($raw, 'UTF-8') === $raw)) {
            $intensityMultiplier *= 1.25;
        }

        // Terapkan multiplikator intensitas
        foreach ($emotionScores as $cat => $val) {
            $emotionScores[$cat] = round($val * $intensityMultiplier, 2);
        }

        // 5. Intervensi Sarkasme: Jika sarkasme terdeteksi, balikkan emosi semu positif menjadi kemarahan/frustrasi
        if ($sarcasm['is_sarcastic']) {
            unset($emotionScores[self::EMOTION_JOY]);
            $emotionScores[self::EMOTION_ANGER] = max(1.5, ($emotionScores[self::EMOTION_ANGER] ?? 0.0) + 1.8);
            $wordsFound[self::EMOTION_ANGER][] = 'sarkasme: ' . $sarcasm['reason'];
        }

        // 5b. Intervensi Dobel Negasi: Balikkan polaritas negatif menjadi afirmatif / positif
        if ($inversion['has_inversion']) {
            if ($inversion['type'] === 'positive_inversion' || $inversion['type'] === 'affirmation') {
                unset($emotionScores[self::EMOTION_ANGER], $emotionScores[self::EMOTION_SADNESS]);
                $wordsFound[self::EMOTION_ANGER] = [];
                $wordsFound[self::EMOTION_SADNESS] = [];
                $emotionScores[self::EMOTION_JOY] = max(1.2, ($emotionScores[self::EMOTION_JOY] ?? 0.0) + 1.5);
                $wordsFound[self::EMOTION_JOY][] = 'dobel_negasi_positif';
            }
        }

        // 6. Urutkan emosi berdasarkan bobot skor tertinggi
        arsort($emotionScores);

        // Identifikasi Emosi Utama & Emosi Sekunder (Mixed Emotions Modeling)
        $categories = array_keys($emotionScores);
        $primaryEmotion = !empty($categories) ? $categories[0] : self::EMOTION_NEUTRAL;
        $secondaryEmotion = count($categories) >= 2 && $emotionScores[$categories[1]] >= 0.8 ? $categories[1] : null;

        $isMixed = ($secondaryEmotion !== null);

        // 7. Kalkulasi Valence (-1.0 s.d. +1.0) dan Intensitas Emosi (0.0 s.d. 1.0)
        $valence = 0.0;
        $primaryScore = $emotionScores[$primaryEmotion] ?? 0.0;
        $normalizedIntensity = min(1.0, round($primaryScore / 3.0, 2));

        if ($primaryEmotion === self::EMOTION_JOY || $primaryEmotion === self::EMOTION_GRATITUDE_LOVE) {
            $valence = round(min(1.0, 0.35 + ($primaryScore * 0.25)), 2);
            $sentiment = 'positif';
        } elseif (in_array($primaryEmotion, [self::EMOTION_SADNESS, self::EMOTION_ANGER, self::EMOTION_FEAR], true)) {
            $valence = round(max(-1.0, -0.35 - ($primaryScore * 0.25)), 2);
            $sentiment = 'negatif';
        } else {
            $sentiment = 'netral';
            $valence = 0.0;
            $normalizedIntensity = 0.0;
        }

        // 8. Rangkum daftar kata positif dan negatif untuk kompatibilitas mundur
        $posWords = array_merge($wordsFound[self::EMOTION_JOY] ?? [], $wordsFound[self::EMOTION_GRATITUDE_LOVE] ?? []);
        $negWords = array_merge($wordsFound[self::EMOTION_SADNESS] ?? [], $wordsFound[self::EMOTION_ANGER] ?? [], $wordsFound[self::EMOTION_FEAR] ?? []);

        // 9. Sintesis Preamble Empati Responsif (Affective Alignment)
        $empathyPreamble = self::generateEmpathicPreamble($primaryEmotion, $secondaryEmotion, $normalizedIntensity, $isCrisis);

        return [
            // Kompatibilitas mundur dengan unit test Sentinel yang ada:
            'sentiment'          => $sentiment,
            'score'              => $valence,
            'positive_words'     => array_values(array_unique($posWords)),
            'negative_words'     => array_values(array_unique($negWords)),

            // Kemampuan Lanjutan Intelijen Emosional:
            'primary_emotion'    => $primaryEmotion,
            'secondary_emotion'  => $secondaryEmotion,
            'intensity'          => $normalizedIntensity,
            'valence'            => $valence,
            'is_sarcasm'         => $sarcasm['is_sarcastic'],
            'is_mixed'           => $isMixed,
            'is_crisis'          => $isCrisis,
            'empathy_preamble'   => $empathyPreamble,
            'detected_tokens'    => $wordsFound
        ];
    }

    /**
     * Deteksi Sarkasme & Ironi Percakapan
     * Pola: Kata sanjungan positif (hebat, luar biasa, pintar) dipadukan dengan komplain / kegagalan nyata
     */
    protected static function detectSarcasm(string $raw, string $lower): array
    {
        $hasPraise = preg_match('/\b(?:hebat\s+(?:sekali|banget|ya)|luar\s+biasa|pintar\s+(?:sekali|banget)|bagus\s+(?:banget|sekali)|mantap\s+betul|keren\s+amat)\b/ui', $lower);
        $hasComplaint = preg_match('/\b(?:macet|error|hancur|rusak|parah|lelet|lambat|tidak\s+bisa|gak\s+bisa|hilang|tidak\s+ada\s+respon|menunggu\s+berjam|berhari|mengecewakan|gagal)\b/ui', $lower);

        if ($hasPraise && $hasComplaint) {
            return [
                'is_sarcastic' => true,
                'reason'       => 'Pujian positif dikombinasikan dengan keluhan kegagalan sistem'
            ];
        }

        // Pola elipsis ironi: "wah terima kasih ya..." dengan nada sinis
        if (preg_match('/^(?:wah|oh)\s+(?:terima\s+kasih|makasih|hebat)\s+(?:ya|deh)\.{2,}/ui', $lower)) {
            return [
                'is_sarcastic' => true,
                'reason'       => 'Konstruksi tanda elipsis bernada sinis'
            ];
        }

        return ['is_sarcastic' => false, 'reason' => null];
    }

    /**
     * Deteksi Sinyal Krisis / Keputusasaan Ekstrem (Crisis & Self-Harm Safety Guard)
     */
    protected static function detectCrisis(string $lower): bool
    {
        $crisisPatterns = [
            'ingin mengakhiri hidup',
            'tidak sanggup hidup lagi',
            'mau mati saja',
            'bunuh diri',
            'sudah tidak ada harapan hidup',
            'mengakhiri segalanya',
            'menyakiti diri sendiri'
        ];

        foreach ($crisisPatterns as $p) {
            if (str_contains($lower, $p)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Generator Preamble Empati Berdasarkan Kondisi Afektif Pengguna
     */
    public static function generateEmpathicPreamble(string $primary, ?string $secondary, float $intensity, bool $isCrisis): ?string
    {
        if ($isCrisis) {
            return "❤️ **Pesan Perhatian & Dukungan:**\n" .
                   "Saya mendengar betapa beratnya beban yang sedang Anda rasakan saat ini. Hidup Anda sangat berharga dan Anda tidak sendirian. " .
                   "Jika Anda merasa tertekan, mohon hubungi orang terdekat atau layanan konseling profesional bebas pulsa:\n" .
                   "- **LISA (Love Inside Suicide Awareness):** Hubungi `12-12`\n" .
                   "- **Kemenkes Sehat Jiwa:** Hotline `1500-567`\n" .
                   "Saya di sini siap mendengarkan dan menemani Anda berbicara kapan pun Anda siap.";
        }

        switch ($primary) {
            case self::EMOTION_JOY:
                if ($secondary === self::EMOTION_FEAR) {
                    return "🎉 Selamat atas kabar baik tersebut! Sekaligus sangat wajar jika ada rasa cemas di awal perjalanan baru. Mari kita urai tantangannya bersama-sama 😊.";
                }
                return ($intensity >= 0.7)
                    ? "Wah, luar biasa sekali! Ikut merasakan kegembiraan dan sukacita Anda! 🎉 Ada hal menarik apa lagi yang ingin kita rayakan atau bahas?"
                    : "Senang sekali mendengarnya! Semangat positif yang menyenangkan 😊.";

            case self::EMOTION_SADNESS:
                return ($intensity >= 0.7)
                    ? "Saya turut merasakan duka dan kesedihan mendalam yang Anda alami 🥺. Tarik napas perlahan; saya ada di sini menemani Anda."
                    : "Saya mengerti perasaan berat atau kecewa yang sedang Anda alami. Jika berkenan, mari kita telaah perlahan...";

            case self::EMOTION_ANGER:
                return "Saya sangat memahami rasa kesal dan frustrasi Anda atas situasi ini, dan saya memohon maaf atas ketidaknyamanan tersebut. Mari kita fokus mencari solusi terbaik bersama-sama.";

            case self::EMOTION_FEAR:
                return "Rasa cemas dan khawatir adalah respon yang sangat manusiawi saat menghadapi ketidakpastian. Mari kita bedah situasinya satu per satu agar langkah ke depan terasa lebih tenang dan jelas.";

            case self::EMOTION_GRATITUDE_LOVE:
                return "Sama-sama! Senang sekali bisa membantu Anda dengan tulus 🙏. Terima kasih banyak atas apresiasi yang begitu hangat!";

            default:
                return null;
        }
    }
}
