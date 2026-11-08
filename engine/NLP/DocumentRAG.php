<?php

namespace Sentinel\NLP;

class DocumentRAG
{
    protected string $storageDir;
    protected string $sessionId;
    protected ?\PDO $pdo = null;

    public function __construct(string $sessionId = 'default_session', ?string $baseStorageDir = null, ?\PDO $pdo = null)
    {
        $this->sessionId = $sessionId ?: 'default_session';
        $this->pdo = $pdo;
        $baseDir = $baseStorageDir ?: (dirname(__DIR__, 2) . '/data/documents');
        $this->storageDir = $baseDir . '/' . preg_replace('/[^a-zA-Z0-9_\-]/', '_', $this->sessionId);

        // Jangan buat direktori disk jika PDO tersedia
        if ($this->pdo === null && !is_dir($this->storageDir)) {
            @mkdir($this->storageDir, 0777, true);
        }
    }

    public function setPDO(?\PDO $pdo): void
    {
        $this->pdo = $pdo;
    }

    /**
     * Dapatkan path direktori penyimpanan dokumen sesi ini
     */
    public function getStorageDir(): string
    {
        return $this->storageDir;
    }

    /**
     * Memotong teks menjadi potongan-potongan (chunks) dengan metode sliding window dan overlap
     */
    public static function chunkText(string $text, int $chunkSize = 100, int $overlap = 25): array
    {
        $words = preg_split('/\s+/u', trim($text));
        $totalWords = count($words);

        if ($totalWords <= $chunkSize) {
            return [
                [
                    'index'      => 0,
                    'text'       => trim($text),
                    'word_count' => $totalWords
                ]
            ];
        }

        $chunks = [];
        $index = 0;
        $step = max(1, $chunkSize - $overlap);

        for ($i = 0; $i < $totalWords; $i += $step) {
            $slice = array_slice($words, $i, $chunkSize);
            $chunkText = implode(' ', $slice);
            $chunks[] = [
                'index'      => $index++,
                'text'       => $chunkText,
                'word_count' => count($slice)
            ];

            if ($i + $chunkSize >= $totalWords) {
                break;
            }
        }

        return $chunks;
    }

    /**
     * Menyimpan dan mengindeks dokumen baru untuk sesi obrolan ini (Ke tabel MySQL sentinel_nlp_documents)
     */
    public function ingestDocument(string $title, string $content, string $source = 'user_upload'): array
    {
        if (strlen($content) > 2 * 1024 * 1024) {
            throw new \InvalidArgumentException("Ukuran konten dokumen melebihi batas maksimal 2 MB.");
        }

        $cleanTitle = trim($title) ?: 'Dokumen Catatan';
        $docId = 'doc_' . time() . '_' . substr(md5($cleanTitle . microtime()), 0, 6);

        $chunks = self::chunkText($content, 90, 20);

        $docData = [
            'id'           => $docId,
            'title'        => $cleanTitle,
            'source'       => $source,
            'created_at'   => date('Y-m-d H:i:s'),
            'total_words'  => count(preg_split('/\s+/u', trim($content))),
            'chunk_count'  => count($chunks),
            'content_raw'  => $content,
            'chunks'       => $chunks
        ];

        // 1. Simpan eksklusif ke tabel MySQL
        if ($this->pdo !== null) {
            try {
                $stmt = $this->pdo->prepare("
                    INSERT INTO sentinel_nlp_documents (id, session_id, title, source, doc_data) 
                    VALUES (?, ?, ?, ?, ?)
                ");
                $stmt->execute([$docId, $this->sessionId, $cleanTitle, $source, json_encode($docData, JSON_UNESCAPED_UNICODE)]);
            } catch (\Exception $e) {}
        } else {
            $filePath = $this->storageDir . '/' . $docId . '.json';
            @file_put_contents($filePath, json_encode($docData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX);
        }

        return [
            'id'          => $docId,
            'title'       => $cleanTitle,
            'chunk_count' => count($chunks),
            'total_words' => $docData['total_words']
        ];
    }

    /**
     * Dapatkan daftar semua dokumen yang diunggah pada sesi ini
     */
    public function listDocuments(): array
    {
        $docs = [];

        if ($this->pdo !== null) {
            try {
                $stmt = $this->pdo->prepare("SELECT id, title, source, doc_data, created_at FROM sentinel_nlp_documents WHERE session_id = ? ORDER BY id DESC");
                $stmt->execute([$this->sessionId]);
                $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
                foreach ($rows as $r) {
                    $data = json_decode($r['doc_data'] ?? '', true);
                    $docs[] = [
                        'id'          => $r['id'],
                        'title'       => $r['title'],
                        'created_at'  => $r['created_at'],
                        'total_words' => $data['total_words'] ?? 0,
                        'chunk_count' => $data['chunk_count'] ?? 0
                    ];
                }
                return $docs;
            } catch (\Exception $e) {}
        }

        $files = glob($this->storageDir . '/*.json') ?: [];
        foreach ($files as $file) {
            $data = json_decode((string)@file_get_contents($file), true);
            if (is_array($data) && isset($data['id'])) {
                $docs[] = [
                    'id'          => $data['id'],
                    'title'       => $data['title'],
                    'created_at'  => $data['created_at'] ?? 'Unknown',
                    'total_words' => $data['total_words'] ?? 0,
                    'chunk_count' => $data['chunk_count'] ?? 0
                ];
            }
        }

        return $docs;
    }

    /**
     * Cek apakah ada dokumen yang aktif tersimpan di sesi ini
     */
    public function hasDocuments(): bool
    {
        if ($this->pdo !== null) {
            try {
                $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM sentinel_nlp_documents WHERE session_id = ?");
                $stmt->execute([$this->sessionId]);
                return ((int)$stmt->fetchColumn()) > 0;
            } catch (\Exception $e) {}
        }

        $files = glob($this->storageDir . '/*.json') ?: [];
        return count($files) > 0;
    }

    /**
     * Hapus dokumen berdasarkan ID
     */
    public function deleteDocument(string $docId): bool
    {
        if ($this->pdo !== null) {
            try {
                $stmt = $this->pdo->prepare("DELETE FROM sentinel_nlp_documents WHERE id = ? AND session_id = ?");
                return $stmt->execute([$docId, $this->sessionId]);
            } catch (\Exception $e) {}
        }

        $cleanId = preg_replace('/[^a-zA-Z0-9_\-]/', '', $docId);
        $file = $this->storageDir . '/' . $cleanId . '.json';
        if (file_exists($file)) {
            return @unlink($file);
        }
        return false;
    }

    /**
     * Melakukan kueri cerdas RAG: Pencarian potongan dokumen paling relevan menggunakan TF-IDF + Ekspansi Semantik
     */
    public function query(string $userQuery, int $topK = 3): ?array
    {
        $allChunks = [];

        if ($this->pdo !== null) {
            try {
                $stmt = $this->pdo->prepare("SELECT doc_data FROM sentinel_nlp_documents WHERE session_id = ?");
                $stmt->execute([$this->sessionId]);
                $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
                foreach ($rows as $r) {
                    $doc = json_decode($r['doc_data'] ?? '', true);
                    if (!is_array($doc) || empty($doc['chunks'])) continue;
                    foreach ($doc['chunks'] as $chunk) {
                        $chunkTokens = TextProcessor::tokenize($chunk['text']);
                        $allChunks[] = [
                            'doc_id'      => $doc['id'],
                            'doc_title'   => $doc['title'],
                            'chunk_index' => $chunk['index'],
                            'text'        => $chunk['text'],
                            'tokens'      => $chunkTokens
                        ];
                    }
                }
            } catch (\Exception $e) {}
        } else {
            $files = glob($this->storageDir . '/*.json') ?: [];
            if (empty($files)) {
                return null;
            }
            foreach ($files as $file) {
                $doc = json_decode((string)@file_get_contents($file), true);
                if (!is_array($doc) || empty($doc['chunks'])) continue;
                foreach ($doc['chunks'] as $chunk) {
                    $chunkTokens = TextProcessor::tokenize($chunk['text']);
                    $allChunks[] = [
                        'doc_id'      => $doc['id'],
                        'doc_title'   => $doc['title'],
                        'chunk_index' => $chunk['index'],
                        'text'        => $chunk['text'],
                        'tokens'      => $chunkTokens
                    ];
                }
            }
        }

        if (empty($allChunks)) {
            return null;
        }

        // Ekspansi kueri dengan sinonim semantik
        $expandedQuery = SemanticEmbeddings::expandQuery($userQuery);
        $queryTokens = TextProcessor::tokenize($expandedQuery);

        if (empty($queryTokens)) {
            return null;
        }

        // Bangun Indeks Frekuensi Dokumen (Document Frequency / DF)
        $totalDocs = count($allChunks);
        $docFreq = [];
        foreach ($allChunks as $item) {
            $uniqueTokens = array_unique($item['tokens']);
            foreach ($uniqueTokens as $t) {
                $docFreq[$t] = ($docFreq[$t] ?? 0) + 1;
            }
        }

        // Hitung TF-IDF & Cosine Similarity untuk tiap chunk terhadap kueri
        $scoredChunks = [];
        $queryTf = array_count_values($queryTokens);

        foreach ($allChunks as $chunk) {
            $score = 0.0;
            $chunkTf = array_count_values($chunk['tokens']);

            $dotProduct = 0.0;
            $normQ = 0.0;
            $normC = 0.0;

            foreach ($queryTf as $term => $qFreq) {
                $df = $docFreq[$term] ?? 1;
                $idf = log(($totalDocs + 1) / ($df + 1)) + 1.0;
                $wQ = $qFreq * $idf;
                $normQ += $wQ * $wQ;

                if (isset($chunkTf[$term])) {
                    $wC = $chunkTf[$term] * $idf;
                    $dotProduct += $wQ * $wC;
                }
            }

            foreach ($chunkTf as $term => $cFreq) {
                $df = $docFreq[$term] ?? 1;
                $idf = log(($totalDocs + 1) / ($df + 1)) + 1.0;
                $wC = $cFreq * $idf;
                $normC += $wC * $wC;
            }

            if ($normQ > 0 && $normC > 0) {
                $score = $dotProduct / (sqrt($normQ) * sqrt($normC));
            }

            // Tambahan Semantic Score jika ada kecocokan konseptual
            $semanticBonus = SemanticEmbeddings::calculateSemanticScore($userQuery, $chunk['text']);
            $finalScore = (0.7 * $score) + (0.3 * $semanticBonus);

            if ($finalScore >= 0.12) {
                $chunk['score'] = round($finalScore, 4);
                $scoredChunks[] = $chunk;
            }
        }

        if (empty($scoredChunks)) {
            return null;
        }

        // Urutkan berdasarkan skor tertinggi
        usort($scoredChunks, fn($a, $b) => $b['score'] <=> $a['score']);
        $topChunks = array_slice($scoredChunks, 0, $topK);

        // Sintesis Jawaban Terstruktur RAG
        return self::synthesizeAnswer($userQuery, $topChunks);
    }

    /**
     * Mensintesis respon RAG yang komprehensif lengkap dengan kutipan sitasi
     */
    protected static function synthesizeAnswer(string $userQuery, array $topChunks): array
    {
        $bestChunk = $topChunks[0];
        $docTitle = $bestChunk['doc_title'];
        $confidence = min(0.98, $bestChunk['score']);

        $citations = [];
        foreach ($topChunks as $idx => $chunk) {
            $excerpt = mb_substr($chunk['text'], 0, 220);
            if (mb_strlen($chunk['text']) > 220) {
                $excerpt .= '...';
            }
            $citations[] = "> **Kutipan #" . ($idx + 1) . " [{$chunk['doc_title']} - Bagian " . ($chunk['chunk_index'] + 1) . "]** *(Korelasi: " . round($chunk['score'] * 100, 1) . "%):*\n> \"{$excerpt}\"";
        }

        $reply = "📄 **Informasi dari Dokumen Terlampir: \"{$docTitle}\"**\n\n" .
                 "Berdasarkan penelusuran pada dokumen yang Anda unggah/sediakan, berikut adalah temuan informasi yang relevan dengan pertanyaan Anda:\n\n" .
                 "{$bestChunk['text']}\n\n" .
                 "___\n" .
                 "🔍 **Sumber Kutipan & Verifikasi Konteks:**\n" .
                 implode("\n\n", $citations);

        $thought = "Menemukan informasi relevan di dalam dokumen '{$docTitle}' pada potongan #{$bestChunk['chunk_index']} dengan relevansi " . round($confidence * 100, 1) . "%. Melakukan sintesis jawaban dan menyertakan sitasi kutipan sumber.";

        return [
            'reply'      => $reply,
            'thought'    => $thought,
            'confidence' => $confidence,
            'doc_title'  => $docTitle,
            'top_chunks' => $topChunks
        ];
    }
}

