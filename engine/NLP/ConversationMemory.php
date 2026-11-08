<?php

namespace Sentinel\NLP;

class ConversationMemory
{
    protected string $sessionId;
    protected string $storageDir;
    protected ?\PDO $pdo = null;
    protected array $data = [];

    public function __construct(?string $sessionId = 'default_session', ?string $storageDir = null, ?\PDO $pdo = null)
    {
        // Bersihkan session ID agar aman
        $cleanId = preg_replace('/[^a-zA-Z0-9_\-]/', '_', $sessionId ?: 'default_session');
        $this->sessionId = $cleanId;
        $this->storageDir = $storageDir ?: __DIR__ . '/../../data/sessions';
        $this->pdo = $pdo;

        // Jangan buat storageDir di disk jika PDO tersedia (100% MySQL Database)
        if ($this->pdo === null) {
            $this->ensureStorageDir();
        }
        $this->loadSession();
    }

    public function setPDO(?\PDO $pdo): void
    {
        $this->pdo = $pdo;
    }

    protected function ensureStorageDir(): void
    {
        if (!is_dir($this->storageDir)) {
            @mkdir($this->storageDir, 0777, true);
        }
    }

    protected function getFilePath(): string
    {
        return rtrim($this->storageDir, '/\\') . DIRECTORY_SEPARATOR . $this->sessionId . '.json';
    }

    /**
     * Memuat data sesi dari database MySQL (atau file JSON sebagai fallback darurat jika offline)
     */
    protected function loadSession(): void
    {
        // 1. Prioritas Utama: Muat langsung dari tabel MySQL sentinel_nlp_sessions
        if ($this->pdo !== null) {
            try {
                $stmt = $this->pdo->prepare("SELECT memory_data FROM sentinel_nlp_sessions WHERE session_id = ? LIMIT 1");
                $stmt->execute([$this->sessionId]);
                $row = $stmt->fetch(\PDO::FETCH_ASSOC);
                if ($row && !empty($row['memory_data'])) {
                    $decoded = json_decode($row['memory_data'], true);
                    if (is_array($decoded)) {
                        $this->data = $decoded;
                        $this->data['last_entity'] = $this->data['last_entity'] ?? null;
                        $this->data['entities'] = $this->data['entities'] ?? [];
                        $this->data['emotional_trajectory'] = $this->data['emotional_trajectory'] ?? [];
                        $this->data['episodic_fragments'] = $this->data['episodic_fragments'] ?? [];
                        return;
                    }
                }
            } catch (\Exception $e) {
                // Defensive fallback
            }
        } else {
            $file = $this->getFilePath();
            if (file_exists($file)) {
                $content = @file_get_contents($file);
                $decoded = json_decode($content, true);
                if (is_array($decoded)) {
                    $this->data = $decoded;
                    $this->data['last_entity'] = $this->data['last_entity'] ?? null;
                    $this->data['entities'] = $this->data['entities'] ?? [];
                    $this->data['emotional_trajectory'] = $this->data['emotional_trajectory'] ?? [];
                    $this->data['episodic_fragments'] = $this->data['episodic_fragments'] ?? [];
                    return;
                }
            }
        }

        // Inisialisasi struktur sesi baru yang bersih
        $this->data = [
            'session_id'           => $this->sessionId,
            'created_at'           => date('Y-m-d H:i:s'),
            'updated_at'           => date('Y-m-d H:i:s'),
            'profile'              => [
                'name'             => null,
                'role'             => null,
                'location'         => null,
                'hobby'            => null,
                'skills'           => []
            ],
            'last_entity'          => null,
            'entities'             => [],
            'facts'                => [],
            'session_learned'      => [],
            'topics'               => [],
            'emotional_trajectory' => [],
            'episodic_fragments'   => []
        ];
    }

    /**
     * Simpan status sesi eksklusif ke tabel MySQL sentinel_nlp_sessions
     */
    public function saveSession(): bool
    {
        $this->data['updated_at'] = date('Y-m-d H:i:s');
        $json = json_encode($this->data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

        // 1. Simpan eksklusif ke database MySQL (atau SQLite saat pengujian)
        if ($this->pdo !== null) {
            try {
                $driver = $this->pdo->getAttribute(\PDO::ATTR_DRIVER_NAME);
                if ($driver === 'sqlite') {
                    $stmt = $this->pdo->prepare("
                        INSERT INTO sentinel_nlp_sessions (session_id, memory_data, updated_at) 
                        VALUES (?, ?, datetime('now')) 
                        ON CONFLICT(session_id) DO UPDATE SET memory_data = excluded.memory_data, updated_at = datetime('now')
                    ");
                } else {
                    $stmt = $this->pdo->prepare("
                        INSERT INTO sentinel_nlp_sessions (session_id, memory_data, updated_at) 
                        VALUES (?, ?, NOW()) 
                        ON DUPLICATE KEY UPDATE memory_data = VALUES(memory_data), updated_at = NOW()
                    ");
                }
                return $stmt->execute([$this->sessionId, $json]);
            } catch (\Exception $e) {
                return false;
            }
        }

        // Fallback hanya jika sistem dijalankan tanpa koneksi database sama sekali
        $file = $this->getFilePath();
        return (bool) @file_put_contents($file, $json, LOCK_EX);
    }

    /**
     * Ekstrak fakta profil pengguna dari kalimat (nama, profesi, domisili, hobi, dan fakta eksplisit)
     */
    public function extractFacts(string $text): array
    {
        $extracted = [];
        $clean = trim($text);

        // 1. Deteksi Nama: "nama saya Budi", "namaku Siti", "panggil aku Kevin", "saya Kevin"
        // Hindari false positive jika pengguna justru sedang BERTANYA ("siapa nama saya?", "kamu tahu nama saya?")
        $isAskingName = (bool) preg_match('/\b(?:siapa|apakah|tahukah|kamu\s+tahu)\s+(?:nama\s+(?:saya|aku)|namaku)\b/ui', $clean);
        if (!$isAskingName) {
            if (preg_match('/\b(?:nama\s+(?:saya|aku)|namaku|panggil\s+(?:saya|aku))\s+(?:adalah\s+)?([A-Z][a-zA-Z0-9_\-]{1,20}(?:\s+[A-Z][a-zA-Z0-9_\-]{1,20})?)/u', $clean, $m)) {
                $nameCandidate = trim($m[1]);
                $lower = mb_strtolower($nameCandidate);
                if (!in_array($lower, ['siapa', 'apa', 'adalah', 'robot', 'sentinel', 'orang', 'pengguna', 'user', 'dan', 'yang'])) {
                    $nameFormatted = ucwords($lower);
                    $this->data['profile']['name'] = $nameFormatted;
                    $extracted['name'] = $nameFormatted;
                }
            } elseif (preg_match('/\b(?:nama\s+(?:saya|aku)|namaku|panggil\s+(?:saya|aku))\s+(?:adalah\s+)?([a-zA-Z]{2,20})\b/ui', $clean, $m)) {
                $nameCandidate = trim($m[1]);
                $lower = mb_strtolower($nameCandidate);
                if (!in_array($lower, ['siapa', 'apa', 'adalah', 'robot', 'sentinel', 'orang', 'pengguna', 'user', 'dan', 'yang'])) {
                    $nameFormatted = ucwords($lower);
                    $this->data['profile']['name'] = $nameFormatted;
                    $extracted['name'] = $nameFormatted;
                }
            } elseif (empty($this->data['profile']['name']) && preg_match('/^(?:halo|hai|salam)?\s*(?:(?:saya|aku)\s+)([a-zA-Z]{3,20})(?:[,\.!\s]|$)/ui', $clean, $m)) {
                $candidate = trim($m[1]);
                $lower = mb_strtolower($candidate);
                if (!in_array($lower, ['adalah', 'seorang', 'ingin', 'mau', 'bisa', 'punya', 'hanya', 'lagi', 'sedang', 'bernama', 'memiliki', 'suka', 'tinggal', 'bekerja', 'baru'])) {
                    $nameFormatted = ucwords($lower);
                    $this->data['profile']['name'] = $nameFormatted;
                    $extracted['name'] = $nameFormatted;
                }
            }
        }

        // 2. Deteksi Profesi / Peran: "saya seorang dokter", "pekerjaan saya programmer", "saya bekerja sebagai desainer"
        if (preg_match('/\b(?:pekerjaan\s+(?:saya|aku)|profesi\s+(?:saya|aku)|bekerja\s+sebagai|saya\s+(?:seorang|adalah))\s+([a-zA-Z\s]{3,35})\b/ui', $clean, $m)) {
            $roleCandidate = trim($m[1]);
            $lower = mb_strtolower($roleCandidate);
            if (!in_array($lower, ['orang', 'manusia', 'user', 'robot', 'budi', 'siti'])) {
                $this->data['profile']['role'] = ucwords($lower);
                $extracted['role'] = ucwords($lower);
            }
        }

        // 3. Deteksi Lokasi / Domisili: "saya tinggal di Jakarta", "domisili saya di Bandung", "asal saya dari Surabaya"
        if (preg_match('/\b(?:tinggal\s+di|domisili(?:\s+saya)?\s+di|asal(?:\s+saya)?\s+dari)\s+([a-zA-Z\s]{3,25})\b/ui', $clean, $m)) {
            $loc = ucwords(mb_strtolower(trim($m[1])));
            $this->data['profile']['location'] = $loc;
            $extracted['location'] = $loc;
        }

        // 4. Deteksi Hobi / Minat: "hobi saya membaca", "saya suka memancing", "saya gemar koding"
        if (preg_match('/\b(?:hobi\s+(?:saya|aku)|saya\s+suka|gemar)\s+([a-zA-Z\s]{3,30})\b/ui', $clean, $m)) {
            $hobby = trim($m[1]);
            $lower = mb_strtolower($hobby);
            if (!in_array($lower, ['kamu', 'anda', 'tanya', 'bicara', 'makan', 'minum'])) {
                $this->data['profile']['hobby'] = ucfirst($lower);
                $extracted['hobby'] = ucfirst($lower);
            }
        }

        // 5. Perintah Ingat Eksplisit: "ingat bahwa kucing saya namanya Miko", "catat bahwa proyek saya bernama Alpha"
        if (preg_match('/\b(?:ingat(?:lah)?\s+(?:bahwa|ya|ini)?|catat(?:\s+bahwa)?)\s*[:,\s]\s*([^\.\n]+)/ui', $clean, $m)) {
            $fact = trim($m[1]);
            if (mb_strlen($fact) > 3) {
                if (!in_array($fact, $this->data['facts'], true)) {
                    $this->data['facts'][] = $fact;
                    $extracted['fact_saved'] = $fact;
                }
            }
        }

        // 6. Deteksi Fakta Entitas Alami (Hewan Peliharaan, Rekan, Keluarga, Proyek)
        // Contoh: "kucing saya bernama Milo yang suka ikan salmon", "anjing saya namanya Bruno", "teman saya Budi"
        if (preg_match('/\b((?:kucing|anjing|kelinci|burung|ikan|peliharaan|hewan|istri|suami|anak|teman|rekan|sahabat|proyek|tim|perusahaan)\s+(?:saya|aku|kami)\s+(?:namanya|bernama|adalah|[^\.\n,]{1,20}\s+bernama)\s+([^\.\n]+))/ui', $clean, $m)) {
            $rawFact = trim($m[1]);
            if (mb_strlen($rawFact) > 5) {
                if (!in_array($rawFact, $this->data['facts'], true)) {
                    $this->data['facts'][] = $rawFact;
                    $extracted['fact_saved'] = $rawFact;
                }
                if (preg_match('/\b(?:namanya|bernama)\s+([A-Z][a-zA-Z0-9_\-]*)/u', $rawFact, $eMatch)) {
                    $entityName = $eMatch[1];
                    $this->data['last_entity'] = $entityName;
                    $extracted['entity_name'] = $entityName;

                    // Ekstraksi kategori dan atribut untuk Knowledge Graph (EAV)
                    $category = 'entitas';
                    if (preg_match('/\b(kucing|anjing|kelinci|burung|ikan|peliharaan|hewan|istri|suami|anak|teman|rekan|proyek)\b/ui', $rawFact, $cMatch)) {
                        $category = mb_strtolower($cMatch[1]);
                    }

                    $attributes = [];
                    if (preg_match('/\b(?:suka|sukanya|makan)\s+([^\.,\n]+)/ui', $rawFact, $aMatch)) {
                        $attributes['makanan'] = trim($aMatch[1]);
                    }
                    if (preg_match('/\b(?:bekerja\s+sebagai|seorang)\s+([^\.,\n]+)/ui', $rawFact, $rMatch)) {
                        $attributes['pekerjaan'] = trim($rMatch[1]);
                    }

                    $this->data['entities'][$entityName] = [
                        'name'       => $entityName,
                        'category'   => $category,
                        'attributes' => $attributes,
                        'created_at' => date('Y-m-d H:i:s'),
                        'updated_at' => date('Y-m-d H:i:s')
                    ];
                }
            }
        } elseif (preg_match('/\b(saya\s+(?:juga\s+)?(?:punya|memiliki|memelihara)\s+(?:seekor\s+)?([a-zA-Z\s]+?)\s+(?:namanya|bernama|yang\s+bernama)\s+([^\.\n]+))/ui', $clean, $m)) {
            $rawFact = trim($m[1]);
            if (mb_strlen($rawFact) > 5) {
                if (!in_array($rawFact, $this->data['facts'], true)) {
                    $this->data['facts'][] = $rawFact;
                    $extracted['fact_saved'] = $rawFact;
                }
                if (preg_match('/\b(?:namanya|bernama|yang\s+bernama)\s+([A-Z][a-zA-Z0-9_\-]*)/u', $rawFact, $eMatch)) {
                    $entityName = $eMatch[1];
                    $this->data['last_entity'] = $entityName;
                    $extracted['entity_name'] = $entityName;

                    $category = 'peliharaan';
                    if (preg_match('/\b(kucing|anjing|kelinci|burung|ikan|hewan)\b/ui', $rawFact, $cMatch)) {
                        $category = mb_strtolower($cMatch[1]);
                    }

                    $attributes = [];
                    if (preg_match('/\b(?:suka|sukanya|makan)\s+([^\.,\n]+)/ui', $rawFact, $aMatch)) {
                        $attributes['makanan'] = trim($aMatch[1]);
                    }

                    $this->data['entities'][$entityName] = [
                        'name'       => $entityName,
                        'category'   => $category,
                        'attributes' => $attributes,
                        'created_at' => date('Y-m-d H:i:s'),
                        'updated_at' => date('Y-m-d H:i:s')
                    ];
                }
            }
        }

        // 7. Pembaruan Fakta Entitas (Temporal Override & Conflict Resolution)
        // Contoh: "Milo sekarang sukanya ayam rebus", "Milo sekarang sudah tidak suka salmon lagi"
        if (!empty($this->data['entities'])) {
            foreach ($this->data['entities'] as $knownName => &$ent) {
                if (preg_match('/\b' . preg_quote($knownName, '/') . '\b/ui', $clean)) {
                    if (preg_match('/\b(?:sekarang|kini|ganti|berubah|pindah)\s+(?:sudah\s+tidak\s+suka\s+[^\.,\n]+\s+lagi[,\s]+(?:dia\s+)?(?:sekarang\s+)?(?:sukanya|makan|pilih)\s+([^\.,\n]+)|sukanya\s+([^\.,\n]+)|makan\s+([^\.,\n]+)|suka\s+([^\.,\n]+)|bekerja\s+di\s+([^\.,\n]+)|tinggal\s+di\s+([^\.,\n]+))\b/ui', $clean, $uMatch)) {
                        $newVal = trim($uMatch[1] ?: ($uMatch[2] ?: ($uMatch[3] ?: ($uMatch[4] ?: ($uMatch[5] ?? ($uMatch[6] ?? ''))))));
                        if (mb_strlen($newVal) > 1) {
                            $attrKey = str_contains($clean, 'bekerja') ? 'pekerjaan' : (str_contains($clean, 'tinggal') ? 'lokasi' : 'makanan');
                            $oldVal = $ent['attributes'][$attrKey] ?? 'sebelumnya';
                            $ent['attributes'][$attrKey] = $newVal;
                            $ent['updated_at'] = date('Y-m-d H:i:s');

                            $updateFact = "{$knownName} sekarang menyukai {$newVal} (diperbarui dari riwayat sebelumnya: {$oldVal})";
                            array_unshift($this->data['facts'], $updateFact);
                            $extracted['fact_updated'] = $updateFact;
                            $extracted['fact_saved'] = $updateFact;
                        }
                    }
                }
            }
        }

        if (!empty($extracted)) {
            $this->saveSession();
        }

        return $extracted;
    }

    /**
     * Pelajari pengetahuan khusus untuk sesi obrolan saat ini
     */
    public function learnSessionKnowledge(string $question, string $answer): bool
    {
        $q = trim($question);
        $a = trim($answer);
        if ($q === '' || $a === '') return false;

        $updated = false;
        foreach ($this->data['session_learned'] as &$item) {
            if (mb_strtolower($item['question']) === mb_strtolower($q)) {
                $item['answer'] = $a;
                $item['updated_at'] = date('Y-m-d H:i:s');
                $updated = true;
                break;
            }
        }

        if (!$updated) {
            $this->data['session_learned'][] = [
                'question'   => $q,
                'answer'     => $a,
                'learned_at' => date('Y-m-d H:i:s')
            ];
        }

        return $this->saveSession();
    }

    /**
     * Cari kecocokan dalam basis pengetahuan sesi saat ini
     */
    public function matchSessionKnowledge(string $userInput): ?array
    {
        $clean = mb_strtolower(trim($userInput), 'UTF-8');
        if (empty($this->data['session_learned'])) return null;

        $bestScore = 0.0;
        $bestAnswer = null;
        $bestQ = null;

        foreach ($this->data['session_learned'] as $item) {
            $qLower = mb_strtolower($item['question'], 'UTF-8');
            if ($clean === $qLower) {
                return [
                    'answer'     => $item['answer'],
                    'question'   => $item['question'],
                    'confidence' => 1.0
                ];
            }

            // Hitung kemiripan sederhana token
            similar_text($clean, $qLower, $perc);
            $score = $perc / 100.0;
            if ($score > $bestScore) {
                $bestScore = $score;
                $bestAnswer = $item['answer'];
                $bestQ = $item['question'];
            }
        }

        if ($bestScore >= 0.55 && $bestAnswer !== null) {
            return [
                'answer'     => $bestAnswer,
                'question'   => $bestQ,
                'confidence' => round($bestScore, 2)
            ];
        }

        return null;
    }

    /**
     * Cek apakah pengguna menanyakan tentang memori, identitas, atau riwayat di sesi ini
     */
    public function checkMemoryQuery(string $text, array $history = []): ?array
    {
        $lower = mb_strtolower(trim($text), 'UTF-8');

        // Pastikan fakta di-scan juga dari history sesi jika profil masih kosong
        if (empty($this->data['profile']['name'])) {
            foreach ($history as $msg) {
                if (($msg['role'] ?? '') === 'user') {
                    $this->extractFacts($msg['content'] ?? '');
                }
            }
        }

        $profile = $this->data['profile'];
        $facts = $this->data['facts'] ?? [];

        // 0. Resolusi Kata Ganti / Anaphora Coreference ("Siapa dia?", "Siapakah dia?", "Tentang dia")
        if (preg_match('/\b(siapa\s+(?:dia|ia)|siapakah\s+(?:dia|ia)|tentang\s+(?:dia|ia)|bagaimana\s+dengan\s+(?:dia|ia))\b/ui', $lower)) {
            $lastEntity = $this->data['last_entity'] ?? null;
            if (!empty($lastEntity)) {
                return [
                    'thought' => "Melakukan pelacakan kata ganti (Anaphora Coreference Resolution). Kata 'dia/ia' merujuk ke subjek yang baru saja kita bahas: '{$lastEntity}'.",
                    'reply'   => "Berdasarkan konteks percakapan kita sebelumnya, kata ganti **\"dia\"** merujuk kepada **{$lastEntity}**.",
                    'type'    => 'anaphora_resolution'
                ];
            }
        }

        // 1. Pertanyaan: "Siapa nama saya?" / "Siapa aku?" / "Kamu tahu siapa saya?"
        if (preg_match('/\b(siapa\s+nama\s+(?:saya|aku)|siapa\s+(?:saya|aku)|kamu\s+tahu\s+nama(?:ku| saya))\b/ui', $lower)) {
            if (!empty($profile['name'])) {
                $extra = [];
                if (!empty($profile['role'])) $extra[] = "seorang **{$profile['role']}**";
                if (!empty($profile['location'])) $extra[] = "berdomisili di **{$profile['location']}**";

                $extraText = !empty($extra) ? " (" . implode(', ', $extra) . ")" : "";
                return [
                    'thought' => "Pengguna menanyakan identitas diri pada sesi obrolan ini. Ditemukan profil nama: '{$profile['name']}'.",
                    'reply'   => "Nama Anda adalah **{$profile['name']}**{$extraText}! Saya selalu mengingat identitas Anda dalam sesi obrolan ini.",
                    'type'    => 'session_identity_recall'
                ];
            } else {
                return [
                    'thought' => "Pengguna menanyakan nama mereka, tetapi belum pernah memperkenalkan diri pada sesi obrolan ini.",
                    'reply'   => "Pada sesi obrolan ini, Anda belum memberi tahu nama Anda. Siapakah nama Anda? Anda bisa memperkenalkan diri seperti: *\"Nama saya [nama Anda]\"* agar saya dapat mengingatnya!",
                    'type'    => 'session_identity_ask'
                ];
            }
        }

        // 2. Pertanyaan: "Apa pekerjaan / profesi saya?"
        if (preg_match('/\b(apa\s+(?:pekerjaan|profesi|kerjaan)\s+(?:saya|aku)|saya\s+kerja\s+apa)\b/ui', $lower)) {
            if (!empty($profile['role'])) {
                return [
                    'thought' => "Membaca profesi/pekerjaan pengguna dari memori sesi: '{$profile['role']}'.",
                    'reply'   => "Berdasarkan percakapan di sesi ini, Anda adalah seorang **{$profile['role']}**.",
                    'type'    => 'session_role_recall'
                ];
            } else {
                return [
                    'thought' => "Profesi pengguna belum tercatat pada sesi ini.",
                    'reply'   => "Anda belum memberi tahu apa pekerjaan atau profesi Anda di sesi ini. Anda bekerja atau berfokus di bidang apa?",
                    'type'    => 'session_role_ask'
                ];
            }
        }

        // 3. Pertanyaan: "Di mana saya tinggal?" / "Domisili saya di mana?"
        if (preg_match('/\b(di\s+mana\s+(?:saya|aku)\s+tinggal|(?:domisili|asal)\s+(?:saya|aku)\s+di\s+mana)\b/ui', $lower)) {
            if (!empty($profile['location'])) {
                return [
                    'thought' => "Membaca lokasi domisili pengguna dari memori sesi: '{$profile['location']}'.",
                    'reply'   => "Di sesi ini, Anda menyebutkan bahwa Anda tinggal atau berdomisili di **{$profile['location']}**.",
                    'type'    => 'session_location_recall'
                ];
            }
        }

        // 4. Pertanyaan: "Apa hobi saya?"
        if (preg_match('/\b(apa\s+hobi\s+(?:saya|aku)|hobi\s+saya\s+apa|saya\s+suka\s+apa)\b/ui', $lower)) {
            if (!empty($profile['hobby'])) {
                return [
                    'thought' => "Membaca hobi pengguna dari memori sesi: '{$profile['hobby']}'.",
                    'reply'   => "Dalam sesi obrolan ini, Anda menyebutkan memiliki kegemaran atau hobi **{$profile['hobby']}**.",
                    'type'    => 'session_hobby_recall'
                ];
            }
        }

        // 5. Pertanyaan: "Apa saja yang sudah kamu ingat/ketahui tentang saya?" / "Sebutkan profil saya"
        if (preg_match('/\b(apa\s+(?:saja\s+)?yang\s+(?:sudah\s+)?kamu\s+(?:ingat|ketahui|catat)\s+tentang\s+(?:saya|aku)|sebutkan\s+profil\s+(?:saya|aku))\b/ui', $lower)) {
            $items = [];
            if (!empty($profile['name'])) $items[] = "- **Nama:** {$profile['name']}";
            if (!empty($profile['role'])) $items[] = "- **Profesi/Peran:** {$profile['role']}";
            if (!empty($profile['location'])) $items[] = "- **Domisili:** {$profile['location']}";
            if (!empty($profile['hobby'])) $items[] = "- **Hobi/Minat:** {$profile['hobby']}";

            if (!empty($facts)) {
                $items[] = "\n**Fakta Khusus yang Tercatat:**";
                foreach ($facts as $f) {
                    $items[] = "- *{$f}*";
                }
            }

            if (!empty($items)) {
                return [
                    'thought' => "Merangkum seluruh profil dan fakta pengguna yang dipelajari pada sesi obrolan saat ini.",
                    'reply'   => "Berikut adalah informasi dan fakta yang telah saya pelajari tentang Anda di sesi obrolan ini:\n\n" . implode("\n", $items),
                    'type'    => 'session_profile_summary'
                ];
            } else {
                return [
                    'thought' => "Sesi obrolan masih baru dan belum memiliki catatan identitas pengguna.",
                    'reply'   => "Di sesi obrolan ini, kita baru memulai dan saya belum memiliki catatan khusus tentang profil Anda. Silakan ceritakan nama, hobi, atau profesi Anda agar saya bisa mengingatnya!",
                    'type'    => 'session_profile_empty'
                ];
            }
        }

        // 6. Pertanyaan: "Tadi kita membahas apa saja?" / "Rangkum obrolan kita" / Sintesis Riwayat
        if (EpisodicSlicer::isEpisodicSynthesisQuery($text) || preg_match('/\b(tadi\s+kita\s+(?:membahas|bicara|ngobrolin)\s+apa|rangkum\s+(?:obrolan|percakapan)\s+kita|topik\s+apa\s+saja)\b/ui', $lower)) {
            $synthesized = $this->synthesizeEpisodicMemory($text, $history);
            if ($synthesized !== null) {
                return $synthesized;
            }

            $summaryPoints = [];
            $seenTopics = [];

            foreach ($history as $h) {
                if (($h['role'] ?? '') === 'user') {
                    $uText = trim($h['content'] ?? '');
                    if (mb_strlen($uText) > 2 && !preg_match('/\b(tadi kita|rangkum|siapa saya)\b/ui', $uText)) {
                        $short = mb_substr($uText, 0, 45) . (mb_strlen($uText) > 45 ? '...' : '');
                        if (!in_array($short, $seenTopics, true)) {
                            $seenTopics[] = $short;
                            $summaryPoints[] = "- \"{$short}\"";
                        }
                    }
                }
            }

            if (!empty($summaryPoints)) {
                return [
                    'thought' => "Menganalisis riwayat obrolan sesi saat ini dan menyusun rangkuman poin-poin yang telah dibahas.",
                    'reply'   => "Dalam sesi percakapan ini, kita telah mendiskusikan beberapa topik berikut:\n\n" . implode("\n", array_slice($summaryPoints, -6)) . "\n\nAda aspek yang ingin kita perdalam lebih lanjut?",
                    'type'    => 'session_history_summary'
                ];
            }
        }

        // 6.5 Kueri Relasional Agregat (Menghitung dan mendaftar entitas terstruktur)
        // Contoh: "ada berapa hewan peliharaan saya?", "sebutkan hewan peliharaan saya"
        if (!empty($this->data['entities']) && preg_match('/\b(?:berapa\s+(?:jumlah\s+)?(?:hewan|peliharaan|kucing|anjing)|ada\s+berapa\s+(?:hewan|peliharaan)|sebutkan\s+(?:semua\s+)?(?:hewan|peliharaan))\b/ui', $lower)) {
            $pets = [];
            foreach ($this->data['entities'] as $eName => $eData) {
                if (in_array(mb_strtolower($eData['category'] ?? ''), ['kucing', 'anjing', 'kelinci', 'burung', 'ikan', 'peliharaan', 'hewan'])) {
                    $pets[] = "**{$eName}** (" . ($eData['category'] ?? 'hewan') . ")";
                }
            }
            if (!empty($pets)) {
                $count = count($pets);
                return [
                    'thought' => "Melakukan kueri relasional agregat pada basis graf entitas memori sesi: ditemukan {$count} entitas hewan peliharaan.",
                    'reply'   => "Berdasarkan catatan di sesi percakapan ini, Anda memiliki **{$count}** hewan peliharaan: " . implode(', ', $pets) . " 🐾.",
                    'type'    => 'session_relational_pets'
                ];
            }
        }

        // 6.6 Penelusuran Graf Entitas EAV (Entity-Attribute-Value) Langsung
        // Contoh: "apa makanan favorit Milo sekarang?", "makanan kesukaan Milo apa?"
        if (!empty($this->data['entities'])) {
            foreach ($this->data['entities'] as $eName => $eData) {
                if (str_contains($lower, mb_strtolower($eName))) {
                    $attributes = $eData['attributes'] ?? [];
                    if (!empty($attributes)) {
                        $isAskingFood = (bool) preg_match('/\b(?:makan|makanan|suka|sukanya|favorit|kesukaan|diet|menu)\b/ui', $lower);
                        if ($isAskingFood && isset($attributes['makanan'])) {
                            $foodVal = $attributes['makanan'];
                            $cat = $eData['category'] ?? 'peliharaan';
                            return [
                                'thought' => "Menemukan korelasi atribut 'makanan' pada graf entitas terstruktur: '{$eName}' ({$cat}) -> '{$foodVal}'.",
                                'reply'   => "Berdasarkan data terkini pada sesi ini, makanan favorit **{$eName}** ({$cat}) adalah **{$foodVal}** 🐾.",
                                'type'    => 'session_entity_attribute_recall'
                            ];
                        }

                        $isAskingJob = (bool) preg_match('/\b(?:kerja|pekerjaan|profesi|jabatan)\b/ui', $lower);
                        if ($isAskingJob && isset($attributes['pekerjaan'])) {
                            $jobVal = $attributes['pekerjaan'];
                            return [
                                'thought' => "Menemukan korelasi atribut 'pekerjaan' pada graf entitas: '{$eName}' -> '{$jobVal}'.",
                                'reply'   => "Berdasarkan data di sesi ini, pekerjaan dari **{$eName}** adalah **{$jobVal}**.",
                                'type'    => 'session_entity_attribute_recall'
                            ];
                        }
                    }
                }
            }
        }

        // 7. Cek apakah ada kecocokan dengan fakta spesifik yang pernah dicatat (misal: "nama kucing", "makanan kesukaan Milo")
        if (!empty($facts)) {
            $synonyms = [
                'makanan'  => ['suka', 'makan', 'favorit', 'kesukaan', 'menu', 'diet', 'salmon', 'ikan', 'daging'],
                'kesukaan' => ['suka', 'favorit', 'gemar', 'senang', 'cinta', 'hobi'],
                'favorit'  => ['suka', 'kesukaan', 'gemar', 'pilihan'],
                'pekerjaan'=> ['profesi', 'kerja', 'bekerja', 'karir', 'jabatan', 'tugas'],
                'kucing'   => ['peliharaan', 'hewan', 'anabul', 'cat'],
                'anjing'   => ['peliharaan', 'hewan', 'dog'],
                'nama'     => ['bernama', 'namanya', 'dipanggil', 'sebutan'],
                'tinggal'  => ['domisili', 'rumah', 'kota', 'tempat', 'asal']
            ];

            $qTokens = TextProcessor::tokenize($lower, true);
            $expandedTokens = $qTokens;
            foreach ($qTokens as $qt) {
                if (isset($synonyms[$qt])) {
                    $expandedTokens = array_merge($expandedTokens, $synonyms[$qt]);
                }
            }
            $expandedTokens = array_unique($expandedTokens);

            $bestFact = null;
            $bestMatchCount = 0;

            foreach ($facts as $savedFact) {
                $factLower = mb_strtolower($savedFact);
                $matchCount = 0;
                foreach ($expandedTokens as $token) {
                    if (str_contains($factLower, $token)) {
                        $matchCount++;
                    }
                }
                if ($matchCount > $bestMatchCount) {
                    $bestMatchCount = $matchCount;
                    $bestFact = $savedFact;
                }
            }

            if ($bestFact !== null && ($bestMatchCount >= 2 || (count($qTokens) === 1 && $bestMatchCount >= 1))) {
                $cleanFact = trim($bestFact);
                return [
                    'thought' => "Menemukan korelasi pada fakta sesi yang tersimpan: '{$cleanFact}' dengan kecocokan {$bestMatchCount} korelasi semantik.",
                    'reply'   => "Berdasarkan fakta yang Anda sampaikan pada sesi ini: **\"{$cleanFact}\"**.",
                    'type'    => 'session_fact_recall'
                ];
            }
        }

        return null;
    }

    /**
     * Dapatkan subjek entitas terakhir yang sedang aktif dibahas dalam sesi
     */
    public function getLastEntity(): ?string
    {
        return $this->data['last_entity'] ?? null;
    }

    /**
     * Pemangkasan Kepingan Memori Episodik Berbasis Prioritas & Salience (Salience-Aware Pruning)
     * Menggantikan pemangkasan buta FIFO agar batasan krusial (CONSTRAINT) dan fakta kunci (FACT)
     * selalu terlindungi/diproteksi, sementara derau opini usang dan basa-basi dieleminasi terlebih dahulu.
     */
    public function pruneEpisodicFragments(int $maxLimit = 100): void
    {
        $fragments = $this->data['episodic_fragments'] ?? [];
        if (count($fragments) <= $maxLimit) {
            return;
        }

        $pinned = [];
        $candidates = [];
        $maxTurn = 1;
        foreach ($fragments as $f) {
            if (($f['turn_index'] ?? 1) > $maxTurn) {
                $maxTurn = $f['turn_index'];
            }
        }

        // 1. Pemilahan kepingan: Pisahkan jangkar krusial (pinned) dari kandidat eliminasi
        foreach ($fragments as $frag) {
            $type = $frag['type'] ?? EpisodicSlicer::TYPE_FACT;
            $salience = (float)($frag['salience_weight'] ?? 0.5);

            // Batasan proyek/aturan dan fakta fundamental dengan bobot tinggi mutlak dilindungi
            if (($type === EpisodicSlicer::TYPE_CONSTRAINT && $salience >= 0.85) ||
                ($type === EpisodicSlicer::TYPE_FACT && $salience >= 0.90)) {
                $pinned[] = $frag;
            } else {
                $turn = (int)($frag['turn_index'] ?? 1);
                $recency = $maxTurn > 1 ? ($turn / $maxTurn) : 1.0;

                // Bobot retensi: Salience (60%) + Recency (40%) dengan pengali tipe proposisi
                $typeMultiplier = match ($type) {
                    EpisodicSlicer::TYPE_CONSTRAINT  => 1.35,
                    EpisodicSlicer::TYPE_FACT        => 1.15,
                    EpisodicSlicer::TYPE_PREFERENCE  => 1.00,
                    EpisodicSlicer::TYPE_ACTION_PLAN => 0.95,
                    EpisodicSlicer::TYPE_OPINION     => 0.60, // Opini usang lekas terdepresiasi
                    default                          => 0.20,
                };

                $retentionScore = (($salience * 0.6) + ($recency * 0.4)) * $typeMultiplier;
                $frag['_retention_score'] = round($retentionScore, 4);
                $candidates[] = $frag;
            }
        }

        // 2. Jika jumlah pinned melebihi 50% kuota total, jaga agar tidak memonopoli seluruh ruang
        $maxPinned = (int)floor($maxLimit * 0.5);
        if (count($pinned) > $maxPinned) {
            usort($pinned, function ($a, $b) {
                return ($b['salience_weight'] <=> $a['salience_weight']) ?: ($b['turn_index'] <=> $a['turn_index']);
            });
            $excessPinned = array_slice($pinned, $maxPinned);
            $pinned = array_slice($pinned, 0, $maxPinned);
            foreach ($excessPinned as $ep) {
                $ep['_retention_score'] = $ep['salience_weight'] ?? 0.85;
                $candidates[] = $ep;
            }
        }

        // 3. Seleksi kandidat terbaik berdasarkan skor retensi tertinggi
        $availableSlots = max(0, $maxLimit - count($pinned));
        usort($candidates, fn($a, $b) => ($b['_retention_score'] ?? 0) <=> ($a['_retention_score'] ?? 0));
        $selectedCandidates = array_slice($candidates, 0, $availableSlots);

        // 4. Bersihkan metadata internal _retention_score
        $final = array_merge($pinned, $selectedCandidates);
        foreach ($final as &$f) {
            unset($f['_retention_score']);
        }
        unset($f);

        // 5. Kembalikan urutan kronologis berdasarkan turn_index dan timestamp
        usort($final, function ($a, $b) {
            $tDiff = ($a['turn_index'] ?? 0) <=> ($b['turn_index'] ?? 0);
            if ($tDiff !== 0) {
                return $tDiff;
            }
            return ($a['timestamp'] ?? 0) <=> ($b['timestamp'] ?? 0);
        });

        $this->data['episodic_fragments'] = array_values($final);
    }

    /**
     * Catat topik baru, lacak entitas subjek percakapan (Coreference / Anaphora Tracking),
     * serta rekam kurva jejak emosional (Affective Trajectory Log).
     */
    public function recordTurn(string $userText, string $botReply, array $emotionalContext = []): void
    {
        $tokens = TextProcessor::tokenize($userText, true);
        foreach ($tokens as $token) {
            if (mb_strlen($token) >= 4 && !in_array($token, $this->data['topics'], true)) {
                $this->data['topics'][] = $token;
                if (count($this->data['topics']) > 30) {
                    array_shift($this->data['topics']);
                }
            }
        }

        // Ekstraksi entitas penting (nama orang, tokoh, atau konsep subjek di posisi awal klausa S-P-O-K)
        if (preg_match_all('/\b([A-Z][a-z]{2,}(?:\s+[A-Z][a-z]{2,})?)\b/', $userText, $matches)) {
            $filtered = array_values(array_filter($matches[1], function($item) {
                return !in_array(mb_strtolower($item), ['halo', 'hai', 'siapa', 'apakah', 'saya', 'kamu', 'anda', 'kami', 'tolong', 'pada', 'saat', 'ketika']);
            }));
            if (!empty($filtered)) {
                // Ambil entitas pertama sebagai subjek utama klausa
                $this->data['last_entity'] = $filtered[0];
            }
        } elseif (preg_match('/\*\*([a-zA-Z0-9\s]{3,30})\*\*/', $botReply, $bMatch)) {
            $this->data['last_entity'] = trim($bMatch[1]);
        }

        // Catat jejak kurva emosional (Affective Trajectory Log)
        if (!empty($emotionalContext)) {
            $this->data['emotional_trajectory'] = $this->data['emotional_trajectory'] ?? [];
            $this->data['emotional_trajectory'][] = [
                'turn'       => count($this->data['emotional_trajectory']) + 1,
                'emotion'    => $emotionalContext['primary_emotion'] ?? 'neutral',
                'intensity'  => $emotionalContext['intensity'] ?? 0.0,
                'valence'    => $emotionalContext['valence'] ?? 0.0,
                'is_sarcasm' => $emotionalContext['is_sarcasm'] ?? false,
                'timestamp'  => time()
            ];
            if (count($this->data['emotional_trajectory']) > 30) {
                array_shift($this->data['emotional_trajectory']);
            }
        }

        // Pemecahan & penyimpanan kepingan mikro informasi (Episodic Slicing)
        $this->data['episodic_fragments'] = $this->data['episodic_fragments'] ?? [];
        $turnNum = count($this->data['emotional_trajectory'] ?? []) ?: 1;
        $activeEntity = $this->getLastEntity();

        // 1. Kepingan informasi dari pengguna (User Input dengan Coreference Binding)
        $userFrags = EpisodicSlicer::sliceText($userText, $turnNum, 'user', $activeEntity);
        foreach ($userFrags as $uf) {
            if ($uf['type'] !== EpisodicSlicer::TYPE_CHITCHAT) {
                $this->data['episodic_fragments'][] = $uf;
            }
        }

        // 2. Kepingan informasi dari respons Sentinel sendiri (Sanitized Assistant Output)
        // Mencegah perekaman header markdown / dekorator UI Sentinel ke memori fakta (Anti Self-Feedback Echo)
        if (!empty($botReply) && mb_strlen($botReply) > 10) {
            $cleanBotReply = EpisodicSlicer::sanitizeAssistantReply($botReply);
            if (!empty($cleanBotReply)) {
                $botFrags = EpisodicSlicer::sliceText($cleanBotReply, $turnNum, 'assistant', $activeEntity);
                foreach ($botFrags as $bf) {
                    // Simpan fakta, batasan, formula, dan rencana aksi yang dilahirkan oleh Sentinel
                    if (in_array($bf['type'], [EpisodicSlicer::TYPE_FACT, EpisodicSlicer::TYPE_CONSTRAINT, EpisodicSlicer::TYPE_ACTION_PLAN], true) && $bf['salience_weight'] >= 0.70) {
                        $this->data['episodic_fragments'][] = $bf;
                    }
                }
            }
        }

        // Pemangkasan adaptif berbasis prioritas & salience (melindungi CONSTRAINT & FACT krusial)
        if (count($this->data['episodic_fragments']) > 100) {
            $this->pruneEpisodicFragments(100);
        }

        $this->saveSession();
    }

    /**
     * Dapatkan semua kepingan mikro informasi yang tersimpan di sesi ini
     */
    public function getEpisodicFragments(): array
    {
        return $this->data['episodic_fragments'] ?? [];
    }

    /**
     * Memotong riwayat obrolan dan menyaring kepingan yang paling relevan
     */
    public function sliceAndFilterHistory(string $query, array $history = []): array
    {
        $fragments = $this->getEpisodicFragments();

        if (!empty($history)) {
            $historyFrags = EpisodicSlicer::sliceHistory($history, $this->getLastEntity());
            $seenTexts = [];
            $merged = [];
            foreach (array_merge($fragments, $historyFrags) as $f) {
                $hash = mb_strtolower(trim($f['text']));
                if (!isset($seenTexts[$hash])) {
                    $seenTexts[$hash] = true;
                    $merged[] = $f;
                }
            }
            $fragments = $merged;
        }

        return EpisodicSlicer::filterAndRank($fragments, $query);
    }

    /**
     * Meramu kepingan riwayat menjadi respons baru orisinal
     */
    public function synthesizeEpisodicMemory(string $query, array $history = []): ?array
    {
        $filtered = $this->sliceAndFilterHistory($query, $history);
        if (empty($filtered['selected'])) {
            return null;
        }

        return EpisodicSlicer::synthesizeNovelResponse($query, $filtered['selected'], $this->getProfile());
    }

    /**
     * Ambil seluruh riwayat jejak emosional pada sesi ini
     */
    public function getEmotionalTrajectory(): array
    {
        return $this->data['emotional_trajectory'] ?? [];
    }

    /**
     * Hitung tren dinamika emosi sesi ini (apakah membaik, memburuk, atau tenang)
     */
    public function getEmotionalTrend(): array
    {
        $trajectory = $this->getEmotionalTrajectory();
        if (empty($trajectory)) {
            return [
                'trend'            => 'neutral_stable',
                'average_valence'  => 0.0,
                'dominant_emotion' => 'neutral',
                'is_calming_down'  => false,
                'total_turns'      => 0
            ];
        }

        $valences = array_column($trajectory, 'valence');
        $emotions = array_column($trajectory, 'emotion');
        $avgValence = round(array_sum($valences) / count($valences), 2);

        $counts = array_count_values($emotions);
        arsort($counts);
        $dominant = array_key_first($counts) ?? 'neutral';

        $isCalmingDown = false;
        $trend = 'neutral_stable';

        if (count($trajectory) >= 2) {
            $firstHalf = array_slice($valences, 0, (int)floor(count($valences) / 2));
            $secondHalf = array_slice($valences, (int)floor(count($valences) / 2));
            $avgFirst = array_sum($firstHalf) / max(1, count($firstHalf));
            $avgSecond = array_sum($secondHalf) / max(1, count($secondHalf));

            if ($avgSecond > $avgFirst + 0.2) {
                $trend = 'improving_positive';
                $isCalmingDown = true;
            } elseif ($avgSecond < $avgFirst - 0.2) {
                $trend = 'deteriorating_negative';
            } elseif ($avgValence > 0.3) {
                $trend = 'consistently_positive';
            } elseif ($avgValence < -0.3) {
                $trend = 'consistently_negative';
            }
        }

        return [
            'trend'            => $trend,
            'average_valence'  => $avgValence,
            'dominant_emotion' => $dominant,
            'is_calming_down'  => $isCalmingDown,
            'total_turns'      => count($trajectory)
        ];
    }

    public function getProfile(): array
    {
        return $this->data['profile'] ?? [];
    }

    public function getSessionData(): array
    {
        return $this->data;
    }

    public function resetSession(): bool
    {
        $this->data['profile'] = ['name' => null, 'role' => null, 'location' => null, 'hobby' => null, 'skills' => []];
        $this->data['facts'] = [];
        $this->data['session_learned'] = [];
        $this->data['topics'] = [];
        $this->data['episodic_fragments'] = [];
        return $this->saveSession();
    }
}
