<?php

namespace Sentinel\NLP;

class DiscourseOrchestrator
{
    public const CLAUSE_INFORMASI = 'informasi';
    public const CLAUSE_INSTRUKSI = 'instruksi';
    public const CLAUSE_PERTANYAAN = 'pertanyaan';

    /**
     * Memeriksa apakah input merupakan wacana majemuk / komposit
     * yang menggabungkan lebih dari satu klausa heterogen
     * (misal: Informasi + Instruksi, Informasi + Pertanyaan, atau Instruksi + Pertanyaan).
     */
    public static function isCompound(string $text): bool
    {
        if (EpisodicSlicer::isEpisodicSynthesisQuery($text)) {
            return false;
        }

        $clauses = self::segmentClauses($text);
        if (count($clauses) < 2) {
            return false;
        }

        $types = [];
        foreach ($clauses as $c) {
            $types[$c['type']] = true;
        }

        // Jika terdapat minimal dua jenis niat yang berbeda
        if (count($types) >= 2) {
            return true;
        }

        // Atau jika terdapat minimal 2 klausa terpisah yang salah satunya memuat instruksi komputasi / pertanyaan eksplisit
        if (count($clauses) >= 2 && (isset($types[self::CLAUSE_INSTRUKSI]) || isset($types[self::CLAUSE_PERTANYAAN]))) {
            return true;
        }

        return false;
    }

    /**
     * Memecah wacana majemuk menjadi segmen klausa semantik dengan
     * Quote & Entity Masking agar tanda baca di dalam kutipan tidak merusak segmentasi.
     */
    public static function segmentClauses(string $text): array
    {
        $raw = trim($text);
        if (empty($raw)) {
            return [];
        }

        // 0. Quote & URL Masking
        $masks = [];
        $maskIndex = 0;

        // Masking URL terlebih dahulu
        $maskedText = preg_replace_callback('/https?:\/\/[^\s]+/i', function ($m) use (&$masks, &$maskIndex) {
            $key = "__SENTINEL_URL_{$maskIndex}__";
            $masks[$key] = $m[0];
            $maskIndex++;
            return $key;
        }, $raw);

        // Masking teks di dalam tanda kutip tunggal/ganda/guillemets ("...", '...', “...”, «...»)
        $maskedText = preg_replace_callback('/([\'"“«])(.*?)(\1|[”»])/u', function ($m) use (&$masks, &$maskIndex) {
            $key = "__SENTINEL_QUOTE_{$maskIndex}__";
            $masks[$key] = $m[0];
            $maskIndex++;
            return $key;
        }, $maskedText);

        // 1. Pecah awal berdasarkan pemisah kalimat utama (. ? ! ; \n)
        // Menjaga agar angka desimal seperti 3.14 tidak terpecah
        $sentenceSplit = preg_split('/(?<!\d)[.?!;\n]+(?!\d)/u', $maskedText, -1, PREG_SPLIT_NO_EMPTY);

        $clauses = [];

        foreach ($sentenceSplit as $sentence) {
            $sentence = trim($sentence);
            if (empty($sentence)) {
                continue;
            }

            // 2. Pecah intra-kalimat berdasarkan kata hubung transisi wacana:
            // "..., lalu ...", "..., kemudian ...", "..., tolong ...", "dan kucing saya...", "dan apa..."
            $subParts = preg_split(
                '/(?:,\s*|\s+)(?:lalu|kemudian|setelah\s+itu|selain\s+itu|berikutnya|dan\s+tolong|dan\s+apakah|dan\s+juga)\s+|\s+dan\s+(?=(?:kucing|anjing|kelinci|burung|ikan|peliharaan|hewan|istri|suami|anak|teman|rekan|proyek|pekerjaan)\b)|\s+dan\s+(?=(?:apa|siapa|apakah|bagaimana|mengapa|kenapa|berapa)\b)|\s*,\s*dan\s+(?=(?:kalikan|tambahkan|kurangi|bagi|bagikan|hitung)\b)|\s+dan\s+(?=(?:kalikan|tambahkan|kurangi|bagi|bagikan|hitung)\b)/ui',
                $sentence,
                -1,
                PREG_SPLIT_NO_EMPTY
            );

            foreach ($subParts as $sub) {
                $sub = trim($sub, " \t\n\r\0\x0B,");
                if (empty($sub)) {
                    continue;
                }

                // Cek jika ada koma yang memisahkan klausa aksi atau kata tanya
                $commaParts = preg_split('/,\s*(?=(?:tolong|coba|hitung|buatkan|jelaskan|apakah|siapakah|apa|kenapa|mengapa|kalikan|tambahkan|kurangi|bagi)\b)/ui', $sub);

                foreach ($commaParts as $cp) {
                    $cp = trim($cp, " \t\n\r\0\x0B,");
                    if (mb_strlen($cp) >= 2) {
                        // Unmasking klausa agar kembali ke teks aslinya
                        $unmaskedCp = !empty($masks) ? str_replace(array_keys($masks), array_values($masks), $cp) : $cp;
                        $classification = self::classifyClause($unmaskedCp);
                        $clauses[] = [
                            'text'     => $unmaskedCp,
                            'type'     => $classification['type'],
                            'sub_type' => $classification['sub_type']
                        ];
                    }
                }
            }
        }

        return $clauses;
    }

    /**
     * Klasifikasikan klausa menjadi Informasi, Instruksi, atau Pertanyaan
     * dengan Normalisasi Tindak Tutur Sopan (Polite Modals Normalization).
     */
    public static function classifyClause(string $clauseText): array
    {
        $clean = mb_strtolower(trim($clauseText), 'UTF-8');

        // A. Normalisasi Tindak Tutur Sopan (Indirect Speech Acts)
        // Contoh: "apakah kamu tahu kalau saya ini dokter bedah" -> ini adalah INFORMASI, bukan pertanyaan!
        if (preg_match('/\b(?:apakah\s+(?:kamu|anda)\s+tahu\s+(?:bahwa|kalau)|tahukah\s+(?:kamu|anda)\s+(?:bahwa|kalau))\s+(.+)/ui', $clean, $m)) {
            return ['type' => self::CLAUSE_INFORMASI, 'sub_type' => 'user_fact'];
        }

        // Contoh: "bisa tolong jelaskan apa itu black hole?" -> ini adalah PERTANYAAN/PENJELASAN ILMIAH, bukan aksi tool!
        $normalized = preg_replace('/^(?:bisa(?:kah)?\s+(?:tolong|bantu)?|maukah\s+(?:kamu|anda)?|tolong(?:kah)?|mohon)\s+/ui', '', $clean);
        if (preg_match('/\b(?:jelaskan|terangkan|ceritakan\s+tentang|apa\s+itu|bagaimana\s+cara\s+kerja)\b/ui', $normalized) && !CodeAssistant::isCodeQuery($clauseText)) {
            return ['type' => self::CLAUSE_PERTANYAAN, 'sub_type' => 'knowledge'];
        }

        // B. Deteksi Instruksi (Imperatif, Komputasi, Aksi Teknis)
        // Matematika & Waktu
        if (MathSolver::isMathQuery($clauseText) || preg_match('/\b(?:hitung(?:kan)?|kalkulasi|jumlahkan|kurangkan|kurangi|kalikan|tambahkan|bagikan|bagi|berapa\s+hasil\s+dari|\d+\s*[\+\-\*\/\^]\s*\d+)\b/ui', $clean)) {
            return ['type' => self::CLAUSE_INSTRUKSI, 'sub_type' => 'math'];
        }
        if (preg_match('/\b(?:jam\s+berapa|pukul\s+berapa|tanggal\s+berapa|hari\s+apa)\b/ui', $clean)) {
            return ['type' => self::CLAUSE_INSTRUKSI, 'sub_type' => 'datetime'];
        }
        // Coding / Pemrograman
        if (CodeAssistant::isCodeQuery($clauseText) || preg_match('/\b(?:buatkan\s+(?:fungsi|kode|script|skrip|program)|tuliskan\s+kode|bikin\s+kodingan)\b/ui', $clean)) {
            return ['type' => self::CLAUSE_INSTRUKSI, 'sub_type' => 'code'];
        }
        // Teks Kreatif
        if (TextGenerator::isCreativeQuery($clauseText) || preg_match('/\b(?:buatkan\s+(?:puisi|pantun|cerita|dongeng|fabel)|ceritakan\s+dongeng)\b/ui', $clean)) {
            return ['type' => self::CLAUSE_INSTRUKSI, 'sub_type' => 'creative'];
        }
        // Teka-Teki Logika
        if (LogicPuzzleSolver::isLogicQuery($clauseText)) {
            return ['type' => self::CLAUSE_INSTRUKSI, 'sub_type' => 'logic'];
        }
        // Imperatif Aksi Langsung
        if (preg_match('/^(?:tolong|harap|coba|silakan)\s+[a-zA-Z]/ui', $clean) && !str_ends_with(trim($clauseText), '?')) {
            return ['type' => self::CLAUSE_INSTRUKSI, 'sub_type' => 'action'];
        }

        // C. Deteksi Pertanyaan (Interogatif, Tanda Tanya, Kata Tanya)
        if (str_ends_with(trim($clauseText), '?') || preg_match('/\b(?:apakah|siapakah|bagaimanakah|mengapakah|kenapakah|apa|siapa|di\s+mana|dimana|ke\s+mana|kemana|dari\s+mana|darimana|mengapa|kenapa|bagaimana|kapan|tahukah\s+kamu|tahu\s+tidak|adakah)\b/ui', $clean)) {
            // Memori pertanyaan identitas atau fakta sesi
            if (preg_match('/\b(?:siapa\s+dia|siapa\s+saya|apa\s+makanan|makanan\s+(?:favorit|kesukaan)|siapa\s+nama|nama\s+kucing|pekerjaan\s+saya|hobi\s+saya|di\s+mana\s+saya|tadi\s+kita|berapa\s+(?:hewan|kucing|anjing))\b/ui', $clean)) {
                return ['type' => self::CLAUSE_PERTANYAAN, 'sub_type' => 'memory'];
            }
            return ['type' => self::CLAUSE_PERTANYAAN, 'sub_type' => 'knowledge'];
        }

        // D. Deteksi Informasi (Deklaratif, Fakta, Perkenalan Diri, Profil, dan Entitas)
        if (preg_match('/\b(?:nama\s+(?:saya|aku)|namaku|panggil\s+(?:saya|aku)|saya\s+adalah|saya\s+seorang|saya\s+tinggal|domisili|asal\s+saya|hobi\s+saya|saya\s+suka|gemar|ingat\s+bahwa|catat\s+bahwa)\b/ui', $clean)) {
            return ['type' => self::CLAUSE_INFORMASI, 'sub_type' => 'user_profile'];
        }
        if (preg_match('/\b(?:kucing|anjing|peliharaan|hewan|istri|suami|anak|teman|rekan|sahabat|proyek|tim|perusahaan|mobil)\s+(?:saya|aku|kami)\b/ui', $clean)) {
            return ['type' => self::CLAUSE_INFORMASI, 'sub_type' => 'entity_fact'];
        }
        if (preg_match('/\b(?:saya\s+(?:punya|memiliki|memelihara|bekerja|baru\s+pindah))\b/ui', $clean)) {
            return ['type' => self::CLAUSE_INFORMASI, 'sub_type' => 'user_fact'];
        }

        // Default klausa deklaratif umum
        return ['type' => self::CLAUSE_INFORMASI, 'sub_type' => 'statement'];
    }

    /**
     * Jalankan orkestrasi wacana majemuk melalui Pipeline Kognitif Multi-Fase
     * dengan Working Memory ($workingMemory) untuk Rantai Eksekusi Beruntun.
     */
    public static function orchestrate(string $userInput, array $context, ChatbotAI $chatbot, float $startTime): array
    {
        $sessionId = $context['session_id'] ?? 'default_session';
        $history = $context['history'] ?? [];
        $memory = $chatbot->getMemory($sessionId);

        $clauses = self::segmentClauses($userInput);

        $infoClauses = [];
        $instructionClauses = [];
        $questionClauses = [];

        foreach ($clauses as $c) {
            if ($c['type'] === self::CLAUSE_INFORMASI) {
                $infoClauses[] = $c;
            } elseif ($c['type'] === self::CLAUSE_INSTRUKSI) {
                $instructionClauses[] = $c;
            } else {
                $questionClauses[] = $c;
            }
        }

        $thoughtSteps = [];
        $thoughtSteps[] = "1. **Dekomposisi Wacana Majemuk:** Menganalisis masukan pengguna dan membagi menjadi " . count($clauses) . " klausa semantik (" . count($infoClauses) . " Informasi, " . count($instructionClauses) . " Instruksi, " . count($questionClauses) . " Pertanyaan).";

        // ==========================================
        // FASE 1: SERAP INFORMASI (Memory Ingestion)
        // ==========================================
        $absorbedFacts = [];
        $newUserName = null;

        if (!empty($infoClauses)) {
            foreach ($infoClauses as $ic) {
                $extracted = $memory->extractFacts($ic['text']);
                if (isset($extracted['name'])) {
                    $newUserName = $extracted['name'];
                }
                if (isset($extracted['fact_saved'])) {
                    $absorbedFacts[] = $extracted['fact_saved'];
                }
                if (isset($extracted['fact_updated'])) {
                    $absorbedFacts[] = $extracted['fact_updated'];
                }
            }

            $ingestionDetail = [];
            if ($newUserName) $ingestionDetail[] = "identitas pengguna '{$newUserName}'";
            if (!empty($absorbedFacts)) $ingestionDetail[] = count($absorbedFacts) . " fakta baru";
            if (empty($ingestionDetail)) $ingestionDetail[] = "konteks wacana latar belakang";

            $thoughtSteps[] = "2. **Penyerapan Informasi & Graf Entitas:** Mengekstraksi " . implode(' dan ', $ingestionDetail) . " serta menyimpannya ke memori aktif sesi '{$sessionId}'.";
        } else {
            $thoughtSteps[] = "2. **Penyerapan Informasi:** Tidak ada fakta profil baru yang perlu disimpan; melanjutkan langsung ke pemrosesan perintah.";
        }

        // ==========================================
        // FASE 2: EKSEKUSI INSTRUKSI (Working Memory / Chained Dependencies)
        // ==========================================
        $workingMemory = [
            'last_computed_value' => null,
            'last_code_snippet'   => null,
            'last_subject'        => null
        ];

        $instructionResults = [];
        if (!empty($instructionClauses)) {
            foreach ($instructionClauses as $isc) {
                $text = $isc['text'];

                // 2a. Resolusi Ketergantungan Rantai Nilai (Working Memory Anaphora)
                // Contoh: "kalikan hasilnya dengan 2", "kurangi 50 dari hasil tadi"
                if ($workingMemory['last_computed_value'] !== null) {
                    $prevVal = $workingMemory['last_computed_value'];
                    // Ganti "hasilnya", "hasil tadi", "hasil tersebut", "angka tersebut" dengan nilai numerik sebelumnya
                    $text = preg_replace('/\b(?:hasil(?:nya|nya\s+tadi|nya\s+tersebut)?|hasil\s+(?:tadi|tersebut)|angka\s+(?:tadi|tersebut)|nilai\s+(?:tadi|tersebut|itu))\b/ui', (string)$prevVal, $text);

                    // Deteksi operasi kelanjutan tanpa operan kiri: "lalu kalikan dengan 2", "dan tambahkan 25", "kurangi 50"
                    if (preg_match('/^(?:lalu|dan|kemudian)?\s*(kalikan|tambahkan|kurangi|bagi|bagikan)\s+(?:dengan\s+)?(\d+(?:[\.,]\d+)?)$/ui', trim($text), $opMatch)) {
                        $opVerb = mb_strtolower($opMatch[1]);
                        $operand = (float)str_replace(',', '.', $opMatch[2]);
                        $sym = '*';
                        if ($opVerb === 'tambahkan') $sym = '+';
                        elseif ($opVerb === 'kurangi') $sym = '-';
                        elseif (str_starts_with($opVerb, 'bag')) $sym = '/';

                        $text = "{$prevVal} {$sym} {$operand}";
                    }
                }

                $res = null;

                if (MathSolver::isMathQuery($text)) {
                    $res = MathSolver::solve($text);
                    // Tangkap hasil numerik untuk working memory
                    if ($res !== null && isset($res['reply'])) {
                        if (preg_match('/(?:Hasil Akhir.*?:\s*\*\*|mathbf\{)([\d\.,]+)(?:\*\*|\})/ui', $res['reply'], $ansM)) {
                            $cleanNumStr = str_replace('.', '', $ansM[1]);
                            $cleanNumStr = str_replace(',', '.', $cleanNumStr);
                            $workingMemory['last_computed_value'] = (float)$cleanNumStr;
                        }
                    }
                } elseif (CodeAssistant::isCodeQuery($text)) {
                    $res = CodeAssistant::solve($text);
                } elseif (TextGenerator::isCreativeQuery($text)) {
                    $res = TextGenerator::generate($text);
                } elseif (LogicPuzzleSolver::isLogicQuery($text)) {
                    $res = LogicPuzzleSolver::solve($text);
                } else {
                    $res = MathSolver::solve($text);
                }

                if ($res !== null) {
                    $instructionResults[] = [
                        'clause' => $text,
                        'reply'  => $res['reply'],
                        'thought'=> $res['thought'] ?? ''
                    ];
                }
            }
            $thoughtSteps[] = "3. **Eksekusi Instruksi Berantai (Working Memory):** Menjalankan modul komputasi dengan transfer konteks hasil antar-instruksi untuk " . count($instructionResults) . " perintah.";
        } else {
            $thoughtSteps[] = "3. **Eksekusi Instruksi:** Tidak ada instruksi komputasi langsung; melanjutkan ke penalaran pertanyaan.";
        }

        // ==========================================
        // FASE 3: JAWAB PERTANYAAN (Context & Knowledge)
        // ==========================================
        $questionResults = [];
        if (!empty($questionClauses)) {
            foreach ($questionClauses as $qc) {
                $text = $qc['text'];
                $qReply = null;

                // 3a. Cek memori sesi (termasuk fakta dan graf entitas EAV yang baru diserap di Fase 1!)
                $memRes = $memory->checkMemoryQuery($text, $history);
                if ($memRes !== null) {
                    $qReply = $memRes['reply'];
                }

                // 3b. Cek Document RAG jika ada dokumen terlampir
                if ($qReply === null) {
                    $rag = $chatbot->getRAG($sessionId);
                    if ($rag->hasDocuments()) {
                        $ragRes = $rag->query($text);
                        if ($ragRes !== null && $ragRes['confidence'] >= 0.16) {
                            $qReply = $ragRes['reply'];
                        }
                    }
                }

                // 3c. Cek Cognitive Synthesizer (Sintesis Penalaran Lintas-Konsep)
                if ($qReply === null) {
                    $synthText = $text;
                    if (!CognitiveSynthesizer::canSynthesize($synthText)) {
                        $combinedContext = (!empty($infoClauses) ? implode(' ', array_column($infoClauses, 'text')) . ' ' : '') . $text;
                        if (CognitiveSynthesizer::canSynthesize($combinedContext)) {
                            $synthText = $combinedContext;
                        } elseif (CognitiveSynthesizer::canSynthesize($userInput)) {
                            $synthText = $userInput;
                        }
                    }

                    if (CognitiveSynthesizer::canSynthesize($synthText)) {
                        $synth = CognitiveSynthesizer::synthesize($synthText, $context);
                        if ($synth !== null) {
                            $qReply = $synth['reply'];
                        }
                    }
                }

                // 3d. Cek Knowledge Bank (Ensiklopedia)
                if ($qReply === null) {
                    $kbRes = KnowledgeBank::search($text);
                    if ($kbRes !== null && $kbRes['confidence'] >= 0.45) {
                        $qReply = $kbRes['reply'];
                    }
                }

                // 3d. Cek TF-IDF Matcher bawaan
                if ($qReply === null) {
                    $matchResult = $chatbot->getMatcher()->match($text);
                    if ($matchResult && $matchResult['score'] >= 0.30) {
                        $responses = $matchResult['metadata']['responses'] ?? [];
                        $raw = !empty($responses) ? $responses[array_rand($responses)] : 'Saya memahami maksud pertanyaan Anda.';
                        $tag = $matchResult['metadata']['tag'] ?? 'general';
                        $qReply = TextGenerator::synthesizeDynamicResponse($tag, $raw, $text, $memory->getProfile());
                    }
                }

                // Fallback jika belum ditemukan
                if ($qReply === null) {
                    $qReply = "Terkait pertanyaan tersebut (*\"{$text}\"*), saya telah mencatat konteksnya untuk dipelajari lebih lanjut.";
                }

                $questionResults[] = [
                    'clause' => $text,
                    'reply'  => $qReply
                ];
            }
            $thoughtSteps[] = "4. **Penalaran & Resolusi Pertanyaan:** Menelusuri korelasi semantik pada basis memori sesi terbarui dan ensiklopedia pengetahuan.";
        } else {
            $thoughtSteps[] = "4. **Penalaran Pertanyaan:** Tidak ada pertanyaan tambahan dalam wacana.";
        }

        $thoughtSteps[] = "5. **Sintesis Respon Adaptif:** Memadukan hasil komputasi dan jawaban analitis secara kontekstual.";

        // ==========================================
        // FASE 4: SINTESIS JAWABAN TERPADU & ADAPTIF
        // ==========================================
        $replyParts = [];

        // Evaluasi apakah respon bertipe kasual/percakapan mengalir atau teknis/kompleks
        $isCasual = count($instructionResults) <= 1 && count($questionResults) <= 1 && (empty($instructionResults) || !str_contains($instructionResults[0]['reply'], '$$'));

        // 4a. Sambutan / Konfirmasi Informasi
        $profile = $memory->getProfile();
        if ($newUserName) {
            $replyParts[] = "Senang berkenalan dengan Anda, **{$newUserName}**! 😊 Saya telah menyimpan profil Anda di sesi percakapan ini.";
        } elseif (!empty($absorbedFacts)) {
            $replyParts[] = "Baik, saya telah mencatat informasi yang Anda berikan ke dalam memori sesi obrolan kita 📝.";
        }

        // 4b. Bagian Hasil Instruksi
        if (!empty($instructionResults)) {
            if ($isCasual && count($instructionResults) === 1) {
                $replyParts[] = $instructionResults[0]['reply'];
            } else {
                $replyParts[] = "### ⚡ Hasil Instruksi & Komputasi:";
                foreach ($instructionResults as $idx => $ir) {
                    $num = count($instructionResults) > 1 ? ($idx + 1) . ". " : "";
                    $replyParts[] = "{$num}" . $ir['reply'];
                }
            }
        }

        // 4c. Bagian Jawaban Pertanyaan
        if (!empty($questionResults)) {
            if ($isCasual && count($questionResults) === 1 && empty($instructionResults)) {
                $replyParts[] = $questionResults[0]['reply'];
            } else {
                $replyParts[] = "### 💡 Jawaban Pertanyaan:";
                foreach ($questionResults as $idx => $qr) {
                    $num = count($questionResults) > 1 ? ($idx + 1) . ". " : "";
                    $replyParts[] = "{$num}" . $qr['reply'];
                }
            }
        }

        // 4d. Kebijaksanaan Algoritmik: Penutup interaktif adaptif (hanya muncul saat mode eksploratif/kolaboratif)
        $sentimentAnalysis = $chatbot->analyzeSentiment($userInput);
        $persona = PersonaEngine::determinePersona($sentimentAnalysis, $userInput, $memory->getEmotionalTrend());

        if (PersonaEngine::shouldIncludeFollowUpPrompt($persona, $userInput, $context)) {
            $replyParts[] = "💡 *Ada sudut pandang atau skenario lanjutan yang ingin kita eksplorasi bersama?*";
        }

        $finalReply = implode("\n\n", $replyParts);
        $finalThought = implode("\n\n", $thoughtSteps);

        // Catat interaksi ke memori sesi
        $memory->recordTurn($userInput, $finalReply, $sentimentAnalysis);

        return [
            'reply'           => $finalReply,
            'thought'         => $finalThought,
            'confidence'      => 0.98,
            'intent'          => 'compound_discourse_orchestration',
            'matched_pattern' => 'compound_input',
            'needs_learning'  => false,
            'sentiment'       => $sentimentAnalysis,
            'session_profile' => $memory->getProfile(),
            'time_ms'         => round((microtime(true) - $startTime) * 1000, 2)
        ];
    }
}
