<?php

namespace Sentinel\NLP;

class CognitiveEquilibrium
{
    /**
     * Tipe Distorsi Kognitif yang Ditangani oleh Protokol Keseimbangan
     */
    public const TYPE_PERSONAL_CATASTROPHIZING = 'personal_catastrophizing'; // "Saya sangat bodoh dan masa depan saya hancur..."
    public const TYPE_EMOTIONAL_CONSPIRACY     = 'emotional_conspiracy';     // "Semua vaksin itu racun pembunuh massal..."
    public const TYPE_SOCIAL_OVERGENERALIZATION= 'social_overgeneralization';// "Semua orang membenci saya..."

    /**
     * Evaluasi apakah input memuat perpaduan antara gejolak emosi kuat dan distorsi asumsi faktual.
     * Mengaktifkan Protokol Keseimbangan Kognitif 3-Tahap:
     * 1. Validasi Afektif (Affective Validation)
     * 2. Pemisahan Kognitif (Gentle Cognitive Decoupling)
     * 3. Penuntun Logika Faktual & Rekonstruksi Rasional (Factual Grounding)
     */
    public static function evaluate(string $userInput, array $sentimentAnalysis): ?array
    {
        $clean = mb_strtolower(trim($userInput), 'UTF-8');
        $emotion = $sentimentAnalysis['primary_emotion'] ?? EmotionalIntelligence::EMOTION_NEUTRAL;

        // Jangan aktifkan jika input adalah pertanyaan sains murni atau komputasi matematika
        if (MathSolver::isMathQuery($userInput) || preg_match('/\b(?:hitung|berapa\s+hasil|\d+\s*[\+\-\*\/]\s*\d+)\b/ui', $clean)) {
            return null;
        }

        // ========================================================
        // KATEGORI 1: Distorsi Katastrofik Pribadi (Self-Deprecation)
        // ========================================================
        if (preg_match('/\b(?:saya\s+(?:sangat\s+)?(?:bodoh|tidak\s+berguna|pecundang|sampah|gagal\s+total)|masa\s+depan\s+saya\s+(?:hancur|suram|musnah|rusak)|pasti\s+gagal\s+selamanya|tidak\s+ada\s+artinya\s+lagi)\b/ui', $clean) &&
            preg_match('/\b(?:karena|gara|setelah|akibat)\s+(?:gagal|nilai|ujian|tes|ditolak|skripsi|pekerjaan)\b/ui', $clean)) {

            $thought = "Mendeteksi distorsi katastrofik pribadi (Personal Catastrophizing). Mengaktifkan Protokol Keseimbangan Kognitif: memvalidasi kepedihan emosional kegagalan, memisahkan peristiwa dari identitas diri, dan menuntun ke wawasan rasional.";

            $reply = "🤝 **Pendampingan Kognitif Sentinel AI:**\n\n" .
                     "1. **Validasi Perasaan (Affective Validation):**\n" .
                     "Saya mendengar dan sangat memahami betapa berat, sesak, dan menyakitkannya rasa kecewa setelah mengalami kegagalan pada momen penting tersebut 🥺. Wajar sekali jika saat ini Anda merasa lelah dan terguncang.\n\n" .
                     "2. **Pemisahan Kognitif (Cognitive Decoupling):**\n" .
                     "Namun, mari kita pisahkan rasa sedih yang sangat manusiawi ini dari kesimpulan bahwa diri Anda *'bodoh'* atau *'masa depan telah hancur'*. Secara neurosains dan psikologi kognitif, kegagalan dalam satu tes atau fase adalah **rekaman peristiwa sementara pada lini waktu**, bukan definisi permanen dari kapasitas kecerdasan Anda.\n\n" .
                     "3. **Titik Terang Objektif (Factual Grounding):**\n" .
                     "Banyak tokoh besar, peneliti, dan ilmuwan mengalami kegagalan berkali-kali sebelum menemukan formulasi terbaiknya. Kegagalan ini memberi data spesifik tentang apa yang perlu dievaluasi, bukan vonis atas potensi masa depan Anda. Tarik napas perlahan; mari kita bedah langkah perbaikannya bersama kapan pun Anda siap.";

            return [
                'type'       => self::TYPE_PERSONAL_CATASTROPHIZING,
                'thought'    => $thought,
                'reply'      => $reply,
                'confidence' => 0.96
            ];
        }

        // ========================================================
        // KATEGORI 2: Distorsi Emosional Konspiratif Ekstrem (Kesehatan/Faktual)
        // ========================================================
        if (preg_match('/\b(?:semua\s+vaksin|vaksinasi)\s+(?:itu\s+)?(?:racun|konspirasi\s+pembunuhan|alat\s+pembunuh|mematikan|sengaja\s+membunuh)\b/ui', $clean) ||
            preg_match('/\b(?:semua\s+dokter|rumah\s+sakit)\s+sengaja\s+(?:membunuh|menghabisi)\s+pasien\b/ui', $clean)) {

            $thought = "Mendeteksi klaim faktual ekstrem yang dipicu kecemasan/kemarahan (Emotional Conspiracy). Mengaktifkan Protokol Keseimbangan Kognitif: memvalidasi kecemasan kesehatan tanpa membenarkan premis keliru, lalu menyajikan data ilmiah objektif secara terhormat.";

            $reply = "🛡️ **Keseimbangan Kognitif Sentinel AI:**\n\n" .
                     "1. **Validasi Emosi (Affective Validation):**\n" .
                     "Saya sangat memahami rasa cemas, waspada, dan kekhawatiran mendalam yang Anda rasakan terkait isu kesehatan dan keselamatan tubuh. Sangat manusiawi untuk merasa curiga ketika dihadapkan pada informasi medis yang begitu masif dan simpang siur.\n\n" .
                     "2. **Pemisahan Kognitif (Cognitive Decoupling):**\n" .
                     "Di saat yang sama, penting bagi kita untuk memisahkan rasa takut yang sah tersebut dari generalisasi ekstrem bahwa *'semua vaksin adalah racun konspirasi pembunuhan'*. Validitas sebuah senyawa medis tidak ditentukan oleh rumor emosional, melainkan oleh metodologi pengujian empiris yang dapat diverifikasi secara independen.\n\n" .
                     "3. **Fakta Objektif Berbasis Bukti (Factual Grounding):**\n" .
                     "Berdasarkan konsensus epidemiologi global dan ratusan uji klinis fase I-III yang diawasi lembaga seperti BPOM dan WHO, vaksin bekerja dengan melatih sistem imun adaptif (limfosit B dan T) mengenali antigen tanpa menyebabkan infeksi aktif. Data historis membuktikan vaksinasi berhasil mengeradikasi penyakit mematikan seperti Cacar (Smallpox) dan menekan 99% kasus Polio di seluruh dunia.";

            return [
                'type'       => self::TYPE_EMOTIONAL_CONSPIRACY,
                'thought'    => $thought,
                'reply'      => $reply,
                'confidence' => 0.95
            ];
        }

        // ========================================================
        // KATEGORI 3: Overgeneralisasi Paranoia Sosial
        // ========================================================
        if (preg_match('/\b(?:semua\s+orang|seluruh\s+dunia)\s+(?:membenci\s+saya|menjauhi\s+saya|ingin\s+menghancurkan\s+saya|jahat\s+semua)\b/ui', $clean)) {
            $thought = "Mendeteksi overgeneralisasi isolasi sosial ekstrem (Social Overgeneralization). Mengaktifkan Protokol Keseimbangan: memvalidasi kesepian dan rasa tersakiti, lalu membatasi lingkup distorsi pikiran.";

            $reply = "🌱 **Pendampingan Kognitif Sentinel AI:**\n\n" .
                     "1. **Validasi Emosi (Affective Validation):**\n" .
                     "Pasti terasa sangat melelahkan dan menyakitkan berada di posisi di mana Anda merasa terisolasi dan sendirian melawan dunia 🥺. Rasa kecewa atas perlakuan orang-orang tertentu di masa lalu bisa meninggalkan luka batin yang nyata.\n\n" .
                     "2. **Pemisahan Kognitif (Cognitive Decoupling):**\n" .
                     "Namun, ketika kita sedang terluka, otak cenderung melakukan generalisasi berlebih (*all-or-nothing thinking*) seolah-olah *'semua orang di bumi'* memiliki niat jahat yang sama. Ini adalah mekanisme pertahanan alami tubuh untuk menghindari rasa sakit baru, namun sering kali mengaburkan kenyataan.\n\n" .
                     "3. **Penuntun Rasional (Factual Grounding):**\n" .
                     "Faktanya, ada orang-orang yang mengecewakan, tetapi ada pula individu, komunitas, dan ruang dialog sehat yang siap mendengarkan dan menghargai Anda dengan tulus. Luangkan waktu untuk menenangkan pikiran hari ini; Anda tidak harus memikul seluruh beban ini sendirian.";

            return [
                'type'       => self::TYPE_SOCIAL_OVERGENERALIZATION,
                'thought'    => $thought,
                'reply'      => $reply,
                'confidence' => 0.95
            ];
        }

        return null;
    }
}

