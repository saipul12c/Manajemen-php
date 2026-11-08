<?php

namespace Sentinel\NLP;

class SemanticEmbeddings
{
    /**
     * Peta Sinonim dan Kluster Konseptual Bahasa Indonesia & Terminologi Umum
     * Setiap cluster mengelompokkan kata-kata yang memiliki makna semantik serupa (Synsets)
     */
    protected static array $synsets = [
        // Kendaraan & Transportasi
        'kendaraan' => ['transportasi', 'sarana_transportasi', 'alat_transportasi', 'armada', 'mobil', 'motor', 'sepeda', 'pesawat', 'kereta', 'kapal', 'bus', 'truk', 'helikopter', 'perahu', 'kendaraan_bermotor'],
        'mobil' => ['kendaraan', 'roda_empat', 'sedan', 'suv', 'otomotif'],
        'pesawat' => ['pesawat_terbang', 'penerbangan', 'kapal_terbang', 'dirgantara', 'aviasi', 'boeing', 'airbus'],
        'kecepatan' => ['cepat', 'laju', 'akselerasi', 'kelajuan', 'kilat', 'gesit', 'pesat'],

        // Emosi & Perasaan
        'senang' => ['gembira', 'bahagia', 'sukacita', 'riang', 'ceria', 'puas', 'bersyukur', 'antusias', 'girang', 'lega'],
        'sedih' => ['duka', 'berduka', 'murung', 'kecewa', 'pilu', 'menangis', 'lara', 'patah_hati', 'gundah', 'putus_asa'],
        'marah' => ['geram', 'kesal', 'murka', 'emosi', 'dongkol', 'jengkel', 'gusar', 'benci', 'berang'],
        'hebat' => ['luar_biasa', 'keren', 'mantap', 'bagus', 'unggul', 'istimewa', 'sempurna', 'cemerlang', 'fantastis', 'top'],
        'buruk' => ['jelek', 'parah', 'rusak', 'mengecewakan', 'kurang_bagus', 'hancur', 'bobrok'],

        // Kecerdasan Buatan & Komputasi
        'ai' => ['kecerdasan_buatan', 'artificial_intelligence', 'machine_learning', 'pembelajaran_mesin', 'deep_learning', 'neural_network', 'jaringan_syaraf', 'algoritma', 'model_ai', 'sentinel'],
        'komputer' => ['pc', 'laptop', 'mesin_hitung', 'komputasi', 'sistem', 'perangkat', 'server'],
        'pemrograman' => ['koding', 'coding', 'bahasa_pemrograman', 'source_code', 'kode', 'script', 'software', 'aplikasi', 'program'],
        'belajar' => ['mempelajari', 'latihan', 'training', 'studi', 'edukasi', 'menuntut_ilmu', 'pembelajaran', 'bimbingan'],

        // Sains & Alam
        'sains' => ['ilmu_pengetahuan', 'ilmiah', 'penelitian', 'riset', 'eksperimen', 'laboratorium', 'teori'],
        'alam' => ['lingkungan', 'bumi', 'ekosistem', 'alam_semesta', 'jagad_raya', 'kosmos', 'biosfer'],
        'tumbuhan' => ['tanaman', 'flora', 'pohon', 'bunga', 'daun', 'vegetasi', 'fotosintesis'],
        'hewan' => ['binatang', 'fauna', 'satwa', 'makhluk_hidup', 'mamalia', 'unggas', 'reptil'],
        'energi' => ['tenaga', 'daya', 'kekuatan', 'listrik', 'kalor', 'kinetik', 'potensial', 'terbarukan'],

        // Pekerjaan & Profesi
        'dokter' => ['tenaga_medis', 'medis', 'ahli_kesehatan', 'spesialis', 'tabib', 'perawat', 'bidan'],
        'guru' => ['pendidik', 'pengajar', 'dosen', 'instruktur', 'tutor', 'mentor'],
        'ilmuwan' => ['peneliti', 'pakar', 'saintis', 'akademisi', 'ahli', 'cendekiawan'],
        'presiden' => ['kepala_negara', 'pemimpin_negara', 'pemimpin', 'ri_1', 'pemerintah'],

        // Kreativitas & Karya Sastra
        'puisi' => ['sajak', 'syair', 'pantun', 'bait', 'rima', 'karya_sastra', 'gurindam', 'soneta'],
        'cerita' => ['kisah', 'dongeng', 'fabel', 'narasi', 'fiksi', 'novel', 'cerpen', 'anekdot', 'babad'],
        'kreativitas' => ['inovasi', 'daya_cipta', 'imajinasi', 'kreasi', 'kreatif', 'orisinalitas', 'karya'],

        // Instruksi & Verba
        'buatkan' => ['ciptakan', 'tuliskan', 'bikin', 'susunlah', 'generate', 'rangkai', 'buat'],
        'jelaskan' => ['terangkan', 'uraikan', 'paparkan', 'deskripsikan', 'definisikan', 'jabarkan', 'ceritakan_tentang'],
        'ringkas' => ['rangkum', 'simpulkan', 'intisari', 'ikhtisar', 'sinopsis', 'tldr'],
        'bantu' => ['tolong', 'dukung', 'asistensi', 'pandu', 'bimbing'],
        'analisis' => ['telaah', 'bedah', 'evaluasi', 'investigasi', 'periksa', 'kaji', 'uji'],

        // Logika & Hubungan
        'tua' => ['usia_tinggi', 'senior', 'berumur', 'lebih_tua', 'didahului'],
        'muda' => ['usia_rendah', 'junior', 'belia', 'lebih_muda'],
        'tinggi' => ['jangkung', 'menjulang', 'lebih_tinggi', 'ketinggian'],
        'pendek' => ['rendah', 'lebih_pendek'],
        'besar' => ['luas', 'raksasa', 'akbar', 'lebih_besar', 'maksimal'],
        'kecil' => ['mini', 'mikro', 'sempit', 'lebih_kecil', 'minimal']
    ];

    /**
     * Vektor fitur semantik 16-dimensi untuk konsep utama (Dense Concept Representation)
     * Dimensi: [Sains, Teknologi, Emosi_Positif, Emosi_Negatif, Sastra, Alam, Logika, Aksi, Medis, Kecepatan, Manusia, Fisik, Edukasi, Seni, Sejarah, Abstrak]
     */
    protected static array $conceptVectors = [
        'ai'           => [0.4, 0.9, 0.2, 0.0, 0.1, 0.0, 0.8, 0.3, 0.0, 0.5, 0.3, 0.1, 0.6, 0.1, 0.2, 0.8],
        'komputer'     => [0.3, 0.9, 0.1, 0.0, 0.0, 0.0, 0.7, 0.3, 0.0, 0.4, 0.2, 0.6, 0.5, 0.0, 0.1, 0.5],
        'program'      => [0.3, 0.8, 0.1, 0.0, 0.1, 0.0, 0.8, 0.4, 0.0, 0.3, 0.2, 0.1, 0.6, 0.1, 0.1, 0.6],
        'kendaraan'    => [0.2, 0.7, 0.1, 0.0, 0.0, 0.1, 0.3, 0.8, 0.0, 0.9, 0.3, 0.9, 0.1, 0.0, 0.2, 0.2],
        'mobil'        => [0.2, 0.6, 0.1, 0.0, 0.0, 0.0, 0.2, 0.8, 0.0, 0.7, 0.3, 0.9, 0.1, 0.0, 0.1, 0.1],
        'pesawat'      => [0.4, 0.8, 0.1, 0.0, 0.0, 0.1, 0.3, 0.9, 0.0, 1.0, 0.3, 0.9, 0.1, 0.0, 0.2, 0.2],
        'senang'       => [0.0, 0.0, 0.9, 0.0, 0.3, 0.2, 0.1, 0.3, 0.1, 0.2, 0.8, 0.1, 0.1, 0.3, 0.1, 0.7],
        'sedih'        => [0.0, 0.0, 0.0, 0.9, 0.4, 0.1, 0.1, 0.1, 0.2, 0.0, 0.8, 0.1, 0.1, 0.3, 0.1, 0.7],
        'sains'        => [0.9, 0.6, 0.2, 0.0, 0.1, 0.6, 0.8, 0.3, 0.3, 0.2, 0.3, 0.4, 0.9, 0.1, 0.4, 0.7],
        'puisi'        => [0.0, 0.0, 0.4, 0.2, 0.9, 0.3, 0.2, 0.3, 0.0, 0.1, 0.6, 0.0, 0.4, 0.9, 0.3, 0.8],
        'cerita'       => [0.1, 0.1, 0.3, 0.1, 0.8, 0.2, 0.3, 0.4, 0.0, 0.2, 0.7, 0.1, 0.5, 0.8, 0.4, 0.6],
        'dokter'       => [0.5, 0.3, 0.3, 0.1, 0.0, 0.2, 0.5, 0.6, 0.9, 0.2, 0.9, 0.4, 0.7, 0.0, 0.2, 0.3],
        'guru'         => [0.3, 0.2, 0.5, 0.0, 0.2, 0.2, 0.5, 0.5, 0.1, 0.1, 0.9, 0.2, 1.0, 0.2, 0.3, 0.4],
        'logika'       => [0.5, 0.5, 0.1, 0.0, 0.1, 0.1, 1.0, 0.4, 0.0, 0.2, 0.4, 0.1, 0.6, 0.1, 0.2, 0.9]
    ];

    /**
     * Dapatkan semua sinonim dan istilah terkait dari suatu kata
     */
    public static function getSynonyms(string $word): array
    {
        $word = mb_strtolower(trim($word), 'UTF-8');
        $results = [];

        foreach (self::$synsets as $root => $cluster) {
            if ($root === $word || in_array($word, $cluster, true)) {
                $results[] = $root;
                $results = array_merge($results, $cluster);
            }
        }

        return array_values(array_unique($results));
    }

    /**
     * Lakukan ekspansi kueri semantik (Query Expansion):
     * Menambahkan kata-kata sinonim dan istilah terkait ke dalam teks agar pencocokan TF-IDF jauh lebih fleksibel
     */
    public static function expandQuery(string $text): string
    {
        $tokens = TextProcessor::tokenize($text);
        $expanded = $tokens;

        foreach ($tokens as $token) {
            $synonyms = self::getSynonyms($token);
            if (!empty($synonyms)) {
                // Ambil maksimal 3 sinonim terdekat agar tidak terlalu encer
                $subset = array_slice($synonyms, 0, 3);
                foreach ($subset as $syn) {
                    $cleanSyn = str_replace('_', ' ', $syn);
                    $expanded[] = $cleanSyn;
                }
            }
        }

        return implode(' ', array_unique($expanded));
    }

    /**
     * Hitung kemiripan kosinus (Cosine Similarity) antara dua vektor numerik
     */
    public static function cosineSimilarity(array $vecA, array $vecB): float
    {
        $dotProduct = 0.0;
        $normA = 0.0;
        $normB = 0.0;
        $len = min(count($vecA), count($vecB));

        for ($i = 0; $i < $len; $i++) {
            $dotProduct += $vecA[$i] * $vecB[$i];
            $normA += $vecA[$i] * $vecA[$i];
            $normB += $vecB[$i] * $vecB[$i];
        }

        if ($normA <= 0.0 || $normB <= 0.0) {
            return 0.0;
        }

        return $dotProduct / (sqrt($normA) * sqrt($normB));
    }

    /**
     * Dapatkan representasi vektor semantik dense untuk teks (Rata-rata tertimbang vektor konsep)
     */
    public static function getSemanticVector(string $text): array
    {
        $tokens = TextProcessor::tokenize($text);
        $dim = 16;
        $vector = array_fill(0, $dim, 0.0);
        $matches = 0;

        foreach ($tokens as $token) {
            // Cek langsung
            if (isset(self::$conceptVectors[$token])) {
                for ($d = 0; $d < $dim; $d++) {
                    $vector[$d] += self::$conceptVectors[$token][$d];
                }
                $matches++;
                continue;
            }

            // Cek sinonim
            $syns = self::getSynonyms($token);
            foreach ($syns as $syn) {
                if (isset(self::$conceptVectors[$syn])) {
                    for ($d = 0; $d < $dim; $d++) {
                        $vector[$d] += self::$conceptVectors[$syn][$d] * 0.8;
                    }
                    $matches += 0.8;
                    break;
                }
            }
        }

        if ($matches > 0) {
            for ($d = 0; $d < $dim; $d++) {
                $vector[$d] /= $matches;
            }
        }

        return $vector;
    }

    /**
     * Hitung skor kedekatan semantik antara dua kalimat/frasa
     * Menggabungkan Jaccard Similarity, Synonym Overlap, dan Cosine Dense Vector
     */
    public static function calculateSemanticScore(string $textA, string $textB): float
    {
        $tokensA = TextProcessor::tokenize($textA);
        $tokensB = TextProcessor::tokenize($textB);

        if (empty($tokensA) || empty($tokensB)) {
            return 0.0;
        }

        // 1. Exact Token Overlap (Jaccard)
        $intersect = array_intersect($tokensA, $tokensB);
        $union = array_unique(array_merge($tokensA, $tokensB));
        $jaccard = count($intersect) / max(1, count($union));

        // 2. Synonym Overlap
        $synonymMatches = 0;
        foreach ($tokensA as $a) {
            $synA = self::getSynonyms($a);
            foreach ($tokensB as $b) {
                if ($a === $b || in_array($b, $synA, true)) {
                    $synonymMatches++;
                    break;
                }
            }
        }
        $synonymScore = $synonymMatches / max(1, count($tokensA));

        // 3. Dense Vector Cosine Similarity
        $vecA = self::getSemanticVector($textA);
        $vecB = self::getSemanticVector($textB);
        $cosine = self::cosineSimilarity($vecA, $vecB);

        // Bobot perpaduan: 0.3 Jaccard + 0.4 Sinonim + 0.3 Kosinus
        $finalScore = (0.3 * $jaccard) + (0.4 * $synonymScore) + (0.3 * $cosine);
        return min(1.0, max(0.0, round($finalScore, 4)));
    }

    /**
     * Koreksi Saltik / Typo Tolerance (Fuzzy Matcher)
     * Mengembalikan kata resmi yang paling mirip jika ada salah ketik
     */
    public static function findFuzzyCorrection(string $token): ?string
    {
        $token = mb_strtolower(trim($token), 'UTF-8');
        if (mb_strlen($token) < 4) {
            return null;
        }

        $allKnownWords = array_keys(self::$synsets);
        foreach (self::$synsets as $syns) {
            $allKnownWords = array_merge($allKnownWords, $syns);
        }
        $allKnownWords = array_unique($allKnownWords);

        $bestWord = null;
        $bestDistance = 999;

        foreach ($allKnownWords as $word) {
            $dist = levenshtein($token, $word);
            // Toleransi jarak 1 untuk kata pendek (4-5 huruf), toleransi 2 untuk kata panjang (>5 huruf)
            $allowedDist = mb_strlen($token) > 5 ? 2 : 1;
            if ($dist <= $allowedDist && $dist < $bestDistance) {
                $bestDistance = $dist;
                $bestWord = $word;
            }
        }

        return $bestWord;
    }
}

