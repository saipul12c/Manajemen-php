<?php

namespace Sentinel\NLP;

class KnowledgeBank
{
    /**
     * Cari kecocokan konsep dalam basis pengetahuan ensiklopedia umum
     * Menggunakan Pencocokan Pola + Ekspansi Sinonim Semantik (Semantic Embeddings)
     */
    public static function search(string $text): ?array
    {
        $lower = mb_strtolower(trim($text), 'UTF-8');
        $expanded = SemanticEmbeddings::expandQuery($lower);

        $entries = self::getEncyclopedia();

        $bestScore = 0.0;
        $bestEntry = null;

        foreach ($entries as $entry) {
            $score = 0.0;

            // 1. Cek kecocokan pola eksak atau substring pada teks asli & teks terekspansi
            foreach ($entry['keywords'] as $kw) {
                $kwLower = mb_strtolower($kw, 'UTF-8');
                if (str_contains($lower, $kwLower)) {
                    $score += 0.50;
                } elseif (str_contains($expanded, $kwLower)) {
                    $score += 0.35;
                }
            }

            // 2. Cek kesamaan kata dasar & sinonim semantik
            $tokens = TextProcessor::tokenize($expanded, true);
            foreach ($tokens as $token) {
                if (in_array($token, $entry['tags'], true)) {
                    $score += 0.25;
                } else {
                    $syns = SemanticEmbeddings::getSynonyms($token);
                    foreach ($syns as $syn) {
                        if (in_array($syn, $entry['tags'], true)) {
                            $score += 0.15;
                            break;
                        }
                    }
                }
            }

            // 3. Skor kedekatan semantik umum
            $semanticSim = SemanticEmbeddings::calculateSemanticScore($lower, implode(' ', $entry['keywords']) . ' ' . implode(' ', $entry['tags']));
            $score += $semanticSim * 0.30;

            if ($score > $bestScore) {
                $bestScore = $score;
                $bestEntry = $entry;
            }
        }

        if ($bestScore >= 0.40 && $bestEntry !== null) {
            return [
                'thought'    => $bestEntry['thought'],
                'reply'      => $bestEntry['content'],
                'confidence' => min(0.98, round($bestScore, 2)),
                'topic'      => $bestEntry['topic']
            ];
        }

        return null;
    }

    /**
     * Ensiklopedia terstruktur sains, sejarah, teknologi, AI, geografi
     */
    protected static function getEncyclopedia(): array
    {
        return [
            // SAINS & BIOLOGI
            [
                'topic'    => 'fotosintesis',
                'keywords' => ['fotosintesis', 'proses fotosintesis', 'cara tumbuhan membuat makanan'],
                'tags'     => ['fotosintesis', 'tumbuhan', 'klorofil', 'karbondioksida', 'oksigen', 'daun'],
                'thought'  => "Pertanyaan mengenai proses biokimia fotosintesis pada tumbuhan hijau dan alga.",
                'content'  => "🌿 **Fotosintesis** adalah proses biokimia di mana tumbuhan hijau, alga, dan beberapa bakteri mengubah energi cahaya matahari menjadi energi kimia dalam bentuk glukosa (makanan).\n\n" .
                              "### Persamaan Kimia Fotosintesis:\n" .
                              '$$6\text{CO}_2 + 6\text{H}_2\text{O} + \text{Cahaya} \longrightarrow \text{C}_6\text{H}_{12}\text{O}_6 + 6\text{O}_2$$' . "\n\n" .
                              "- **Bahan Baku:** Karbon dioksida (\$CO_2\$) dari udara dan air (\$H_2O\$) dari tanah.\n" .
                              "- **Katalis:** Klorofil (zat hijau daun) di dalam organel kloroplas menyerap foton cahaya matahari.\n" .
                              "- **Hasil Akhir:** Glukosa (energi untuk tumbuhan) dan Oksigen (\$O_2\$) yang dilepaskan ke atmosfer untuk kehidupan makhluk hidup."
            ],
            [
                'topic'    => 'dna_genetika',
                'keywords' => ['apa itu dna', 'fungsi dna', 'struktur dna', 'genetika', 'kromosom'],
                'tags'     => ['dna', 'rna', 'genetika', 'heliks', 'nukleotida', 'kromosom'],
                'thought'  => "Menjelaskan molekul pembawa materi genetik DNA (Deoxyribonucleic Acid).",
                'content'  => "🧬 **DNA (Deoxyribonucleic Acid / Asam Deoksiribonukleat)** adalah molekul pembawa kode genetik yang menginstruksikan perkembangan, fungsi, pertumbuhan, dan reproduksi seluruh makhluk hidup.\n\n" .
                              "- **Bentuk Molekul:** Berupa rantai ganda berpilin (*double helix*) yang ditemukan oleh James Watson dan Francis Crick pada tahun 1953.\n" .
                              "- **Basa Nitrogen:** Tersusun dari 4 basa nukleotida yaitu Adenin (A), Timin (T), Guanin (G), dan Sitosin (C). Di mana A berpasangan dengan T, dan G berpasangan dengan C.\n" .
                              "- **Fungsi:** Menyimpan cetak biru biologis untuk sintesis protein dan pewarisan sifat dari orang tua ke keturunan."
            ],
            [
                'topic'    => 'gravitasi',
                'keywords' => ['gravitasi', 'apa itu gravitasi', 'hukum gravitasi', 'gaya tarik bumi'],
                'tags'     => ['gravitasi', 'newton', 'einstein', 'bumi', 'massa', 'ruang'],
                'thought'  => "Menjelaskan fenomena gravitasi dari perspektif Newton dan Teori Relativitas Umum Einstein.",
                'content'  => "🌍 **Gravitasi** adalah fenomena alam di mana semua objek bermassa saling tarik-menarik:\n\n" .
                              "1. **Pandangan Klasik (Isaac Newton):** Gaya tarik gravitasi berbanding lurus dengan hasil kali kedua massa dan berbanding terbalik dengan kuadrat jaraknya:\n" .
                              '   $$F = G \frac{m_1 m_2}{r^2}$$' . "\n" .
                              "2. **Relativitas Umum (Albert Einstein):** Gravitasi bukanlah sekadar gaya tak terlihat, melainkan manifestasi dari **kelengkungan ruang dan waktu (spacetime curvature)** yang disebabkan oleh keberadaan massa dan energi."
            ],
            [
                'topic'    => 'tata_surya_mars',
                'keywords' => ['planet mars', 'tata surya', 'tiket ke mars', 'planet merah', 'ke mars'],
                'tags'     => ['mars', 'planet', 'surya', 'astronomi', 'luar angkasa', 'nasa', 'spacex'],
                'thought'  => "Pertanyaan mengenai planet Mars atau eksplorasi luar angkasa.",
                'content'  => "🚀 **Planet Mars (Planet Merah)** adalah planet keempat dari Matahari di Tata Surya kita:\n\n" .
                              "- **Jarak dari Bumi:** Sekitar 55 hingga 400 juta kilometer tergantung orbit.\n" .
                              "- **Kondisi:** Memiliki atmosfer tipis (95% karbon dioksida), suhu rata-rata -60°C, dan permukaan kaya oksida besi (karat) yang memberinya warna kemerahan.\n" .
                              "- **Penerbangan ke Mars:** Saat ini belum ada penerbangan komersial umum ke Mars. Lembaga seperti NASA dan SpaceX sedang mengembangkan teknologi pesawat luar angkasa (seperti Starship) dengan misi penjelajahan manusia berawak yang ditargetkan di dekade mendatang."
            ],
            [
                'topic'    => 'black_hole',
                'keywords' => ['black hole', 'lubang hitam', 'apa itu black hole', 'apa itu lubang hitam', 'singularity'],
                'tags'     => ['black', 'hole', 'lubang', 'hitam', 'astronomi', 'gravitasi', 'singularity', 'hawking'],
                'thought'  => "Menjelaskan objek astrofisika lubang hitam (black hole), singularitas, dan cakrawala peristiwa.",
                'content'  => "🕳️ **Lubang Hitam (Black Hole)** adalah wilayah di ruang angkasa dengan medan gravitasi yang luar biasa kuat sehingga tidak ada materi atau radiasi elektromagnetik (bahkan cahaya) yang dapat lolos darinya:\n\n" .
                              "- **Cakrawala Peristiwa (Event Horizon):** Batas terluar di mana kecepatan lepas (*escape velocity*) sama dengan kecepatan cahaya. Segala sesuatu yang melintasi batas ini tidak dapat kembali.\n" .
                              "- **Singularitas:** Titik pusat di mana seluruh massa terkonsentrasi ke dalam volume yang mendekati nol, menghasilkan kerapatan dan kelengkungan ruang-waktu tak terhingga.\n" .
                              "- **Pembentukan:** Sebagian besar lubang hitam terbentuk dari sisa-sisa ledakan supernova bintang masif di akhir masa hidupnya."
            ],

            // TEKNOLOGI & ARTIFICIAL INTELLIGENCE
            [
                'topic'    => 'perbedaan_ml_dl',
                'keywords' => ['perbedaan machine learning dan deep learning', 'machine learning vs deep learning', 'apa bedanya ml dan dl'],
                'tags'     => ['machine', 'learning', 'deep', 'ai', 'neural', 'network'],
                'thought'  => "Menganalisis perbedaan teknis antara Machine Learning (ML konvensional) dan Deep Learning (DL).",
                'content'  => "🤖 **Perbedaan Machine Learning (ML) vs Deep Learning (DL):**\n\n" .
                              "| Kriteria | Machine Learning Tradisional | Deep Learning |\n" .
                              "|---|---|---|\n" .
                              "| **Arsitektur** | Algoritma statistik (Regresi, SVM, Decision Tree, Random Forest) | Jaringan Saraf Tiruan Dalam (Multi-layer DNN, CNN, Transformer) |\n" .
                              "| **Feature Engineering** | Memerlukan ekstraksi fitur manual oleh manusia | Otomatis mempelajari representasi fitur langsung dari data mentah |\n" .
                              "| **Kebutuhan Data** | Bekerja baik pada dataset kecil hingga menengah | Membutuhkan volume data masif (Big Data) agar optimal |\n" .
                              "| **Komputasi** | Cukup dijalankan di CPU | Memerlukan GPU/TPU berkecepatan tinggi |\n" .
                              "| **Interpretabilitas** | Lebih mudah dijelaskan (*White-box*) | Cenderung seperti kotak hitam (*Black-box*) |"
            ],
            [
                'topic'    => 'cpu_vs_ram',
                'keywords' => ['perbedaan cpu dan ram', 'cpu vs ram', 'apa beda cpu dan ram', 'fungsi cpu dan ram'],
                'tags'     => ['cpu', 'ram', 'processor', 'memori', 'hardware', 'komputer'],
                'thought'  => "Menerangkan arsitektur Von Neumann, peran CPU sebagai pemroses dan RAM sebagai memori jangka pendek.",
                'content'  => "💻 **Perbedaan Antara CPU dan RAM:**\n\n" .
                              "- **CPU (Central Processing Unit):**\n" .
                              "  - Berfungsi sebagai **otak utama** komputer yang melakukan eksekusi instruksi, kalkulasi aritmatika, dan pengendalian alur logika program.\n" .
                              "  - Kecepatan diukur dalam Gigahertz (GHz).\n\n" .
                              "- **RAM (Random Access Memory):**\n" .
                              "  - Berfungsi sebagai **tempat penyimpanan sementara (volatile)** berkecepatan ultra tinggi untuk menampung data dan aplikasi yang sedang aktif diproses CPU.\n" .
                              "  - Data akan hilang seketika saat komputer dimatikan atau di-restart.\n\n" .
                              "**Analogi Sederhana:** CPU adalah koki yang memasak dengan cepat, sedangkan RAM adalah meja dapur tempat meletakkan bahan-bahan masakan yang sedang diolah."
            ],
            [
                'topic'    => 'cara_kerja_sentinel',
                'keywords' => ['bagaimana cara kerja kamu', 'arsitektur sentinel', 'cara kerja sentinel ai', 'bagaimana sistem ini dibuat'],
                'tags'     => ['arsitektur', 'sistem', 'cara', 'kerja', 'sentinel', 'mesin'],
                'thought'  => "Menjelaskan arsitektur teknis sistem Sentinel AI secara transparan.",
                'content'  => "⚙️ **Arsitektur Internal Sentinel AI:**\n\n" .
                              "Sentinel AI dibangun murni menggunakan **PHP 8.x** dengan pendekatan berlapis (*Multi-Layer Pipeline*):\n\n" .
                              "1. **Natural Language Processing (NLP):**\n" .
                              "   - Pembersihan teks, tokenisasi, n-grams, dan penghilangan stopwords.\n" .
                              "   - Pembobotan **TF-IDF (Term Frequency - Inverse Document Frequency)** dan kalkulasi vektor kemiripan **Cosine Similarity**.\n" .
                              "2. **Reasoning & Math Engine:**\n" .
                              "   - Parser aritmatika Infix-ke-Postfix (Shunting-yard algorithm) untuk memecahkan persamaan matematika secara instan.\n" .
                              "3. **Neural Network Multi-Layer Perceptron (MLP):**\n" .
                              "   - Implementasi forward propagation, fungsi aktivasi Sigmoid, dan backpropagation dengan Gradient Descent murni di PHP.\n" .
                              "4. **Decision Tree (ID3 Classifier):**\n" .
                              "   - Pembelajaran berbasis pohon keputusan menggunakan kalkulasi Entropi Shannon dan Information Gain.\n" .
                              "5. **Persistent JSON Memory:**\n" .
                              "   - Pengetahuan baru dan riwayat model disimpan dalam struktur JSON transparan tanpa dependensi database eksternal."
            ],

            // SEJARAH & INDONESIA
            [
                'topic'    => 'proklamasi_kemerdekaan',
                'keywords' => ['kemerdekaan indonesia', 'kapan indonesia merdeka', 'proklamasi', 'hari kemerdekaan'],
                'tags'     => ['indonesia', 'merdeka', 'proklamasi', 'sukarno', 'agustus', '1945'],
                'thought'  => "Menjawab sejarah proklamasi kemerdekaan Republik Indonesia.",
                'content'  => "🇮🇩 **Kemerdekaan Republik Indonesia:**\n\n" .
                              "Indonesia memproklamasikan kemerdekaannya pada hari **Jumat, 17 Agustus 1945** (bertepatan dengan bulan Ramadan 1364 H).\n\n" .
                              "- **Lokasi:** Jalan Pegangsaan Timur No. 56, Jakarta Pusat (kediaman Bung Karno).\n" .
                              "- **Pembaca Teks:** Ir. Soekarno didampingi oleh Drs. Mohammad Hatta atas nama bangsa Indonesia.\n" .
                              "- **Pengetik Naskah:** Sayuti Melik.\n" .
                              "- **Penjahit Bendera Pusaka:** Ibu Fatmawati (Sang Saka Merah Putih)."
            ],
            [
                'topic'    => 'presiden_indonesia',
                'keywords' => ['siapa presiden pertama indonesia', 'daftar presiden indonesia', 'presiden ri', 'presiden indonesia pertama'],
                'tags'     => ['presiden', 'indonesia', 'soekarno', 'pertama', 'ri', 'soeharto', 'jokowi', 'prabowo'],
                'thought'  => "Menyajikan daftar sejarah Presiden Republik Indonesia dari masa ke masa.",
                'content'  => "🇮🇩 **Presiden Republik Indonesia Pertama & Daftar Kepala Negara:**\n\n" .
                              "- **Presiden Pertama:** **Ir. Soekarno** (Bung Karno), menjabat sejak 18 Agustus 1945 didampingi Wakil Presiden Drs. Mohammad Hatta.\n\n" .
                              "### Garis Waktu Presiden Republik Indonesia:\n" .
                              "1. **Ir. Soekarno** (1945 – 1967)\n" .
                              "2. **Soeharto** (1967 – 1998)\n" .
                              "3. **B.J. Habibie** (1998 – 1999)\n" .
                              "4. **Abdurrahman Wahid (Gus Dur)** (1999 – 2001)\n" .
                              "5. **Megawati Soekarnoputri** (2001 – 2004)\n" .
                              "6. **Susilo Bambang Yudhoyono (SBY)** (2004 – 2014)\n" .
                              "7. **Joko Widodo (Jokowi)** (2014 – 2024)\n" .
                              "8. **Prabowo Subianto** (2024 – Sekarang)"
            ],
            [
                'topic'    => 'pancasila',
                'keywords' => ['apa itu pancasila', 'sebutkan sila pancasila', '5 sila pancasila', 'dasar negara indonesia'],
                'tags'     => ['pancasila', 'sila', 'dasar', 'negara', 'garuda', 'semboyan'],
                'thought'  => "Menyajikan 5 sila Pancasila sebagai ideologi dan dasar falsafah negara Republik Indonesia.",
                'content'  => "🦅 **Pancasila adalah Dasar dan Ideologi Negara Republik Indonesia:**\n\n" .
                              "1. **Ketuhanan Yang Maha Esa** (Simbol: Bintang)\n" .
                              "2. **Kemanusiaan yang Adil dan Beradab** (Simbol: Rantai Emas)\n" .
                              "3. **Persatuan Indonesia** (Simbol: Pohon Beringin)\n" .
                              "4. **Kerakyatan yang Dipimpin oleh Hikmat Kebijaksanaan dalam Permusyawaratan/Perwakilan** (Simbol: Kepala Banteng)\n" .
                              "5. **Keadilan Sosial bagi Seluruh Rakyat Indonesia** (Simbol: Padi dan Kapas)\n\n" .
                              "**Semboyan Negara:** *Bhinneka Tunggal Ika* (Berbeda-beda tetapi tetap satu jua)."
            ],
            [
                'topic'    => 'ibukota_nusantara',
                'keywords' => ['apa ibukota indonesia', 'ibukota indonesia adalah', 'dimana ibukota indonesia', 'ibukota ri sekarang'],
                'tags'     => ['ibukota', 'indonesia', 'nusantara', 'ikn', 'jakarta', 'kalimantan'],
                'thought'  => "Menjelaskan status ibukota Indonesia terkini (IKN Nusantara & DKI Jakarta).",
                'content'  => "🏛️ Berdasarkan Undang-Undang No. 3 Tahun 2022 dan regulasi terkait, Ibukota Negara Republik Indonesia ditetapkan di **Ibu Kota Nusantara (IKN)** yang berlokasi di Kabupaten Penajam Paser Utara dan Kutai Kartanegara, Kalimantan Timur, menggantikan peran historis DKI Jakarta sebagai pusat pemerintahan nasional."
            ],

            // GEOGRAFI DUNIA
            [
                'topic'    => 'ibukota_dunia',
                'keywords' => ['ibukota jepang', 'ibukota perancis', 'ibukota prancis', 'ibukota amerika', 'ibukota inggris', 'ibukota australia', 'ibukota korea selatan'],
                'tags'     => ['ibukota', 'jepang', 'tokyo', 'prancis', 'paris', 'amerika', 'washington', 'london'],
                'thought'  => "Menjawab pertanyaan mengenai ibukota negara-negara di dunia.",
                'content'  => "🌐 **Daftar Ibukota Negara Terkemuka di Dunia:**\n\n" .
                              "- 🇯🇵 **Jepang:** Tokyo\n" .
                              "- 🇺🇸 **Amerika Serikat:** Washington, D.C.\n" .
                              "- 🇬🇧 **Inggris (UK):** London\n" .
                              "- 🇫🇷 **Prancis:** Paris\n" .
                              "- 🇩🇪 **Jerman:** Berlin\n" .
                              "- 🇰🇷 **Korea Selatan:** Seoul\n" .
                              "- 🇦🇺 **Australia:** Canberra\n" .
                              "- 🇸🇦 **Arab Saudi:** Riyadh\n" .
                              "- 🇨🇳 **Tiongkok / China:** Beijing\n" .
                              "- 🇷🇺 **Rusia:** Moskow\n" .
                              "- 🇸🇬 **Singapura:** Singapura\n" .
                              "- 🇲🇾 **Malaysia:** Kuala Lumpur"
            ],

            // MOTIVASI & PRODUKTIVITAS
            [
                'topic'    => 'motivasi_belajar',
                'keywords' => ['beri saya motivasi', 'kata motivasi', 'saya sedang lelah', 'butuh semangat', 'tips belajar'],
                'tags'     => ['motivasi', 'semangat', 'lelah', 'sukses', 'belajar', 'tips'],
                'thought'  => "Memberikan dukungan moral dan kata motivasi yang membangkitkan semangat.",
                'content'  => "✨ *\"Perjalanan seribu mil dimulai dengan satu langkah kecil.\"* — Lao Tzu\n\n" .
                              "Wajar jika Anda merasa lelah atau jenuh sesekali. Ingatlah bahwa:\n" .
                              "1. **Konsistensi mengalahkan intensitas:** Melangkah 1% lebih baik setiap hari akan melipatgandakan kemampuan Anda 37 kali lipat dalam setahun.\n" .
                              "2. **Kesalahan adalah data:** Setiap kegagalan atau bug adalah peluang belajar untuk menjadi versi yang lebih tangguh.\n" .
                              "3. **Istirahat bukan berarti menyerah:** Ambil jeda sejenak, tarik napas dalam-dalam, lalu lanjutkan langkah Anda.\n\n" .
                              "Anda mampu melewatinya! Saya di sini siap mendampingi Anda."
            ]
        ];
    }
}
