<?php

namespace Sentinel\NLP;

class PersonaEngine
{
    // Empat Persona Adaptif Sentinel AI
    public const PERSONA_EMPATHIC_COMPANION     = 'empathic_companion';      // Pendengar hangat & pendamping penuh empati (Duka, Cemas, Sedih, Krisis)
    public const PERSONA_PROFESSIONAL_ANALYTICAL = 'professional_analytical';  // Asisten analitis lugas, presisi, data-driven (Sains, Bisnis, Kode, Netral)
    public const PERSONA_DE_ESCALATOR_RESOLVER   = 'de_escalator_resolver';    // Penenang situasi & penyelesai masalah aktif (Kemarahan, Frustrasi, Kritik)
    public const PERSONA_COLLABORATIVE_MENTOR    = 'collaborative_mentor';     // Mentor dialogis edukatif, suportif, & apresiatif (Eksplorasi, Sukacita, Belajar)

    /**
     * Tentukan Persona Komunikasi yang Paling Tepat Berdasarkan Analisis Afektif & Konteks Pertanyaan
     */
    public static function determinePersona(array $sentimentAnalysis, string $userInput, array $emotionalTrend = []): string
    {
        $emotion = $sentimentAnalysis['primary_emotion'] ?? EmotionalIntelligence::EMOTION_NEUTRAL;
        $intensity = $sentimentAnalysis['intensity'] ?? 0.0;
        $isSarcasm = $sentimentAnalysis['is_sarcasm'] ?? false;
        $isCrisis = $sentimentAnalysis['is_crisis'] ?? false;
        $clean = mb_strtolower(trim($userInput), 'UTF-8');

        // 1. Prioritas Krisis atau Kesedihan/Kecemasan Tinggi -> Empathic Companion
        if ($isCrisis || (in_array($emotion, [EmotionalIntelligence::EMOTION_SADNESS, EmotionalIntelligence::EMOTION_FEAR], true) && $intensity >= 0.4)) {
            return self::PERSONA_EMPATHIC_COMPANION;
        }

        // 2. Kemarahan, Frustrasi, Sarkasme, atau Kritik Layanan -> De-escalator Resolver
        if ($isSarcasm || $emotion === EmotionalIntelligence::EMOTION_ANGER || preg_match('/\b(?:kecewa|kesal|rusak|buruk|lambat|salah|parah|komplain|protes|error)\b/ui', $clean)) {
            return self::PERSONA_DE_ESCALATOR_RESOLVER;
        }

        // 3. Heuristik Masukan Singkat & Mendesak (Terse / Urgency Mode)
        // Pengguna membutuhkan fakta teknis instan tanpa basa-basi afektif
        if (self::isTerseQuery($userInput)) {
            return self::PERSONA_PROFESSIONAL_ANALYTICAL;
        }

        // 4. Sukacita, Apresiasi, Pembelajaran Kreatif -> Collaborative Mentor
        if (in_array($emotion, [EmotionalIntelligence::EMOTION_JOY, EmotionalIntelligence::EMOTION_GRATITUDE_LOVE], true) ||
            preg_match('/\b(?:ajari|jelaskan\s+konsep|bagaimana\s+cara|mari\s+belajar|buatkan\s+ide|menarik|puisi|cerita)\b/ui', $clean)) {
            return self::PERSONA_COLLABORATIVE_MENTOR;
        }

        // 5. Default untuk Komputasi, Sains, Kode, Fakta, & Interaksi Netral -> Professional Analytical
        return self::PERSONA_PROFESSIONAL_ANALYTICAL;
    }

    /**
     * Modulasi Nada Respons Sesuai Persona yang Aktif Tanpa Merusak Format Teknis / Markdown
     */
    public static function modulateTone(string $reply, string $persona, array $context = []): string
    {
        // Jangan modifikasi jika respons sudah berupa output komputasi terstruktur LaTeX atau laporan sintesis
        if (str_contains($reply, '🔬 **Sintesis Analitis') || str_contains($reply, '### ⚡ Hasil Instruksi') || str_contains($reply, '```')) {
            return $reply;
        }

        switch ($persona) {
            case self::PERSONA_EMPATHIC_COMPANION:
                // Berikan kehangatan dan rasa aman tanpa berlebihan
                if (!str_contains($reply, '🥺') && !str_contains($reply, '❤️') && !str_contains($reply, 'LISA')) {
                    if (!str_starts_with($reply, 'Saya mengerti') && !str_starts_with($reply, 'Saya turut')) {
                        $reply = "Tentu, mari kita telaah perlahan. " . $reply;
                    }
                }
                break;

            case self::PERSONA_DE_ESCALATOR_RESOLVER:
                // Penekanan pada transparansi dan fokus ke solusi
                if (!str_contains($reply, 'memohon maaf') && !str_contains($reply, 'solusi')) {
                    $reply = "Saya mendengar apa yang Anda sampaikan. " . $reply;
                }
                break;

            case self::PERSONA_COLLABORATIVE_MENTOR:
                // Dorongan rasa ingin tahu intelektual
                if (!str_contains($reply, '💡') && !str_contains($reply, '✨')) {
                    $reply .= "\n\n💡 *Ada sudut pandang atau skenario lanjutan yang ingin kita eksplorasi bersama?*";
                }
                break;

            case self::PERSONA_PROFESSIONAL_ANALYTICAL:
            default:
                // Tetap jernih, terstruktur, dan objektif
                break;
        }

        return $reply;
    }

    /**
     * Dapatkan Label Deskriptif Persona untuk Ditampilkan pada Metadata / Thought Process
     */
    public static function getPersonaLabel(string $persona): string
    {
        return match ($persona) {
            self::PERSONA_EMPATHIC_COMPANION     => 'Empathetic Companion (Pendengar Hangat & Suportif)',
            self::PERSONA_DE_ESCALATOR_RESOLVER   => 'De-escalation Resolver (Penenang Situasi & Solutif)',
            self::PERSONA_COLLABORATIVE_MENTOR    => 'Collaborative Mentor (Edukatif & Mendorong Diskusi)',
            default                               => 'Professional Analytical (Presisi & Data-Driven)',
        };
    }

    /**
     * Memeriksa apakah input pengguna tergolong ringkas, to-the-point, dan berkecepatan tinggi (Terse-Query Heuristic).
     * Jika kalimat <= 4 kata, tanpa sapaan dan tanpa muatan krisis emosional, sistem secara otomatis
     * memotong seluruh basa-basi sapaan pembuka dan pertanyaan penutup (Zero-Overhead Immediate Technical Response).
     */
    public static function isTerseQuery(string $userInput): bool
    {
        $trimmed = trim($userInput);
        if (empty($trimmed)) {
            return false;
        }

        $lower = mb_strtolower($trimmed, 'UTF-8');

        // Jika terdapat salam atau kata sapaan eksplisit, bukan terse-query murni
        if (preg_match('/\b(?:halo|hai|salam|selamat\s+(?:pagi|siang|sore|malam)|assalamu|terima\s+kasih|makasih|hi|hey)\b/ui', $lower)) {
            return false;
        }

        // Jika terdapat ekspresi emosi mendalam / krisis afektif / curhat / masalah kritis
        if (preg_match('/\b(?:sedih|marah|kecewa|takut|cemas|curhat|galau|kesal|depresi|frustrasi|stress|stres|mati|meninggal|rusak|error|hancur|gagal|benci)\b/ui', $lower)) {
            return false;
        }

        // Hitung jumlah kata
        $words = preg_split('/\s+/u', $trimmed, -1, PREG_SPLIT_NO_EMPTY);
        return count($words) <= 4;
    }

    /**
     * Mendeteksi instruksi atau kueri teknis yang belum lengkap / ambigu (Under-Specified Query).
     * Alih-alih melakukan 'Dead-Air' (keheningan buta) atau berasumsi tanpa dasar, Sentinel akan
     * mengajukan pertanyaan klarifikasi teknis satu kalimat yang tepat sasaran (Targeted Technical Disambiguation).
     */
    public static function isUnderSpecifiedQuery(string $userInput): ?array
    {
        $clean = mb_strtolower(trim($userInput), 'UTF-8');

        // 1. Migrasi Database tanpa target sistem / DBMS spesifik
        if (preg_match('/\b(?:migrasikan|migrasi)\s+(?:database|basis\s+data|tabel)(?:nya|\s+ini|\s+tersebut)?\b/ui', $clean) &&
            !preg_match('/\b(?:ke|menuju|pakai|menggunakan|dari|mysql|postgres(?:ql)?|sqlite|mongodb|oracle|mariadb)\b/ui', $clean)) {
            return [
                'domain'               => 'database_migration',
                'missing_parameter'    => 'target_dbms',
                'clarification_prompt' => 'Untuk mengeksekusi migrasi database secara presisi, target sistem basis data apa yang Anda tentukan (misalnya: MySQL, PostgreSQL, atau SQLite)?'
            ];
        }

        // 2. Deployment aplikasi tanpa target platform / environment
        if (preg_match('/\b(?:deploy|terapkan|pasang|luncurkan)\s+(?:aplikasi|sistem|proyek|service|backend|frontend)(?:nya|\s+ini|\s+tersebut)?\b/ui', $clean) &&
            !preg_match('/\b(?:ke|di|pada|docker|kubernetes|k8s|aws|gcp|railway|vps|server|staging|production|lokal)\b/ui', $clean)) {
            return [
                'domain'               => 'application_deployment',
                'missing_parameter'    => 'target_platform',
                'clarification_prompt' => 'Target infrastruktur deployment mana yang ingin Anda tuju (misalnya Docker, Kubernetes, VPS, atau Cloud seperti Railway/AWS)?'
            ];
        }

        // 3. Konversi format berkas tanpa target format / ekstensi
        if (preg_match('/\b(?:konversi|ubah\s+format|convert)\s+(?:file|berkas|dokumen)(?:nya|\s+ini|\s+tersebut)?\b/ui', $clean) &&
            !preg_match('/\b(?:ke|menjadi|format|pdf|json|csv|xml|excel|docx|markdown)\b/ui', $clean)) {
            return [
                'domain'               => 'file_conversion',
                'missing_parameter'    => 'target_format',
                'clarification_prompt' => 'Format berkas keluaran apa yang Anda inginkan (misalnya JSON, CSV, PDF, atau XML)?'
            ];
        }

        // 4. Operasi backup data tanpa target penyimpanan
        if (preg_match('/\b(?:backup|cadangkan)\s+(?:database|basis\s+data|sistem|server)(?:nya|\s+ini|\s+tersebut)?\b/ui', $clean) &&
            !preg_match('/\b(?:ke|menuju|tujuan|lokasi|s3|cloud|folder|drive|path)\b/ui', $clean)) {
            return [
                'domain'               => 'backup_operation',
                'missing_parameter'    => 'storage_target',
                'clarification_prompt' => 'Tujuan penyimpanan cadangan (backup) mana yang ingin Anda gunakan (misalnya local storage, Cloud S3, atau Google Drive)?'
            ];
        }

        return null;
    }

    /**
     * Menilai apakah sistem perlu menambahkan pertanyaan penutup / saran interaktif lanjutan,
     * ataukah sistem harus mengaktifkan 'Algorithmic Silence' (menahan diri dalam keheningan yang menghormati fokus pengguna).
     */
    public static function shouldIncludeFollowUpPrompt(string $persona, string $userInput, array $context = []): bool
    {
        // 0. Heuristik Masukan Singkat (Terse Query) -> Diam mutlak tanpa pertanyaan lanjutan
        if (self::isTerseQuery($userInput)) {
            return false;
        }

        $clean = mb_strtolower(trim($userInput), 'UTF-8');

        // 1. Kondisi Mendesak / Singkat / Frustrasi / Tugas Tertutup -> KEHENINGAN ALGORITMIK (MUTLAK FALSE)
        if (preg_match('/\b(?:cepat|buru-buru|singkat\s+saja|langsung|tanpa\s+basa-basi|jangan\s+banyak\s+tanya|cukup|selesai|stop|tutup|hanya|to\s+the\s+point)\b/ui', $clean)) {
            return false;
        }

        // 2. Persona De-escalator & Empathic Companion -> Jangan mencecar dengan pertanyaan penutup
        if ($persona === self::PERSONA_DE_ESCALATOR_RESOLVER || $persona === self::PERSONA_EMPATHIC_COMPANION) {
            return false;
        }

        // 3. Tugas Analitis Tertutup (Matematika, Koding Spesifik, Lookup Data) -> FALSE
        if ($persona === self::PERSONA_PROFESSIONAL_ANALYTICAL) {
            // Hanya aktif jika pengguna secara eksplisit meminta panduan / alternatif / ide
            return (bool) preg_match('/\b(?:rekomendasi|alternatif|ide|saran|bagaimana\s+menurutmu|menurut\s+kamu|apa\s+opsi)\b/ui', $clean);
        }

        // 4. Persona Collaborative Mentor -> True jika dalam konteks eksploratif edukatif
        if ($persona === self::PERSONA_COLLABORATIVE_MENTOR) {
            return true;
        }

        return false;
    }

    /**
     * Menilai apakah sapaan nama pengguna perlu diulang pada giliran ini,
     * ataukah cukup dihilangkan agar percakapan mengalir alami tanpa terasa robotik berulang.
     */
    public static function shouldIncludeGreeting(int $turnCount, string $userInput): bool
    {
        // Heuristik Masukan Singkat -> Jangan buang waktu dengan sapaan basa-basi
        if (self::isTerseQuery($userInput)) {
            return false;
        }

        if ($turnCount <= 1) {
            return true;
        }

        return (bool) preg_match('/\b(?:halo|hai|salam|selamat\s+(?:pagi|siang|sore|malam))\b/ui', $userInput);
    }
}

