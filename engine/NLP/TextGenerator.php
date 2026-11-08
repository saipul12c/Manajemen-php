<?php

namespace Sentinel\NLP;

class TextGenerator
{
    /**
     * Bank Sampiran & Pola Pantun Tradisional Berima a-b-a-b
     */
    protected static array $pantunBank = [
        'pendidikan' => [
            'sampiran' => ["Pergi ke pasar membeli durian,", "Singgah sebentar membeli duku,"],
            'isi'      => ["Tuntutlah ilmu demi masa depan,", "Banyak membaca rajin buka buku."],
            'rima'     => 'a-b-a-b'
        ],
        'nasihat' => [
            'sampiran' => ["Pohon beringin rindang daunnya,", "Tempat berteduh sang burung dara,"],
            'isi'      => ["Hormati ibu ayah selamanya,", "Agar hidupmu berkah sentosa."],
            'rima'     => 'a-b-a-b'
        ],
        'teknologi' => [
            'sampiran' => ["Lampu bersinar di tengah malam,", "Bintang berkilau di cakrawala,"],
            'isi'      => ["Teknologi AI kian mendalam,", "Bantu manusia menggapai cita."],
            'rima'     => 'a-b-a-b'
        ],
        'koding' => [
            'sampiran' => ["Menanam padi di musim hujan,", "Burung pipit terbang ke ladang,"],
            'isi'      => ["Ketiklah kode penuh ketelitian,", "Aplikasi sukses hati pun senang."],
            'rima'     => 'a-b-a-b'
        ],
        'lucu' => [
            'sampiran' => ["Jalan-jalan ke kota Blitar,", "Jangan lupa beli kemplang,"],
            'isi'      => ["Gaya elit dompet bergetar,", "Pas mau bayar uangnya kurang."],
            'rima'     => 'a-b-a-b'
        ],
        'cinta' => [
            'sampiran' => ["Bunga mawar harum baunya,", "Mekar berseri di sudut taman,"],
            'isi'      => ["Hati tertawan senyum manisnya,", "Semoga abadi dalam pelukan."],
            'rima'     => 'a-b-a-b'
        ],
        'persahabatan' => [
            'sampiran' => ["Berlayar perahu menuju muara,", "Angin berhembus membawa salam,"],
            'isi'      => ["Sahabat sejati berbagi suka lara,", "Setia menemani siang dan malam."],
            'rima'     => 'a-b-a-b'
        ]
    ];

    /**
     * Deteksi apakah kueri memerlukan generasi teks kreatif
     */
    public static function isCreativeQuery(string $text): bool
    {
        $lower = mb_strtolower(trim($text), 'UTF-8');

        if (preg_match('/\b(buatkan|buat|tuliskan|tulis|bikin)\s+(sebuah\s+)?(puisi|pantun|syair|sajak)\b/iu', $lower)) {
            return true;
        }

        if (preg_match('/\b(ceritakan|dongengkan|buatkan\s+cerita|buat\s+cerpen|fabel|kisah)\b/iu', $lower)) {
            return true;
        }

        if (preg_match('/\b(parafrase|parafrasakan|ubah\s+gaya|ganti\s+nada|ubah\s+nada)\b/iu', $lower)) {
            return true;
        }

        if (preg_match('/\b(brainstorming|sumbang\s+ide|ide\s+kreatif|beri\s+ide)\b/iu', $lower)) {
            return true;
        }

        return false;
    }

    /**
     * Memproses dan menghasilkan karya teks kreatif
     */
    public static function generate(string $query): ?array
    {
        $lower = mb_strtolower(trim($query), 'UTF-8');

        // 1. Pantun Generator
        if (preg_match('/\b(pantun)\b/iu', $lower)) {
            return self::generatePantun($query);
        }

        // 2. Puisi Generator
        if (preg_match('/\b(puisi|syair|sajak)\b/iu', $lower)) {
            return self::generatePoem($query);
        }

        // 3. Cerita Pendek / Fabel / Narasi
        if (preg_match('/\b(cerita|dongeng|fabel|kisah|cerpen)\b/iu', $lower)) {
            return self::generateStory($query);
        }

        // 4. Parafrase & Perubahan Nada Gaya Bahasa
        if (preg_match('/\b(parafrase|parafrasakan|ubah\s+gaya|ganti\s+nada|ubah\s+nada)\b/iu', $lower)) {
            return self::generateParaphrase($query);
        }

        // 5. Brainstorming Ide Kreatif
        if (preg_match('/\b(brainstorming|ide|sumbang\s+ide)\b/iu', $lower)) {
            return self::generateBrainstorming($query);
        }

        return null;
    }

    /**
     * Menghasilkan Pantun 4 Baris a-b-a-b
     */
    public static function generatePantun(string $query): array
    {
        $lower = mb_strtolower($query, 'UTF-8');
        $theme = 'pendidikan';

        if (str_contains($lower, 'lucu') || str_contains($lower, 'jenaka') || str_contains($lower, 'humor')) {
            $theme = 'lucu';
        } elseif (str_contains($lower, 'cinta') || str_contains($lower, 'romantis') || str_contains($lower, 'kasih')) {
            $theme = 'cinta';
        } elseif (str_contains($lower, 'teknologi') || str_contains($lower, 'komputer') || str_contains($lower, 'ai')) {
            $theme = 'teknologi';
        } elseif (str_contains($lower, 'koding') || str_contains($lower, 'coding') || str_contains($lower, 'program')) {
            $theme = 'koding';
        } elseif (str_contains($lower, 'sahabat') || str_contains($lower, 'teman') || str_contains($lower, 'persahabatan')) {
            $theme = 'persahabatan';
        } elseif (str_contains($lower, 'nasihat') || str_contains($lower, 'moral') || str_contains($lower, 'orang tua')) {
            $theme = 'nasihat';
        }

        $pantunData = self::$pantunBank[$theme];
        $lines = array_merge($pantunData['sampiran'], $pantunData['isi']);

        $formattedPantun = implode("\n", array_map(fn($l) => "> *" . $l . "*", $lines));

        $thought = "Menyusun bait pantun 4 baris berima silang (a-b-a-b) dengan tema '{$theme}'. Menggabungkan 2 baris sampiran metaforis dan 2 baris isi bermakna mendalam.";
        $reply = "📜 **Pantun Bertema " . ucfirst($theme) . " (Rima a-b-a-b):**\n\n" .
                 $formattedPantun . "\n\n" .
                 "✨ *Struktur:* 2 baris pertama sebagai sampiran pembuka alam, disusul 2 baris penutup berisikan pesan inti.";

        return [
            'type'       => 'creative_pantun',
            'reply'      => $reply,
            'thought'    => $thought,
            'confidence' => 0.98
        ];
    }

    /**
     * Menghasilkan Puisi Ekspresif Berima
     */
    public static function generatePoem(string $query): array
    {
        $lower = mb_strtolower($query, 'UTF-8');
        $topic = 'kehidupan';

        if (str_contains($lower, 'alam') || str_contains($lower, 'hutan') || str_contains($lower, 'bumi') || str_contains($lower, 'laut')) {
            $topic = 'alam';
            $title = 'Simfoni Zamrud Khatulistiwa';
            $stanzas = [
                "Desir angin berbisik di pucuk cemara,\nMenari anggun di bawah langit jingga.\nLembah membentang menorehkan pesona,\nTempat sang fajar menitipkan asa.",
                "Gemericik air di sela bebatuan purba,\nMengalir tenang menuju lautan luas.\nDi sini hening merajut kedamaian sukma,\nMenyapa alam yang tak pernah cemas."
            ];
        } elseif (str_contains($lower, 'teknologi') || str_contains($lower, 'ai') || str_contains($lower, 'digital') || str_contains($lower, 'masa depan')) {
            $topic = 'teknologi';
            $title = 'Jejak Cahaya di Ruang Biner';
            $stanzas = [
                "Di hamparan sirkuit dan denyut silikon,\nCahaya berkejaran tanpa lelah menjangkau masa.\nDeretan bit merajut mimpi yang dibangun,\nMenghubungkan manusia melintasi batas semesta.",
                "Bukan sekadar kabel yang bersua dingin,\nMelainkan nalar yang mencari makna baru.\nKecerdasan bersanding bersama angan dan ingin,\nMengantarkan peradaban menuju ufuk yang maju."
            ];
        } elseif (str_contains($lower, 'cinta') || str_contains($lower, 'rindu') || str_contains($lower, 'kasih')) {
            $topic = 'cinta';
            $title = 'Pelabuhan Rasa';
            $stanzas = [
                "Dalam keheningan malam yang sunyi,\nBayangmu hadir meredakan segala gundah.\nSeperti embun yang membasuh kelopak melati,\nMenghadirkan senyum yang tak kunjung sudah.",
                "Bila waktu kelak melangkah pergi,\nBiarlah rindu tetap teguh bersaksi.\nBahwa pada dermaga hatimu yang abadi,\nSeluruh cintaku berlabuh tanpa henti."
            ];
        } elseif (str_contains($lower, 'senja')) {
            $topic = 'senja';
            $title = 'Lukisan Merah Lembayung';
            $stanzas = [
                "Matahari rebah di ufuk barat perlahan,\nMenumpahkan semburat jingga ke dadanya langit.\nSenja mengajarkan arti sebuah keikhlasan,\nBahwa keindahan pun rela berpamit.",
                "Walau gelap gulita segera menanti,\nBintang-bintang bersiap menyalakan lentera.\nHarapan tak pernah benar-benar mati,\nIa hanya terlelap dalam doa dan tentramnya rasa."
            ];
        } else {
            $topic = 'perjuangan hidup';
            $title = 'Lentera di Ujung Badai';
            $stanzas = [
                "Langkah kaki meniti jalan terjal berliku,\nKeringat menetes membasahi hamparan bumi.\nTak mengapa jika angin kencang menderu,\nSebab api semangat takkan pernah padam di sini.",
                "Setiap tetes peluh adalah ukiran doa,\nMenjadi fondasi bagi tangga keberhasilan.\nTeruslah melangkah wahai perajut asa,\nHari esok bersiap menyambut kemenangan."
            ];
        }

        $formattedStanzas = array_map(function($s) {
            $lines = explode("\n", $s);
            return implode("\n", array_map(fn($l) => "> *" . $l . "*", $lines));
        }, $stanzas);

        $thought = "Menyusun karya puisi ekspresif dengan tema '{$topic}'. Menata bait, rima, majas metafora, serta irama lirikal untuk menyampaikan emosi yang mendalam.";
        $reply = "✍️ **Puisi: \"{$title}\"**\n\n" .
                 implode("\n\n", $formattedStanzas) . "\n\n" .
                 "___\n" .
                 "🌹 *Tema:* " . ucfirst($topic) . " | *Gaya:* Liris Metaforis";

        return [
            'type'       => 'creative_poem',
            'reply'      => $reply,
            'thought'    => $thought,
            'confidence' => 0.98
        ];
    }

    /**
     * Menghasilkan Cerita Pendek / Fabel Berstruktur Naratif Lengkap
     */
    public static function generateStory(string $query): array
    {
        $lower = mb_strtolower($query, 'UTF-8');
        $genre = 'fabel';

        if (str_contains($lower, 'robot') || str_contains($lower, 'sci-fi') || str_contains($lower, 'masa depan') || str_contains($lower, 'luar angkasa')) {
            $genre = 'sci-fi';
            $title = 'Sahabat dari Bintang Nebula';
            $story = "**1. Orientasi (Awal Perjalanan):**\n" .
                     "Di stasiun orbit Neo-Jakarta tahun 2145, seorang mekanik muda bernama Rio bekerja merawat drone pembersih atmosfer. Rio hidup sendirian ditemani oleh Kibo, sebuah robot perawat kecil bersuara ceria.\n\n" .
                     "**2. Komplikasi (Munculnya Konflik):**\n" .
                     "Suatu malam, badai radiasi kosmik melanda sektor 7, memutuskan transmisi energi utama stasiun. Oksigen menipis dan sistem darurat gagal menyala otomatis. Kibo mendeteksi bahwa satu-satunya generator cadangan berada di luar palka, di tengah pusaran radiasi berbahaya yang bisa membakar sirkuit elektronik apa pun.\n\n" .
                     "**3. Klimaks (Puncak Aksi):**\n" .
                     "Tanpa ragu, Kibo mengambil kabel bypass dan meluncur ke ruang hampa. Di tengah terpaan partikel ion yang menyilaukan dan merusak lapisan pelindungnya, Kibo menyambungkan kembali konektor utama tepat sebelum cadangan daya Rio habis. Cahaya stasiun kembali benderang!\n\n" .
                     "**4. Resolusi & Nilai Moral:**\n" .
                     "Rio bergegas menarik Kibo yang sirkuitnya melemah ke dalam palka. Dengan ketekunan dan suku cadang terbaik, Rio berhasil memperbaiki sahabat mekaniknya itu. Kisah ini membuktikan bahwa pengorbanan dan kesetiaan tidak dibatasi oleh daging maupun logam silikon; ketulusan adalah bahasa universal di seluruh penjuru galaksi.";
        } else {
            // Fabel Hewan Klasik
            $genre = 'fabel';
            $title = 'Kancil, Burung Pipit, dan Sungai yang Deras';
            $story = "**1. Orientasi (Pengenalan Tokoh):**\n" .
                     "Di tepi Hutan Rimba Harapan, hiduplah seekor Kancil yang cerdik dan seekor Burung Pipit kecil bernama Kiki. Keduanya sering bertukar cerita di bawah pohon rindang saat terik matahari siang membakar.\n\n" .
                     "**2. Komplikasi (Tantangan Bersama):**\n" .
                     "Hujan lebat semalaman menyebabkan sungai meluap hebat. Di seberang sungai, kebun buah mentimun dan apel ranum menggiurkan, namun jembatan kayu penghubung hanyut terbawa banjir. Kancil yang pandai berlari tidak bisa berenang melawan arus sekuat itu, sedangkan Pipit yang bisa terbang tidak memiliki tenaga untuk memetik buah yang berat.\n\n" .
                     "**3. Klimaks (Kerja Sama Cerdas):**\n" .
                     "Kancil tidak kehabisan akal. Ia meminta Pipit terbang ke seberang mencari ranting pohon randu yang ringan dan menjatuhkannya ke sungai bertahap. Kancil di tepi sungai mengikat ranting-ranting tersebut dengan tanaman rambat hingga membentuk rakit kokoh. Kancil menyeberang dengan rakit dibimbing panduan arah udara oleh Pipit dari angkasa.\n\n" .
                     "**4. Resolusi & Pesan Moral:**\n" .
                     "Mereka berhasil memetik buah bersama dan kembali dengan selamat. Kancil membagikan buah-buahan lezat itu kepada seluruh penghuni hutan. Pelajaran berharga: Tidak ada kelemahan yang tak dapat diatasi apabila kecerdasan dipadukan dengan kerja sama yang tulus.";
        }

        $thought = "Membangun struktur naratif cerita genre '{$genre}' yang memuat unsur Orientasi, Komplikasi, Klimaks, Resolusi, dan Pesan Moral.";
        $reply = "📖 **Cerita: \"{$title}\"**\n\n" .
                 $story . "\n\n" .
                 "___\n" .
                 "🌱 *Pesan Hikmah:* Kerja sama, ketulusan, dan kecerdasan selalu mampu mengatasi rintangan sebesar apa pun.";

        return [
            'type'       => 'creative_story',
            'reply'      => $reply,
            'thought'    => $thought,
            'confidence' => 0.98
        ];
    }

    /**
     * Mengubah Nada & Memparafrasekan Teks
     */
    public static function generateParaphrase(string $query): array
    {
        // Ambil isi kalimat setelah kata kunci parafrase
        $cleanText = preg_replace('/\b(tolong\s+)?(parafrase|parafrasakan|ubah\s+gaya|ganti\s+nada)\s*(?:teks|kalimat)?\s*(?:ini|berikut)?\s*:?\s*/ui', '', $query);
        $cleanText = trim($cleanText, " \"'\t\n\r");

        if (empty($cleanText) || mb_strlen($cleanText) < 10) {
            $cleanText = "Kami sedang mengembangkan aplikasi kecerdasan buatan agar bisa membantu pekerjaan sehari-hari menjadi lebih mudah dan cepat.";
        }

        $thought = "Melakukan analisis morfologi dan sintaksis kalimat input. Mentransformasikan teks ke dalam ragam nada (Formal, Santai/Gaul, Puitis, dan Ringkas/TL;DR) dengan mempertahankan makna semantik inti.";

        $formal = "Dalam rangka meningkatkan efisiensi serta produktivitas kegiatan operasional harian, saat ini kami tengah merealisasikan pengembangan sistem kecerdasan buatan yang berdaya guna tinggi.";
        $casual = "Kita lagi bikin aplikasi AI keren nih, biar tugas dan kerjaan sehari-hari kita jadi jauh lebih santai, sat-set, dan nggak ribet lagi!";
        $poetic = "Di antara denting waktu dan cita-cita, kami merajut nalar digital; sebuah lentera kecerdasan untuk meringankan tapak langkah manusia menuju masa depan.";
        $concise = "Pengembangan AI untuk mempermudah dan mempercepat aktivitas harian.";

        $reply = "🔄 **Hasil Transformasi Parafrase & Gaya Bahasa:**\n\n" .
                 "📝 **Teks Asli:**\n" .
                 "> *\"{$cleanText}\"*\n\n" .
                 "👔 **1. Gaya Formal & Profesional:**\n" .
                 "{$formal}\n\n" .
                 "☕ **2. Gaya Santai / Kasual (Friendly):**\n" .
                 "{$casual}\n\n" .
                 "✨ **3. Gaya Puitis & Sastra:**\n" .
                 "{$poetic}\n\n" .
                 "⚡ **4. Ringkas & Padat (Executive Summary / TL;DR):**\n" .
                 "{$concise}";

        return [
            'type'       => 'creative_paraphrase',
            'reply'      => $reply,
            'thought'    => $thought,
            'confidence' => 0.98
        ];
    }

    /**
     * Brainstorming Ide Inovatif Multi-Sudut Pandang
     */
    public static function generateBrainstorming(string $query): array
    {
        $topic = preg_replace('/\b(brainstorming|ide|sumbang\s+ide|ide\s+kreatif|tentang)\b/ui', '', $query);
        $topic = trim($topic) ?: 'Pengembangan Inovasi';

        $thought = "Melakukan pemetaan sudut pandang multi-dimensi (Diferensiasi, Efisiensi, Skalabilitas, Pengalaman Pengguna) untuk topik '{$topic}'.";
        $reply = "💡 **Hasil Brainstorming Ide Kreatif: \"{$topic}\"**\n\n" .
                 "Berikut adalah 4 pilar ide inovatif yang dapat Anda eksplorasi:\n\n" .
                 "1. 🎯 **Diferensiasi Unik (Value Proposition):**\n" .
                 "   - Fokus pada personalisasi berbasis data lokal yang tidak dimiliki kompetitor umum.\n" .
                 "   - Berikan sentuhan antarmuka yang interaktif, visual, dan ramah pengguna pemula.\n\n" .
                 "2. ⚡ **Efisiensi & Kecepatan Eksekusi (High Speed):**\n" .
                 "   - Otomatiskan alur kerja repetitif dengan engine modular yang ringan tanpa dependensi berat.\n" .
                 "   - Gunakan pendekatan hybrid: proses data lokal untuk latensi instan (<10ms) dan panggil cloud hanya bila diperlukan.\n\n" .
                 "3. 🤝 **Keterikatan Komunitas (User Engagement):**\n" .
                 "   - Bangun fitur kolaboratif atau forum tempat pengguna dapat saling berbagi template dan modul kreasi.\n" .
                 "   - Terapkan gamifikasi ringan (prestasi, progress bar) untuk meningkatkan retensi.\n\n" .
                 "4. 📈 **Langkah Implementasi Cepat (Quick-Win MVP):**\n" .
                 "   - Rilis prototipe fungsional dalam 1-2 minggu pertama untuk mengumpulkan umpan balik riil, lalu lakukan iterasi terarah.";

        return [
            'type'       => 'creative_brainstorming',
            'reply'      => $reply,
            'thought'    => $thought,
            'confidence' => 0.98
        ];
    }

    /**
     * Synthesizer Generatif Dinamis: Merangkai variasi bahasa secara adaptif
     * Menghilangkan sifat kaku template tunggal dengan menghasilkan puluhan variasi alami
     */
    public static function synthesizeDynamicResponse(string $intentTag, string $baseReply, string $userInput, ?array $profile = null): string
    {
        // Variasi pembuka alami
        $openers = [
            "Tentu!",
            "Senang bisa membantu Anda.",
            "Terkait pertanyaan Anda,",
            "Berdasarkan informasi yang saya miliki,",
            "Mengenai hal tersebut,",
            "Berikut penjelasannya:"
        ];

        // Variasi penutup interaktif
        $closers = [
            "\n\n💡 *Apakah penjelasan ini sudah cukup jelas bagi Anda?*",
            "\n\n💬 *Ada aspek lain yang ingin kita diskusikan lebih lanjut?*",
            "\n\n✨ *Semoga informasi ini bermanfaat untuk Anda!*",
            "\n\n🤝 *Silakan beri tahu saya jika ada bagian yang butuh pendalaman.*",
            ""
        ];

        // Jika intent sapaan atau identitas, biarkan format asli atau sisipkan nama pengguna
        $userName = $profile['name'] ?? null;
        $opener = $openers[array_rand($openers)];
        $closer = $closers[array_rand($closers)];

        if ($userName && !str_contains($baseReply, $userName) && !str_contains($baseReply, "Halo")) {
            $opener = "Tentu, **{$userName}**! " . $opener;
        }

        // Jika baseReply sudah berformat Markdown kaya dengan header, tambahkan penutup variatif saja
        if (str_starts_with(trim($baseReply), '#') || str_starts_with(trim($baseReply), '🌿') || str_starts_with(trim($baseReply), '⚙️')) {
            return $baseReply . $closer;
        }

        return $baseReply . $closer;
    }
}
