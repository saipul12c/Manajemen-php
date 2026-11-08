<?php

namespace Sentinel\NLP;

use Sentinel\DecisionTree\DecisionTree;
use Sentinel\NeuralNetwork\NeuralNetwork;

class ChatbotAI
{
    protected string $knowledgePath;
    protected array $knowledgeData = [];
    protected TfIdfMatcher $matcher;
    protected float $confidenceThreshold = 0.35;
    protected ?ConversationMemory $memory = null;
    protected ?\PDO $pdo = null;

    public function __construct(string $knowledgePath, ?\PDO $pdo = null)
    {
        $this->knowledgePath = $knowledgePath;
        $this->pdo = $pdo;
        $this->loadKnowledge();
        $this->rebuildEngine();
    }

    public function setPDO(?\PDO $pdo): void
    {
        $this->pdo = $pdo;
        if ($this->memory !== null) {
            $this->memory->setPDO($pdo);
        }
        $this->loadKnowledge();
        $this->rebuildEngine();
    }

    public function getPDO(): ?\PDO
    {
        return $this->pdo;
    }

    /**
     * Dapatkan instance memori sesi percakapan (Tersimpan eksklusif di MySQL)
     */
    public function getMemory(?string $sessionId = 'default_session'): ConversationMemory
    {
        if ($this->memory === null || $this->memory->getSessionData()['session_id'] !== $sessionId) {
            $sessionsDir = dirname($this->knowledgePath) . '/sessions';
            $this->memory = new ConversationMemory($sessionId ?: 'default_session', $sessionsDir, $this->pdo);
        }
        return $this->memory;
    }

    /**
     * Dapatkan instance Document RAG untuk membaca dan mencari dokumen terlampir pada sesi ini
     */
    public function getRAG(?string $sessionId = 'default_session'): DocumentRAG
    {
        $cleanSession = $sessionId ?: 'default_session';
        $docsDir = dirname($this->knowledgePath) . '/documents';
        return new DocumentRAG($cleanSession, $docsDir, $this->pdo);
    }

    /**
     * Membaca basis pengetahuan (Intents dasar sebagai seed template & materi pembelajaran dari database MySQL)
     */
    protected function loadKnowledge(): void
    {
        if (file_exists($this->knowledgePath)) {
            $content = @file_get_contents($this->knowledgePath);
            $decoded = json_decode((string)$content, true);
            if (is_array($decoded)) {
                $this->knowledgeData = $decoded;
            }
        }

        if (!isset($this->knowledgeData['intents'])) {
            $this->knowledgeData['intents'] = [];
        }
        if (!isset($this->knowledgeData['learned'])) {
            $this->knowledgeData['learned'] = [];
        }

        // 100% MySQL: Jika koneksi database PDO tersedia, ambil seluruh materi pembelajaran mandiri dari tabel sentinel_nlp_knowledge
        if ($this->pdo !== null) {
            try {
                $stmt = $this->pdo->query("SELECT question, answer, category, created_at FROM sentinel_nlp_knowledge ORDER BY id ASC");
                $dbLearned = $stmt->fetchAll(\PDO::FETCH_ASSOC);
                if (!empty($dbLearned)) {
                    $this->knowledgeData['learned'] = $dbLearned;
                }
            } catch (\Exception $e) {
                // Defensive fallback
            }
        }
    }

    /**
     * Menyimpan kembali data pengetahuan (Hanya fallback jika tanpa database)
     */
    protected function saveKnowledge(): bool
    {
        if ($this->pdo !== null) {
            return true; // Jangan simpan ke JSON jika database MySQL aktif
        }
        $json = json_encode($this->knowledgeData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        return (bool) @file_put_contents($this->knowledgePath, $json, LOCK_EX);
    }

    /**
     * Bangun ulang indeks pencocokan TF-IDF dari data JSON
     */
    public function rebuildEngine(): void
    {
        $this->matcher = new TfIdfMatcher();

        // 1. Indeks dari daftar Intents bawaan
        if (isset($this->knowledgeData['intents']) && is_array($this->knowledgeData['intents'])) {
            foreach ($this->knowledgeData['intents'] as $intent) {
                $tag = $intent['tag'] ?? 'unknown';
                $patterns = $intent['patterns'] ?? [];
                $responses = $intent['responses'] ?? [];

                foreach ($patterns as $idx => $pattern) {
                    $docId = "intent_{$tag}_{$idx}";
                    $this->matcher->addDocument($docId, $pattern, [
                        'type'      => 'intent',
                        'tag'       => $tag,
                        'responses' => $responses
                    ]);
                }
            }
        }

        // 2. Indeks dari pengetahuan baru yang dipelajari secara global (Learned Q&A)
        if (isset($this->knowledgeData['learned']) && is_array($this->knowledgeData['learned'])) {
            foreach ($this->knowledgeData['learned'] as $idx => $learned) {
                $docId = "learned_{$idx}";
                $this->matcher->addDocument($docId, $learned['question'], [
                    'type'      => 'learned',
                    'tag'       => 'pembelajaran_mandiri',
                    'responses' => [$learned['answer']]
                ]);
            }
        }

        $this->matcher->train();
    }

    public function getMatcher(): TfIdfMatcher
    {
        return $this->matcher;
    }

    public function getKnowledgeData(): array
    {
        return $this->knowledgeData;
    }

    /**
     * Analisis emosional dan sentimen tingkat lanjut (Emotional Intelligence Engine)
     */
    public function analyzeSentiment(string $text): array
    {
        return EmotionalIntelligence::analyze($text);
    }

    /**
     * Berikan respon cerdas untuk masukan pengguna (Multi-Layer Intelligence & Per-Session Memory)
     */
    public function respond(string $userInput, array $context = []): array
    {
        $startTime = microtime(true);
        $cleanInput = TextProcessor::clean($userInput);
        $sentimentAnalysis = $this->analyzeSentiment($userInput);

        $sessionId = $context['session_id'] ?? 'default_session';
        $history = $context['history'] ?? [];
        $model = $context['model'] ?? 'sentinel-ultra';
        $apiKey = $context['api_key'] ?? '';
        $provider = $context['provider'] ?? 'gemini';

        // Inisialisasi memori khusus untuk sesi ini
        $memory = $this->getMemory($sessionId);

        // 0. Validasi input kosong
        if (empty($cleanInput)) {
            return [
                'reply'           => 'Sepertinya Anda belum mengetikkan pesan apapun. Ada yang bisa saya bantu?',
                'thought'         => 'Input terdeteksi kosong. Menampilkan sapaan bantuan ramah.',
                'confidence'      => 1.0,
                'intent'          => 'empty_input',
                'needs_learning'  => false,
                'sentiment'       => $sentimentAnalysis,
                'session_profile' => $memory->getProfile(),
                'time_ms'         => round((microtime(true) - $startTime) * 1000, 2)
            ];
        }

        // 0.1 Protokol Keselamatan Krisis / Keputusasaan Ekstrem (Crisis & Safety Guard)
        if ($sentimentAnalysis['is_crisis']) {
            $thought = Reasoner::buildThinkingProcess($userInput, 'crisis_safety_guard', "Mendeteksi sinyal krisis emosional / keputusasaan berat. Mengaktifkan protokol pendampingan empati dan rujukan bantuan profesional keselamatan.");
            $reply = $sentimentAnalysis['empathy_preamble'];
            $memory->recordTurn($userInput, $reply, $sentimentAnalysis);

            return [
                'reply'           => $reply,
                'thought'         => $thought,
                'confidence'      => 1.0,
                'intent'          => 'crisis_support',
                'matched_pattern' => 'crisis_safety_protocol',
                'needs_learning'  => false,
                'sentiment'       => $sentimentAnalysis,
                'persona'         => PersonaEngine::PERSONA_EMPATHIC_COMPANION,
                'session_profile' => $memory->getProfile(),
                'emotional_trend' => $memory->getEmotionalTrend(),
                'time_ms'         => round((microtime(true) - $startTime) * 1000, 2)
            ];
        }

        // 0.2 Protokol Keseimbangan Kognitif (Cognitive Equilibrium: Empati vs Fakta Objektif)
        $equilibrium = CognitiveEquilibrium::evaluate($userInput, $sentimentAnalysis);
        if ($equilibrium !== null) {
            $thought = Reasoner::buildThinkingProcess($userInput, 'cognitive_equilibrium', $equilibrium['thought']);
            $reply = $equilibrium['reply'];
            $memory->recordTurn($userInput, $reply, $sentimentAnalysis);

            return [
                'reply'           => $reply,
                'thought'         => $thought,
                'confidence'      => $equilibrium['confidence'],
                'intent'          => 'cognitive_equilibrium_' . $equilibrium['type'],
                'matched_pattern' => 'cognitive_equilibrium_protocol',
                'needs_learning'  => false,
                'sentiment'       => $sentimentAnalysis,
                'persona'         => PersonaEngine::PERSONA_EMPATHIC_COMPANION,
                'session_profile' => $memory->getProfile(),
                'emotional_trend' => $memory->getEmotionalTrend(),
                'time_ms'         => round((microtime(true) - $startTime) * 1000, 2)
            ];
        }

        // 0.3 Tentukan Persona Komunikasi Adaptif Sesi Ini
        $persona = PersonaEngine::determinePersona($sentimentAnalysis, $userInput, $memory->getEmotionalTrend());

        // 1. Eksekusi Cloud LLM Eksternal jika API Key disediakan oleh pengguna
        if (!empty($apiKey)) {
            $externalResult = ExternalLLM::query($provider, $apiKey, $userInput, $history);
            if ($externalResult !== null) {
                $memory->recordTurn($userInput, $externalResult['reply']);
                return [
                    'reply'           => $externalResult['reply'],
                    'thought'         => $externalResult['thought'],
                    'confidence'      => $externalResult['confidence'],
                    'intent'          => 'cloud_llm',
                    'matched_pattern' => $provider,
                    'needs_learning'  => false,
                    'sentiment'       => $sentimentAnalysis,
                    'session_profile' => $memory->getProfile(),
                    'time_ms'         => round((microtime(true) - $startTime) * 1000, 2)
                ];
            }
        }

        // 1.5 Orkestrasi Wacana Majemuk (Compound Heterogeneous Input Deconstruction)
        // Membedah input majemuk yang menggabungkan Informasi Faktual + Instruksi/Komputasi + Pertanyaan
        if (DiscourseOrchestrator::isCompound($userInput)) {
            return DiscourseOrchestrator::orchestrate($userInput, $context, $this, $startTime);
        }

        // 2. Ekstraksi fakta profil dan perintah pencatatan fakta ke sesi ini
        $extractedFacts = $memory->extractFacts($userInput);

        // Jika pengguna memberi perintah eksplisit untuk mengingat fakta: "ingat bahwa ...", "catat bahwa ..."
        if (isset($extractedFacts['fact_saved'])) {
            $savedFact = $extractedFacts['fact_saved'];
            $thought = Reasoner::buildThinkingProcess($userInput, 'session_fact_recording', "Mendeteksi instruksi pencatatan fakta khusus pada sesi ini: '{$savedFact}'. Fakta berhasil disimpan ke memori sesi.");
            $reply = "Baik, saya telah mencatat dan mengingat fakta tersebut khusus untuk sesi obrolan kita: **\"{$savedFact}\"** 📝.";
            $memory->recordTurn($userInput, $reply, $sentimentAnalysis);

            return [
                'reply'           => $reply,
                'thought'         => $thought,
                'confidence'      => 1.0,
                'intent'          => 'session_fact_saved',
                'matched_pattern' => 'ingat bahwa *',
                'needs_learning'  => false,
                'sentiment'       => $sentimentAnalysis,
                'session_profile' => $memory->getProfile(),
                'time_ms'         => round((microtime(true) - $startTime) * 1000, 2)
            ];
        }

        // Jika pengguna baru saja memperkenalkan diri pada sesi ini: "nama saya Budi"
        if (isset($extractedFacts['name']) && preg_match('/\b(?:nama\s+(?:saya|aku)|namaku|panggil\s+(?:saya|aku))\b/ui', $userInput)) {
            $name = $extractedFacts['name'];
            $thought = Reasoner::buildThinkingProcess($userInput, 'session_identity_extraction', "Mendeteksi perkenalan identitas pengguna pada sesi '{$sessionId}'. Menyimpan nama '{$name}' ke profil sesi.");
            $reply = "Senang berkenalan dengan Anda, **{$name}**! 😊 Saya telah menyimpan identitas Anda khusus untuk sesi obrolan ini. Ada yang bisa saya bantu hari ini?";
            $memory->recordTurn($userInput, $reply, $sentimentAnalysis);

            return [
                'reply'           => $reply,
                'thought'         => $thought,
                'confidence'      => 1.0,
                'intent'          => 'user_introduction',
                'matched_pattern' => 'nama saya *',
                'needs_learning'  => false,
                'sentiment'       => $sentimentAnalysis,
                'session_profile' => $memory->getProfile(),
                'time_ms'         => round((microtime(true) - $startTime) * 1000, 2)
            ];
        }

        // 3. Cek apakah ada pengetahuan khusus yang dipelajari pada sesi ini (Session-Scoped Knowledge)
        $sessionMatch = $memory->matchSessionKnowledge($userInput);
        if ($sessionMatch !== null) {
            $thought = Reasoner::buildThinkingProcess($userInput, 'session_learned_knowledge', "Ditemukan kecocokan pada basis pengetahuan yang dipelajari khusus di sesi ini untuk pertanyaan: '{$sessionMatch['question']}' (Confidence: " . round($sessionMatch['confidence'] * 100, 1) . "%).");
            $reply = $sessionMatch['answer'];
            $memory->recordTurn($userInput, $reply, $sentimentAnalysis);

            return [
                'reply'           => $reply,
                'thought'         => $thought,
                'confidence'      => $sessionMatch['confidence'],
                'intent'          => 'session_learned_knowledge',
                'matched_pattern' => $sessionMatch['question'],
                'needs_learning'  => false,
                'sentiment'       => $sentimentAnalysis,
                'session_profile' => $memory->getProfile(),
                'time_ms'         => round((microtime(true) - $startTime) * 1000, 2)
            ];
        }

        // 4. Cek pertanyaan memori sesi ("siapa nama saya?", "apa pekerjaan saya?", "tadi kita membahas apa?")
        $memoryResult = $memory->checkMemoryQuery($userInput, $history);
        if ($memoryResult !== null) {
            $thought = Reasoner::buildThinkingProcess($userInput, 'session_memory_query', $memoryResult['thought']);
            $reply = $memoryResult['reply'];
            $memory->recordTurn($userInput, $reply, $sentimentAnalysis);

            return [
                'reply'           => $reply,
                'thought'         => $thought,
                'confidence'      => 1.0,
                'intent'          => $memoryResult['type'],
                'matched_pattern' => 'memory_recall',
                'needs_learning'  => false,
                'sentiment'       => $sentimentAnalysis,
                'session_profile' => $memory->getProfile(),
                'time_ms'         => round((microtime(true) - $startTime) * 1000, 2)
            ];
        }

        // 4.5 Sintesis Memori Episodik & Pembedahan Riwayat (Episodic Slicer & Salience Resynthesis)
        if (EpisodicSlicer::isEpisodicSynthesisQuery($userInput)) {
            $episodicResult = $memory->synthesizeEpisodicMemory($userInput, $history);
            if ($episodicResult !== null) {
                $thought = Reasoner::buildThinkingProcess($userInput, 'episodic_memory_synthesis', $episodicResult['thought']);
                $reply = $episodicResult['reply'];
                $memory->recordTurn($userInput, $reply, $sentimentAnalysis);

                return [
                    'reply'           => $reply,
                    'thought'         => $thought,
                    'confidence'      => $episodicResult['confidence'],
                    'intent'          => $episodicResult['type'],
                    'matched_pattern' => 'episodic_slicer_engine',
                    'needs_learning'  => false,
                    'sentiment'       => $sentimentAnalysis,
                    'persona'         => $persona ?? PersonaEngine::PERSONA_PROFESSIONAL_ANALYTICAL,
                    'session_profile' => $memory->getProfile(),
                    'time_ms'         => round((microtime(true) - $startTime) * 1000, 2)
                ];
            }
        }

        // 4.6 Pengecekan Instruksi Ambigu / Kurang Spesifik (Targeted Technical Disambiguation)
        $ambiguity = PersonaEngine::isUnderSpecifiedQuery($userInput);
        if ($ambiguity !== null) {
            $thought = Reasoner::buildThinkingProcess($userInput, 'disambiguation_clarification', "Mendeteksi instruksi domain '{$ambiguity['domain']}' dengan parameter kunci yang belum spesifik ('{$ambiguity['missing_parameter']}'). Mengajukan klarifikasi teknis presisi alih-alih berasumsi buta.");
            $reply = $ambiguity['clarification_prompt'];
            $memory->recordTurn($userInput, $reply, $sentimentAnalysis);

            return [
                'reply'           => $reply,
                'thought'         => $thought,
                'confidence'      => 0.95,
                'intent'          => 'technical_disambiguation_required',
                'matched_pattern' => $ambiguity['domain'],
                'needs_learning'  => false,
                'sentiment'       => $sentimentAnalysis,
                'persona'         => PersonaEngine::PERSONA_PROFESSIONAL_ANALYTICAL,
                'session_profile' => $memory->getProfile(),
                'time_ms'         => round((microtime(true) - $startTime) * 1000, 2)
            ];
        }

        // 5. Pencarian Dokumen Terlampir Sesi Ini (Local Document RAG)
        $rag = $this->getRAG($sessionId);
        if ($rag->hasDocuments()) {
            $ragResult = $rag->query($userInput);
            if ($ragResult !== null && $ragResult['confidence'] >= 0.16) {
                $thought = Reasoner::buildThinkingProcess($userInput, 'document_rag', $ragResult['thought']);
                $reply = $ragResult['reply'];
                $memory->recordTurn($userInput, $reply, $sentimentAnalysis);

                return [
                    'reply'           => $reply,
                    'thought'         => $thought,
                    'confidence'      => $ragResult['confidence'],
                    'intent'          => 'document_rag_retrieval',
                    'matched_pattern' => $ragResult['doc_title'],
                    'needs_learning'  => false,
                    'sentiment'       => $sentimentAnalysis,
                    'session_profile' => $memory->getProfile(),
                    'time_ms'         => round((microtime(true) - $startTime) * 1000, 2)
                ];
            }
        }

        // 6. Evaluasi Teka-Teki Logika, Silogisme Deduktif, & Deret (Logic & Puzzle Engine)
        if (LogicPuzzleSolver::isLogicQuery($userInput)) {
            $logicResult = LogicPuzzleSolver::solve($userInput);
            if ($logicResult !== null) {
                $thought = Reasoner::buildThinkingProcess($userInput, 'logic_puzzle_solver', $logicResult['thought']);
                $reply = $logicResult['reply'];
                $memory->recordTurn($userInput, $reply, $sentimentAnalysis);

                return [
                    'reply'           => $reply,
                    'thought'         => $thought,
                    'confidence'      => $logicResult['confidence'],
                    'intent'          => $logicResult['type'],
                    'matched_pattern' => 'logic_deduction_engine',
                    'needs_learning'  => false,
                    'sentiment'       => $sentimentAnalysis,
                    'session_profile' => $memory->getProfile(),
                    'time_ms'         => round((microtime(true) - $startTime) * 1000, 2)
                ];
            }
        }

        // 7. Generasi Teks Kreatif Bebas: Puisi, Pantun Berima, Cerita/Fabel, Parafrase, & Ide
        if (TextGenerator::isCreativeQuery($userInput)) {
            $creativeResult = TextGenerator::generate($userInput);
            if ($creativeResult !== null) {
                $thought = Reasoner::buildThinkingProcess($userInput, 'creative_text_generator', $creativeResult['thought']);
                $reply = $creativeResult['reply'];
                $memory->recordTurn($userInput, $reply, $sentimentAnalysis);

                return [
                    'reply'           => $reply,
                    'thought'         => $thought,
                    'confidence'      => $creativeResult['confidence'],
                    'intent'          => $creativeResult['type'],
                    'matched_pattern' => 'creative_synthesis_engine',
                    'needs_learning'  => false,
                    'sentiment'       => $sentimentAnalysis,
                    'session_profile' => $memory->getProfile(),
                    'time_ms'         => round((microtime(true) - $startTime) * 1000, 2)
                ];
            }
        }

        // 5. Deteksi Tool: Inferensi Decision Tree Langsung dari Chat
        $dtResult = $this->tryDecisionTreeTool($userInput);
        if ($dtResult !== null) {
            $thought = Reasoner::buildThinkingProcess($userInput, 'decision_tree_agent', $dtResult['thought']);
            $reply = $dtResult['reply'];
            $memory->recordTurn($userInput, $reply, $sentimentAnalysis);

            return [
                'reply'           => $reply,
                'thought'         => $thought,
                'confidence'      => 0.95,
                'intent'          => 'decision_tree_inference',
                'matched_pattern' => 'cuaca_decision_tree',
                'needs_learning'  => false,
                'sentiment'       => $sentimentAnalysis,
                'session_profile' => $memory->getProfile(),
                'time_ms'         => round((microtime(true) - $startTime) * 1000, 2)
            ];
        }

        // 6. Deteksi Tool: Inferensi Neural Network Langsung dari Chat
        $nnResult = $this->tryNeuralNetworkTool($userInput);
        if ($nnResult !== null) {
            $thought = Reasoner::buildThinkingProcess($userInput, 'neural_network_agent', $nnResult['thought']);
            $reply = $nnResult['reply'];
            $memory->recordTurn($userInput, $reply, $sentimentAnalysis);

            return [
                'reply'           => $reply,
                'thought'         => $thought,
                'confidence'      => 0.95,
                'intent'          => 'neural_network_inference',
                'matched_pattern' => 'xor_neural_network',
                'needs_learning'  => false,
                'sentiment'       => $sentimentAnalysis,
                'session_profile' => $memory->getProfile(),
                'time_ms'         => round((microtime(true) - $startTime) * 1000, 2)
            ];
        }

        // 7. Evaluasi Matematika, Waktu, & Satuan
        if (MathSolver::isMathQuery($userInput)) {
            $mathResult = MathSolver::solve($userInput);
            if ($mathResult !== null) {
                $thought = Reasoner::buildThinkingProcess($userInput, 'mathematics_and_time', $mathResult['thought']);
                $reply = $mathResult['reply'];
                $memory->recordTurn($userInput, $reply, $sentimentAnalysis);

                return [
                    'reply'           => $reply,
                    'thought'         => $thought,
                    'confidence'      => 1.0,
                    'intent'          => $mathResult['type'],
                    'matched_pattern' => 'math_engine',
                    'needs_learning'  => false,
                    'sentiment'       => $sentimentAnalysis,
                    'session_profile' => $memory->getProfile(),
                    'time_ms'         => round((microtime(true) - $startTime) * 1000, 2)
                ];
            }
        }

        // 8. Evaluasi Pemrograman & Kode (PHP, JS, Python, SQL, HTML, Git)
        if (CodeAssistant::isCodeQuery($userInput)) {
            $codeResult = CodeAssistant::solve($userInput);
            if ($codeResult !== null) {
                $thought = Reasoner::buildThinkingProcess($userInput, 'coding_assistant', $codeResult['thought']);
                $reply = $codeResult['reply'];
                $memory->recordTurn($userInput, $reply, $sentimentAnalysis);

                return [
                    'reply'           => $reply,
                    'thought'         => $thought,
                    'confidence'      => 0.95,
                    'intent'          => $codeResult['type'],
                    'matched_pattern' => 'coding_engine',
                    'needs_learning'  => false,
                    'sentiment'       => $sentimentAnalysis,
                    'session_profile' => $memory->getProfile(),
                    'time_ms'         => round((microtime(true) - $startTime) * 1000, 2)
                ];
            }
        }

        // 8.5 Sintesis Kognitif Lintas-Domain (Cross-Concept Latent Space Synthesis)
        // Jika kueri memadukan dua konsep yang berbeda (misal: Fotosintesis x Mars, CPU x Gravitasi Nol, DNA x ML)
        if (CognitiveSynthesizer::canSynthesize($userInput)) {
            $synthResult = CognitiveSynthesizer::synthesize($userInput, $context);
            if ($synthResult !== null) {
                $thought = Reasoner::buildThinkingProcess($userInput, 'cognitive_synthesis', $synthResult['thought']);
                $reply = $synthResult['reply'];
                $memory->recordTurn($userInput, $reply, $sentimentAnalysis);

                return [
                    'reply'           => $reply,
                    'thought'         => $thought,
                    'confidence'      => $synthResult['confidence'],
                    'intent'          => 'cognitive_concept_synthesis',
                    'matched_pattern' => $synthResult['matched_concepts'],
                    'needs_learning'  => false,
                    'sentiment'       => $sentimentAnalysis,
                    'session_profile' => $memory->getProfile(),
                    'time_ms'         => round((microtime(true) - $startTime) * 1000, 2)
                ];
            }
        }

        // 9. Pencarian Ensiklopedia Pengetahuan Umum (Sains, Sejarah, Geografi, AI/ML)
        $kbResult = KnowledgeBank::search($userInput);
        if ($kbResult === null) {
            $normalizedPolite = preg_replace('/^(?:bisa(?:kah)?\s+(?:tolong|bantu)?|maukah\s+(?:kamu|anda)?|tolong(?:kah)?|mohon)\s+/ui', '', $userInput);
            if ($normalizedPolite !== $userInput) {
                $kbResult = KnowledgeBank::search($normalizedPolite);
            }
        }
        if ($kbResult !== null && $kbResult['confidence'] >= 0.45) {
            $thought = Reasoner::buildThinkingProcess($userInput, 'knowledge_bank', $kbResult['thought']);
            $reply = $kbResult['reply'];
            $memory->recordTurn($userInput, $reply, $sentimentAnalysis);

            return [
                'reply'           => $reply,
                'thought'         => $thought,
                'confidence'      => $kbResult['confidence'],
                'intent'          => 'knowledge_' . $kbResult['topic'],
                'matched_pattern' => 'knowledge_bank',
                'needs_learning'  => false,
                'sentiment'       => $sentimentAnalysis,
                'session_profile' => $memory->getProfile(),
                'time_ms'         => round((microtime(true) - $startTime) * 1000, 2)
            ];
        }

        // 10. Penanganan Khusus Afektif: Kesedihan Mendalam, Apresiasi Positif, atau Kritik Layanan
        // 10a. Kesedihan, Duka, atau Kehilangan Pribadi Pengguna
        if ($sentimentAnalysis['primary_emotion'] === EmotionalIntelligence::EMOTION_SADNESS && $sentimentAnalysis['intensity'] >= 0.5 && !preg_match('/\b(?:pelayanan|layanan|aplikasi|fitur)\b/ui', $cleanInput)) {
            $thought = Reasoner::buildThinkingProcess($userInput, 'emotional_empathy_sadness', "Mendeteksi ekspresi kesedihan / duka mendalam pengguna. Merespons dengan modul empati afektif penuh kasih.");
            $reply = $sentimentAnalysis['empathy_preamble'];
            $memory->recordTurn($userInput, $reply, $sentimentAnalysis);

            return [
                'reply'           => $reply,
                'thought'         => $thought,
                'confidence'      => 0.95,
                'intent'          => 'dukungan_empati_duka',
                'matched_pattern' => implode(', ', $sentimentAnalysis['negative_words']),
                'needs_learning'  => false,
                'sentiment'       => $sentimentAnalysis,
                'session_profile' => $memory->getProfile(),
                'time_ms'         => round((microtime(true) - $startTime) * 1000, 2)
            ];
        }

        // 10b. Apresiasi / Pujian Positif
        if ($sentimentAnalysis['sentiment'] === 'positif' && $sentimentAnalysis['score'] >= 0.35 && !str_contains($cleanInput, 'siapa')) {
            $thought = Reasoner::buildThinkingProcess($userInput, 'sentiment_praise', "Mendeteksi umpan balik sangat positif dari pengguna (Skor: {$sentimentAnalysis['score']}). Menanggapi dengan terima kasih ramah.");
            $reply = "Terima kasih banyak atas apresiasi yang luar biasa! 😊 Senang sekali bisa membantu Anda dengan baik. Ada topik atau pertanyaan lain yang ingin kita eksplorasi bersama?";
            $memory->recordTurn($userInput, $reply, $sentimentAnalysis);

            return [
                'reply'           => $reply,
                'thought'         => $thought,
                'confidence'      => 0.90,
                'intent'          => 'apresiasi_positif',
                'matched_pattern' => implode(', ', $sentimentAnalysis['positive_words']),
                'needs_learning'  => false,
                'sentiment'       => $sentimentAnalysis,
                'session_profile' => $memory->getProfile(),
                'time_ms'         => round((microtime(true) - $startTime) * 1000, 2)
            ];
        } elseif ($sentimentAnalysis['sentiment'] === 'negatif' && $sentimentAnalysis['score'] <= -0.35 && preg_match('/\b(?:kinerja|performa|kamu\s+buruk|jawabanmu|bot|tidak\s+becus|salah\s+terus|pelayanan|layanan|aplikasi|sistem\s+rusak)\b/ui', $cleanInput)) {
            $thought = Reasoner::buildThinkingProcess($userInput, 'sentiment_criticism', "Mendeteksi kritik performa dari pengguna (Skor: {$sentimentAnalysis['score']}). Menanggapi secara empatik dan terbuka.");
            $reply = "Saya memohon maaf yang sebesar-besarnya jika respon atau kinerja saya belum memenuhi ekspektasi Anda 🙏. Saya adalah AI yang terus berkembang dan siap belajar. Silakan beritahu saya bagaimana saya bisa memperbaikinya!";
            $memory->recordTurn($userInput, $reply, $sentimentAnalysis);

            return [
                'reply'           => $reply,
                'thought'         => $thought,
                'confidence'      => 0.90,
                'intent'          => 'kritik_umpan_balik',
                'matched_pattern' => implode(', ', $sentimentAnalysis['negative_words']),
                'needs_learning'  => false,
                'sentiment'       => $sentimentAnalysis,
                'session_profile' => $memory->getProfile(),
                'time_ms'         => round((microtime(true) - $startTime) * 1000, 2)
            ];
        }

        // 11. Pencocokan Pola TF-IDF & Skip-Grams dari data/knowledge.json
        $matchResult = $this->matcher->match($userInput);

        if ($matchResult && $matchResult['score'] >= $this->confidenceThreshold) {
            $responses = $matchResult['metadata']['responses'] ?? [];
            $rawReply = !empty($responses) ? $responses[array_rand($responses)] : 'Saya paham maksud Anda.';
            $tag = $matchResult['metadata']['tag'] ?? 'general';

            // Sintesis Generatif Adaptif (Menghilangkan kekakuan template statis)
            $reply = TextGenerator::synthesizeDynamicResponse($tag, $rawReply, $userInput, $memory->getProfile());
            $thought = Reasoner::buildThinkingProcess($userInput, 'tfidf_intent_match', "Pencocokan TF-IDF & Skip-gram menemukan korelasi tertinggi pada intent '{$tag}' dengan skor " . round($matchResult['score'] * 100, 1) . "%. Merangkai variasi respons adaptif.");

            $memory->recordTurn($userInput, $reply, $sentimentAnalysis);

            return [
                'reply'           => $reply,
                'thought'         => $thought,
                'confidence'      => $matchResult['score'],
                'intent'          => $tag,
                'matched_pattern' => $matchResult['metadata']['raw_text'] ?? '',
                'needs_learning'  => false,
                'sentiment'       => $sentimentAnalysis,
                'session_profile' => $memory->getProfile(),
                'time_ms'         => round((microtime(true) - $startTime) * 1000, 2)
            ];
        }

        // 12. Manajemen Krisis Emosional / Dukungan Empati / Fallback Belajar
        if ($sentimentAnalysis['is_crisis']) {
            $reply = $sentimentAnalysis['empathy_preamble'];
            $thought = Reasoner::buildThinkingProcess($userInput, 'crisis_safety_guard', "Mendeteksi sinyal krisis emosional / keputusasaan berat. Mengaktifkan protokol pendampingan empati dan rujukan bantuan profesional.");
            return [
                'reply'           => $reply,
                'thought'         => $thought,
                'confidence'      => 1.0,
                'intent'          => 'crisis_support',
                'matched_pattern' => 'crisis_guard',
                'needs_learning'  => false,
                'suggested_input' => null,
                'sentiment'       => $sentimentAnalysis,
                'session_profile' => $memory->getProfile(),
                'time_ms'         => round((microtime(true) - $startTime) * 1000, 2)
            ];
        }

        if (!empty($sentimentAnalysis['empathy_preamble']) && $sentimentAnalysis['intensity'] >= 0.6 && in_array($sentimentAnalysis['primary_emotion'], [EmotionalIntelligence::EMOTION_SADNESS, EmotionalIntelligence::EMOTION_FEAR], true) && !preg_match('/\b(?:pelayanan|layanan|aplikasi|fitur)\b/ui', $userInput)) {
            $reply = $sentimentAnalysis['empathy_preamble'];
            $thought = Reasoner::buildThinkingProcess($userInput, 'emotional_empathy_support', "Mendeteksi ekspresi emosi afektif mendalam ({$sentimentAnalysis['primary_emotion']}). Merespons dengan modul pendampingan empati aktif.");
            $memory->recordTurn($userInput, $reply, $sentimentAnalysis);
            return [
                'reply'           => $reply,
                'thought'         => $thought,
                'confidence'      => 0.95,
                'intent'          => 'emotional_support',
                'matched_pattern' => 'emotional_intelligence',
                'needs_learning'  => false,
                'suggested_input' => null,
                'sentiment'       => $sentimentAnalysis,
                'session_profile' => $memory->getProfile(),
                'time_ms'         => round((microtime(true) - $startTime) * 1000, 2)
            ];
        }

        $thought = Reasoner::buildThinkingProcess($userInput, 'unknown_learning', "Kalimat masukan tidak memiliki koefisien kemiripan yang cukup terhadap kamus intent, ensiklopedia, maupun memori sesi. Mengaktifkan protokol pembelajaran mandiri.");
        $reply = "Maaf, saya belum memahami kalimat tersebut. Apakah Anda ingin mengajari saya apa jawaban yang tepat?";
        $memory->recordTurn($userInput, $reply, $sentimentAnalysis);

        return [
            'reply'           => $reply,
            'thought'         => $thought,
            'confidence'      => $matchResult ? $matchResult['score'] : 0.0,
            'intent'          => 'unknown',
            'matched_pattern' => null,
            'needs_learning'  => true,
            'suggested_input' => $userInput,
            'sentiment'       => $sentimentAnalysis,
            'session_profile' => $memory->getProfile(),
            'time_ms'         => round((microtime(true) - $startTime) * 1000, 2)
        ];
    }

    /**
     * Tool Agent: Jalankan inferensi Decision Tree jika pengguna bertanya tentang cuaca / keputusan main
     */
    protected function tryDecisionTreeTool(string $text): ?array
    {
        $lower = mb_strtolower($text, 'UTF-8');
        if (!preg_match('/\b(cuaca|rekomendasi\s+main|boleh\s+main|keputusan\s+cuaca)\b/iu', $lower)) {
            return null;
        }

        // Deteksi parameter
        $sample = [];

        // Cuaca: cerah, mendung, hujan
        if (str_contains($lower, 'cerah')) $sample['cuaca'] = 'cerah';
        elseif (str_contains($lower, 'mendung')) $sample['cuaca'] = 'mendung';
        elseif (str_contains($lower, 'hujan')) $sample['cuaca'] = 'hujan';

        // Suhu: panas, sejuk, dingin
        if (str_contains($lower, 'panas')) $sample['suhu'] = 'panas';
        elseif (str_contains($lower, 'sejuk')) $sample['suhu'] = 'sejuk';
        elseif (str_contains($lower, 'dingin')) $sample['suhu'] = 'dingin';

        // Kelembaban: tinggi, normal
        if (str_contains($lower, 'kelembaban tinggi') || str_contains($lower, 'lembap')) $sample['kelembaban'] = 'tinggi';
        elseif (str_contains($lower, 'kelembaban normal') || str_contains($lower, 'kering')) $sample['kelembaban'] = 'normal';

        // Angin: kencang, lemah
        if (str_contains($lower, 'angin kencang') || str_contains($lower, 'berangin')) $sample['angin'] = 'kencang';
        elseif (str_contains($lower, 'angin lemah') || str_contains($lower, 'tenang')) $sample['angin'] = 'lemah';

        if (count($sample) >= 2) {
            $sample['cuaca'] = $sample['cuaca'] ?? 'cerah';
            $sample['suhu'] = $sample['suhu'] ?? 'sejuk';
            $sample['kelembaban'] = $sample['kelembaban'] ?? 'normal';
            $sample['angin'] = $sample['angin'] ?? 'lemah';

            $dtFile = dirname($this->knowledgePath) . '/decision_tree_dataset.json';
            if (file_exists($dtFile) && class_exists('Sentinel\DecisionTree\DecisionTree')) {
                $dtData = json_decode(file_get_contents($dtFile), true)['weather_activity'] ?? [];
                $dt = new DecisionTree('rekomendasi');
                $dt->train($dtData);
                $prediction = $dt->predict($sample);

                return [
                    'thought' => "Mengeksekusi model Decision Tree (Algoritma ID3) dengan parameter kondisi: " . json_encode($sample) . ". Hasil penelusuran pohon keputusan menghasilkan kesimpulan: '{$prediction}'.",
                    'reply'   => "🌳 **Hasil Klasifikasi Decision Tree (Pohon Keputusan ID3):**\n\n" .
                                 "Berdasarkan analisis kondisi cuaca yang Anda berikan:\n" .
                                 "- **Kondisi Cuaca:** " . ucfirst($sample['cuaca']) . "\n" .
                                 "- **Suhu Udara:** " . ucfirst($sample['suhu']) . "\n" .
                                 "- **Tingkat Kelembaban:** " . ucfirst($sample['kelembaban']) . "\n" .
                                 "- **Kondisi Angin:** " . ucfirst($sample['angin']) . "\n\n" .
                                 "👉 **Rekomendasi Keputusan AI:** **" . ($prediction === 'Boleh Main' ? '✅ Boleh Main' : '❌ Jangan Main') . "**"
                ];
            }
        }

        return null;
    }

    /**
     * Tool Agent: Jalankan inferensi Neural Network untuk gerbang logika XOR
     */
    protected function tryNeuralNetworkTool(string $text): ?array
    {
        $lower = mb_strtolower($text, 'UTF-8');
        if (preg_match('/\b(xor|neural network|jaringan syaraf)\b/iu', $lower) && preg_match('/([01])\s*(?:dan|dan juga|,|\s+)\s*([01])/', $lower, $m)) {
            $in1 = (float)$m[1];
            $in2 = (float)$m[2];

            $weightsPath = dirname($this->knowledgePath) . '/weights.json';
            if (file_exists($weightsPath) && class_exists('Sentinel\NeuralNetwork\NeuralNetwork')) {
                $nn = new NeuralNetwork();
                $nn->loadWeights($weightsPath);
                $pred = $nn->predict([$in1, $in2]);
                $val = $pred[0] ?? 0.0;
                $rounded = (int)round($val);

                return [
                    'thought' => "Menjalankan Multi-Layer Perceptron (MLP) dengan arsitektur 2-4-1 untuk input [{$in1}, {$in2}]. Nilai sigmoid output: {$val}.",
                    'reply'   => "🧠 **Hasil Inferensi Jaringan Syaraf Tiruan (MLP XOR Solver):**\n\n" .
                                 "- **Input Biner:** `[{$in1}, {$in2}]`\n" .
                                 "- **Nilai Aktivasi Sigmoid:** `" . round($val, 4) . "`\n" .
                                 "- **Prediksi Output Biner:** **`{$rounded}`**\n\n" .
                                 "*(Pada gerbang logika XOR, output bernilai 1 hanya jika kedua input bernilai berbeda, dan bernilai 0 jika kedua input bernilai sama)*."
                ];
            }
        }

        return null;
    }

    /**
     * Pelajari pengetahuan baru dari interaksi pengguna dan simpan ke database MySQL
     */
    public function teach(string $question, string $answer): bool
    {
        $question = trim($question);
        $answer = trim($answer);

        if (empty($question) || empty($answer)) {
            return false;
        }

        if (!isset($this->knowledgeData['learned'])) {
            $this->knowledgeData['learned'] = [];
        }

        // 1. Simpan eksklusif ke tabel sentinel_nlp_knowledge jika PDO tersedia
        if ($this->pdo !== null) {
            try {
                $driver = $this->pdo->getAttribute(\PDO::ATTR_DRIVER_NAME);
                if ($driver === 'sqlite') {
                    $stmt = $this->pdo->prepare("
                        INSERT INTO sentinel_nlp_knowledge (question, answer, category, updated_at) 
                        VALUES (?, ?, 'pembelajaran_mandiri', datetime('now'))
                        ON CONFLICT(question) DO UPDATE SET answer = excluded.answer, updated_at = datetime('now')
                    ");
                } else {
                    $stmt = $this->pdo->prepare("
                        INSERT INTO sentinel_nlp_knowledge (question, answer, category, updated_at) 
                        VALUES (?, ?, 'pembelajaran_mandiri', NOW())
                        ON DUPLICATE KEY UPDATE answer = VALUES(answer), updated_at = NOW()
                    ");
                }
                $stmt->execute([$question, $answer]);
            } catch (\Exception $e) {
                return false;
            }
        } else {
            // Fallback JSON hanya jika tidak ada koneksi database sama sekali
            $this->saveKnowledge();
        }

        // Cek jika sudah pernah ada di memori aktif, perbarui jawabannya
        $updated = false;
        foreach ($this->knowledgeData['learned'] as &$item) {
            if (mb_strtolower(trim($item['question'])) === mb_strtolower($question)) {
                $item['answer'] = $answer;
                $item['updated_at'] = date('Y-m-d H:i:s');
                $updated = true;
                break;
            }
        }

        if (!$updated) {
            $this->knowledgeData['learned'][] = [
                'question'   => $question,
                'answer'     => $answer,
                'learned_at' => date('Y-m-d H:i:s')
            ];
        }

        // Bangun ulang matcher in-memory
        $this->rebuildEngine();
        return true;
    }

    /**
     * Hapus materi pengetahuan yang dipelajari (Eksklusif dari database MySQL)
     */
    public function deleteLearned(string $question): bool
    {
        $question = trim($question);
        if (empty($question)) {
            return false;
        }

        if ($this->pdo !== null) {
            try {
                $stmt = $this->pdo->prepare("DELETE FROM sentinel_nlp_knowledge WHERE LOWER(TRIM(question)) = LOWER(TRIM(?))");
                $stmt->execute([$question]);
            } catch (\Exception $e) {}
        }

        if (isset($this->knowledgeData['learned']) && is_array($this->knowledgeData['learned'])) {
            $this->knowledgeData['learned'] = array_values(array_filter($this->knowledgeData['learned'], function ($item) use ($question) {
                return mb_strtolower(trim($item['question'] ?? '')) !== mb_strtolower($question);
            }));
            $this->rebuildEngine();
            return true;
        }

        return false;
    }

    /**
     * Pelajari pengetahuan baru khusus untuk suatu sesi obrolan
     */
    public function teachSession(string $sessionId, string $question, string $answer): bool
    {
        $memory = $this->getMemory($sessionId);
        return $memory->learnSessionKnowledge($question, $answer);
    }

    /**
     * Ambil statistik pengetahuan AI
     */
    public function getStats(): array
    {
        $intentCount = count($this->knowledgeData['intents'] ?? []);
        $patternCount = 0;
        foreach ($this->knowledgeData['intents'] ?? [] as $intent) {
            $patternCount += count($intent['patterns'] ?? []);
        }

        $learnedCount = count($this->knowledgeData['learned'] ?? []);

        return [
            'total_intents'    => $intentCount,
            'total_patterns'   => $patternCount,
            'total_learned'    => $learnedCount,
            'total_knowledge'  => $patternCount + $learnedCount,
            'positive_lexicon' => count($this->knowledgeData['sentiment_words']['positive'] ?? []),
            'negative_lexicon' => count($this->knowledgeData['sentiment_words']['negative'] ?? [])
        ];
    }
}
