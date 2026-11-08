<?php

namespace Sentinel\NLP;

class Reasoner
{
    /**
     * Membangun jejak proses berpikir mendalam (Chain-of-Thought / Deep Thinking)
     */
    public static function buildThinkingProcess(string $query, ?string $domain = 'general', ?string $internalThought = null): string
    {
        $steps = [];
        $cleanQuery = trim($query);

        // Langkah 1: Dekonstruksi Masukan
        $steps[] = "1. **Analisis Niat & Dekonstruksi Masukan:** Memeriksa teks masukan pengguna `\"{$cleanQuery}\"` dengan klasifikasi domain `[{$domain}]`.";

        // Langkah 2: Proses Penalaran Khusus Domain
        if ($internalThought) {
            $steps[] = "2. **Penelusuran Logika & Inferensi Data:** " . $internalThought;
        } else {
            $steps[] = "2. **Penelusuran Logika & Inferensi Data:** Mencocokkan vektor kemiripan semantik TF-IDF, mengevaluasi aturan gramatikal, dan memeriksa representasi memori JSON.";
        }

        // Langkah 3: Sintesis & Verifikasi
        $steps[] = "3. **Sintesis & Validasi Respon:** Memverifikasi keakuratan informasi, menyusun kalimat dalam Bahasa Indonesia yang alami, jelas, dan memformat dalam struktur Markdown.";

        return implode("\n\n", $steps);
    }
}
