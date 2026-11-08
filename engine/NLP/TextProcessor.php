<?php

namespace Sentinel\NLP;

class TextProcessor
{
    /**
     * Daftar stopwords umum bahasa Indonesia (kata tugas)
     */
    protected static array $stopwords = [
        'yang', 'untuk', 'pada', 'ke', 'para', 'namun', 'menurut', 'antara', 'dia', 'dua',
        'ia', 'seperti', 'jika', 'sehingga', 'kembali', 'dan', 'ini', 'karena', 'kepada',
        'oleh', 'saat', 'harus', 'sementara', 'setelah', 'belum', 'kami', 'sekitar', 'bagi',
        'serta', 'di', 'dari', 'telah', 'sebagai', 'masih', 'bisa', 'ada', 'itu', 'adalah'
    ];

    /**
     * Bersihkan teks: lowercase, hilangkan tanda baca, pertahankan alphanumeric
     */
    public static function clean(string $text): string
    {
        $text = mb_strtolower($text, 'UTF-8');
        // Hapus tanda baca selain huruf, angka, dan spasi
        $text = preg_replace('/[^\p{L}\p{N}\s]/u', ' ', $text);
        // Rapikan spasi berlebih
        $text = preg_replace('/\s+/', ' ', $text);
        return trim($text);
    }

    /**
     * Pisahkan teks menjadi array token kata
     */
    public static function tokenize(string $text, bool $removeStopwords = false): array
    {
        $cleaned = self::clean($text);
        if (empty($cleaned)) {
            return [];
        }

        $tokens = explode(' ', $cleaned);

        if ($removeStopwords) {
            $tokens = array_values(array_filter($tokens, function ($token) {
                return !in_array($token, self::$stopwords, true) && mb_strlen($token) > 1;
            }));
        }

        return $tokens;
    }

    /**
     * Ekstrak n-gram (misalnya bigram atau unigram) untuk pencocokan frasa
     */
    public static function ngrams(array $tokens, int $n = 2): array
    {
        $ngrams = [];
        $count = count($tokens);

        for ($i = 0; $i <= $count - $n; $i++) {
            $slice = array_slice($tokens, $i, $n);
            $ngrams[] = implode(' ', $slice);
        }

        return $ngrams;
    }

    /**
     * Ekstrak k-skip-n-grams untuk mengatasi keterbatasan jarak kata (Long-Distance Dependencies)
     * Contoh: "presiden yang pada waktu itu menjadi orang pertama"
     * Akan mengaitkan "presiden" dengan "pertama" sebagai fitur pasangan "presiden__pertama"!
     */
    public static function skipgrams(array $tokens, int $n = 2, int $k = 8): array
    {
        $tokens = array_values($tokens);
        $skipgrams = [];
        $count = count($tokens);

        for ($i = 0; $i < $count; $i++) {
            $maxJ = min($count - 1, $i + $k + 1);
            for ($j = $i + 1; $j <= $maxJ; $j++) {
                if ($tokens[$i] !== $tokens[$j]) {
                    $skipgrams[] = $tokens[$i] . '__' . $tokens[$j];
                }
            }
        }

        return array_unique($skipgrams);
    }

    /**
     * Analisis Pembalikan Polarisasi (Double Negation & Negation Inversion)
     * Memperbaiki bias Bag-of-Words pada kalimat seperti:
     * "saya tidak pernah bilang bahwa saya membencinya" (artinya tidak benci)
     * "aplikasi ini tidak buruk" (artinya bernada positif/cukup baik)
     */
    public static function detectDoubleNegation(string $text): array
    {
        $lower = mb_strtolower($text, 'UTF-8');
        $hasDoubleNegation = false;
        $inversionType = null;

        // Pola: "tidak/bukan ... benci/buruk/jelek"
        if (preg_match('/\b(tidak|bukan|tak|belum|jangan)\s+(?:pernah\s+)?(?:bilang\s+)?(?:bahwa\s+)?(?:saya\s+)?(benci|buruk|jelek|mengecewakan|parah|hancur)\b/iu', $lower)) {
            $hasDoubleNegation = true;
            $inversionType = 'positive_inversion';
        }

        // Pola: "bukan tidak mungkin" / "tidak mustahil"
        if (preg_match('/\b(bukan\s+tidak|tidak\s+mustahil|bukan\s+mustahil)\b/iu', $lower)) {
            $hasDoubleNegation = true;
            $inversionType = 'affirmation';
        }

        return [
            'has_inversion' => $hasDoubleNegation,
            'type'          => $inversionType
        ];
    }
}

