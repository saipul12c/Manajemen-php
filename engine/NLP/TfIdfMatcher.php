<?php

namespace Sentinel\NLP;

class TfIdfMatcher
{
    protected array $documents = [];
    protected array $metadata = [];
    protected array $vocabulary = [];
    protected array $idf = [];
    protected array $docVectors = [];

    /**
     * Daftarkan dokumen (pola kalimat) beserta referensi metadata-nya
     */
    public function addDocument(string $docId, string $text, array $meta = []): void
    {
        $tokens = TextProcessor::tokenize($text);
        if (empty($tokens)) {
            return;
        }

        // Tambahkan unigram, bigram, dan k-skip-grams agar pencocokan frasa jarak jauh akurat
        $bigrams = TextProcessor::ngrams($tokens, 2);
        $skipgrams = TextProcessor::skipgrams($tokens, 2, 8);
        $features = array_merge($tokens, $bigrams, $skipgrams);

        $this->documents[$docId] = $features;
        $this->metadata[$docId] = array_merge($meta, ['raw_text' => $text]);

        foreach ($features as $term) {
            $this->vocabulary[$term] = true;
        }
    }

    /**
     * Hitung bobot IDF (Inverse Document Frequency) untuk semua term
     */
    public function train(): void
    {
        $totalDocs = count($this->documents);
        if ($totalDocs === 0) {
            return;
        }

        // Hitung frekuensi dokumen (DF) dalam 1 pass linear yang jauh lebih cepat
        $docFreq = [];
        foreach ($this->documents as $features) {
            $uniqueTerms = array_unique($features);
            foreach ($uniqueTerms as $term) {
                $docFreq[$term] = ($docFreq[$term] ?? 0) + 1;
            }
        }

        $this->idf = [];
        foreach (array_keys($this->vocabulary) as $term) {
            $docCount = $docFreq[$term] ?? 0;
            // Smooth IDF formula
            $this->idf[$term] = log((1 + $totalDocs) / (1 + $docCount)) + 1.0;
        }

        // Pra-kalkulasi vektor untuk semua dokumen
        $this->docVectors = [];
        foreach ($this->documents as $docId => $features) {
            $this->docVectors[$docId] = $this->createVector($features);
        }
    }

    /**
     * Ubah daftar token menjadi vektor berbobot TF-IDF
     */
    protected function createVector(array $features): array
    {
        $vector = [];
        $totalFeatures = count($features);
        if ($totalFeatures === 0) {
            return [];
        }

        $termCounts = array_count_values($features);

        foreach ($termCounts as $term => $count) {
            if (isset($this->idf[$term])) {
                $tf = $count / $totalFeatures;
                $vector[$term] = $tf * $this->idf[$term];
            }
        }

        return $vector;
    }

    /**
     * Hitung Cosine Similarity antara dua vektor
     */
    protected function cosineSimilarity(array $vecA, array $vecB): float
    {
        $dotProduct = 0.0;
        $normA = 0.0;
        $normB = 0.0;

        foreach ($vecA as $term => $valA) {
            $normA += $valA * $valA;
            if (isset($vecB[$term])) {
                $dotProduct += $valA * $vecB[$term];
            }
        }

        foreach ($vecB as $valB) {
            $normB += $valB * $valB;
        }

        if ($normA <= 0.0 || $normB <= 0.0) {
            return 0.0;
        }

        return $dotProduct / (sqrt($normA) * sqrt($normB));
    }

    /**
     * Cari kecocokan terbaik untuk teks masukan pengguna
     * Memadukan TF-IDF Cosine Similarity dengan Semantic Synonym Booster
     */
    public function match(string $queryText): ?array
    {
        $tokens = TextProcessor::tokenize($queryText);
        if (empty($tokens) || empty($this->docVectors)) {
            return null;
        }

        // Ekspansi token dengan sinonim terdekat
        $expandedTokens = $tokens;
        foreach ($tokens as $t) {
            $syns = SemanticEmbeddings::getSynonyms($t);
            if (!empty($syns)) {
                $expandedTokens = array_merge($expandedTokens, array_slice($syns, 0, 2));
            }
        }
        $expandedTokens = array_unique($expandedTokens);

        $bigrams = TextProcessor::ngrams($expandedTokens, 2);
        $skipgrams = TextProcessor::skipgrams($expandedTokens, 2, 8);
        $queryFeatures = array_merge($expandedTokens, $bigrams, $skipgrams);
        $queryVector = $this->createVector($queryFeatures);

        if (empty($queryVector)) {
            return null;
        }

        $bestScore = 0.0;
        $bestDocId = null;

        $cleanQuery = TextProcessor::clean($queryText);

        foreach ($this->docVectors as $docId => $docVector) {
            $score = $this->cosineSimilarity($queryVector, $docVector);

            // Tambahkan bonus jika ada kemiripan string langsung (hanya jika cukup panjang untuk mencegah false positive kata pendek)
            $rawTextClean = TextProcessor::clean($this->metadata[$docId]['raw_text'] ?? '');
            if ($rawTextClean === $cleanQuery) {
                $score = 1.0;
            } else {
                $qWords = explode(' ', $cleanQuery);
                $rWords = explode(' ', $rawTextClean);
                $minWords = min(count($qWords), count($rWords));

                if ($minWords >= 3 && mb_strlen($rawTextClean) >= 12 && (str_contains($cleanQuery, $rawTextClean) || str_contains($rawTextClean, $cleanQuery))) {
                    $score = max($score, 0.85);
                } else {
                    // Semantic Similarity Booster
                    $semanticScore = SemanticEmbeddings::calculateSemanticScore($cleanQuery, $rawTextClean);
                    $score = max($score, (0.6 * $score) + (0.4 * $semanticScore));
                }
            }

            if ($score > $bestScore) {
                $bestScore = $score;
                $bestDocId = $docId;
            }
        }

        if ($bestDocId !== null) {
            return [
                'doc_id'   => $bestDocId,
                'score'    => round($bestScore, 4),
                'metadata' => $this->metadata[$bestDocId]
            ];
        }

        return null;
    }
}

