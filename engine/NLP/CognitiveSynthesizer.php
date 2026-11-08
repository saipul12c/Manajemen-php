<?php

namespace Sentinel\NLP;

class CognitiveSynthesizer
{
    /**
     * Ambang batas kemiripan konsep leksikal/semantik
     */
    protected const SIMILARITY_THRESHOLD = 0.30;

    /**
     * Cache in-memory untuk ontologi eksternal (data/ontology.json)
     */
    protected static ?array $cachedOntology = null;

    /**
     * Memeriksa apakah input memuat kombinasi multi-konsep atau skenario hipotetis
     * yang dapat disintesis menjadi respon baru.
     */
    public static function canSynthesize(string $userInput): bool
    {
        $anchors = self::extractConceptAnchors($userInput);

        // Memerlukan minimal 2 konsep berbeda untuk melakukan conceptual blending
        if (count($anchors) >= 2) {
            return true;
        }

        // Atau 1 konsep dengan pemicu skenario hipotetis / ekstrapolasi batas
        if (count($anchors) === 1 && preg_match('/\b(?:jika|kalau|bagaimana\s+jika|seandainya|apakah\s+bisa|mungkinkah|dampaknya|pengaruhnya|tanpa|ekstrem|dipaksa)\b/ui', $userInput)) {
            return true;
        }

        return false;
    }

    /**
     * Lakukan sintesis generatif di ruang laten (Conceptual Blending, N-Way Synthesis, Feasibility Scoring)
     */
    public static function synthesize(string $userInput, array $context = []): ?array
    {
        $anchors = self::extractConceptAnchors($userInput);
        $ontology = self::getOntology();

        if (empty($anchors)) {
            return null;
        }

        // 1. Skenario Multi-Konsep Lintas Domain N-Way (N >= 3)
        if (count($anchors) >= 3) {
            $concepts = [];
            foreach ($anchors as $a) {
                if (isset($ontology[$a['key']])) {
                    $concepts[] = $ontology[$a['key']];
                }
            }
            if (count($concepts) >= 3) {
                return self::blendNWayConcepts($concepts, $userInput);
            }
        }

        // 2. Skenario Dua Konsep Lintas Domain (Pairwise Dual-Space Blending)
        if (count($anchors) >= 2) {
            $conceptKeyA = $anchors[0]['key'];
            $conceptKeyB = $anchors[1]['key'];

            $conceptA = $ontology[$conceptKeyA] ?? null;
            $conceptB = $ontology[$conceptKeyB] ?? null;

            if ($conceptA && $conceptB) {
                return self::blendTwoConcepts($conceptA, $conceptB, $userInput);
            }
        }

        // 3. Skenario 1 Konsep dipadukan dengan Kondisi Batas Hipotetis
        if (count($anchors) === 1) {
            $conceptKey = $anchors[0]['key'];
            $concept = $ontology[$conceptKey] ?? null;
            if ($concept) {
                return self::extrapolateHypotheticalBoundary($concept, $userInput);
            }
        }

        return null;
    }

    /**
     * Ekstraksi simpul-simpul konsep dari input pengguna menggunakan analisis leksikal dan semantik terpandu
     */
    public static function extractConceptAnchors(string $text): array
    {
        $lower = mb_strtolower(trim($text), 'UTF-8');
        $expanded = SemanticEmbeddings::expandQuery($lower);
        $tokens = TextProcessor::tokenize($lower, true);

        $ontology = self::getOntology();
        $matchedAnchors = [];

        foreach ($ontology as $key => $node) {
            $score = 0.0;

            // 1. Pencocokan kata kunci primer (menggunakan batasan kata aman)
            foreach ($node['keywords'] as $kw) {
                $kwLower = mb_strtolower($kw, 'UTF-8');
                $pattern = '/(?:\b|^)' . preg_quote($kwLower, '/') . '(?:\b|$)/ui';
                if (preg_match($pattern, $lower)) {
                    $score += 0.60;
                } elseif (preg_match($pattern, $expanded)) {
                    $score += 0.35;
                }
            }

            // 2. Pencocokan tag semantik
            foreach ($tokens as $token) {
                if (in_array($token, $node['tags'], true)) {
                    $score += 0.20;
                }
            }

            if ($score >= self::SIMILARITY_THRESHOLD) {
                $matchedAnchors[] = [
                    'key'   => $key,
                    'name'  => $node['name'],
                    'score' => round($score, 2)
                ];
            }
        }

        // Urutkan berdasarkan relevansi skor tertinggi
        usort($matchedAnchors, function ($a, $b) {
            return $b['score'] <=> $a['score'];
        });

        // Ambil hingga 4 konsep dominan
        return array_slice($matchedAnchors, 0, 4);
    }

    /**
     * Algoritma Perpaduan Dua Konsep (Pairwise Conceptual Blending)
     * Menggunakan Evaluasi Dimensi & Polaritas Terstruktur untuk Mencegah Kontradiksi Semantik
     */
    protected static function blendTwoConcepts(array $cA, array $cB, string $query): array
    {
        $nameA = $cA['name'];
        $nameB = $cB['name'];
        $domainA = $cA['domain'];
        $domainB = $cB['domain'];

        $thought = "Mendeteksi persoalan skenario baru yang memadukan domain '{$nameA}' ({$domainA}) dan '{$nameB}' ({$domainB}). Mengaktifkan Conceptual Blending Ruang Laten dengan pengecekan polaritas dimensi dan kalkulasi indeks kelayakan.";

        // Evaluasi Interaksi Dimensi & Polaritas
        $evaluation = self::evaluateDimensionalInteractions($cA, $cB);
        $synergies = $evaluation['synergies'];
        $bottlenecks = $evaluation['bottlenecks'];
        $adaptations = $evaluation['adaptations'];
        $assumptions = array_unique(array_merge($cA['epistemic_assumptions'] ?? [], $cB['epistemic_assumptions'] ?? []));

        // Kalkulasi Skor Kelayakan Kuantitatif (Feasibility Index Phi)
        $feasibility = self::calculateFeasibilityScore($cA, $cB, $synergies, $bottlenecks, $adaptations);

        // Deteksi Kedalaman / Gaya Pertanyaan (Adaptive Depth Controller)
        $isDeepAnalysis = preg_match('/\b(?:analisis|bagaimana\s+dampak|jelaskan|pengaruh|mekanisme|sintesis|telaah|rinci)\b/ui', $query);

        $sections = [];
        $sections[] = "🔬 **Sintesis Analitis Sentinel AI (Penalaran Lintas Domain):**\n\n" .
                      "Ketika kita menganalisis interaksi antara **{$nameA}** ({$domainA}) di dalam konteks **{$nameB}** ({$domainB}), skenario ini belum pernah memiliki rekaman peristiwa langsung di Bumi, namun dapat kita konstruksikan melalui hukum-hukum fundamental kedua sistem:";

        // Kesimpulan & Status Kelayakan
        $statusConclusion = !empty($bottlenecks)
            ? "Secara alami dan tanpa rekayasa isolasi buatan, sistem **{$nameA}** akan menghadapi kegagalan fungsi atau hambatan eksistensial yang parah di lingkungan **{$nameB}**."
            : "Secara teoritis, sistem **{$nameA}** memiliki potensi untuk beradaptasi atau bekerja dengan efisien di lingkungan **{$nameB}**.";

        $sections[] = "### 📌 Kesimpulan Sintesis:\n{$statusConclusion}\n\n" .
                      "📊 **Indeks Kelayakan Realisasi ($\Phi$):**\n" .
                      "- **Kondisi Alami (Tanpa Rekayasa):** **{$feasibility['natural_pct']}%** ({$feasibility['natural_label']})\n" .
                      "- **Dengan Rekayasa Adaptasi:** **{$feasibility['engineered_pct']}%** ({$feasibility['engineered_label']})";

        if (!empty($synergies)) {
            $sections[] = "### 🟢 Peluang & Sinergi Positif:\n" . implode("\n", array_unique($synergies));
        }

        if (!empty($bottlenecks)) {
            $sections[] = "### 🔴 Hambatan & Titik Kritis Alamiah:\n" . implode("\n", array_unique($bottlenecks));
        }

        if (!empty($adaptations) && !empty($bottlenecks)) {
            $sections[] = "### 💡 Solusi & Rekayasa Adaptasi Hipotetis:\n" .
                          "Agar proses ini dapat berjalan secara nyata, diperlukan jembatan rekayasa berikut:\n" .
                          implode("\n", array_unique($adaptations));
        } elseif (empty($bottlenecks) && !empty($synergies)) {
            $sections[] = "### 💡 Implikasi & Penerapan Nyata:\n" .
                          "Integrasi kedua bidang ini membuka peluang terobosan komputasi bioinformatika tingkat lanjut tanpa hambatan biofisik yang berarti.";
        }

        if (!empty($assumptions) && $isDeepAnalysis) {
            $sections[] = "🛡️ **Catatan Epistemik & Batas Asumsi:**\n" .
                          implode("\n", array_map(fn($a) => "- {$a}", array_slice($assumptions, 0, 3)));
        } else {
            $sections[] = "🛡️ *Catatan Epistemik:* Penalaran ini dirajut berdasarkan perpaduan matematis prinsip {$domainA} dan {$domainB} yang tersimpan di dalam jaringan pengetahuan Sentinel AI.";
        }

        $reply = implode("\n\n", $sections);

        return [
            'reply'            => $reply,
            'thought'          => $thought,
            'confidence'       => round($feasibility['engineered_score'], 2),
            'matched_concepts' => "{$nameA} + {$nameB}",
            'feasibility'      => $feasibility
        ];
    }

    /**
     * Evaluasi Interaksi Dimensi & Polaritas Terstruktur (Dimensional & Polarity Evaluation)
     * Mengeliminasi bug kontradiksi semantik (Opposites-as-Synergy)
     */
    protected static function evaluateDimensionalInteractions(array $cA, array $cB): array
    {
        $nameA = $cA['name'];
        $nameB = $cB['name'];

        $synergies = [];
        $bottlenecks = [];
        $adaptations = [];

        // 1. Ekstrak Sinergi Eksplisit yang didefinisikan pada ontologi
        if (!empty($cA['synergies'])) {
            foreach ($cA['synergies'] as $syn) {
                $synergies[] = "- **Sinergi Analitis & Fungsional:** {$syn}.";
            }
        }
        if (!empty($cB['synergies'])) {
            foreach ($cB['synergies'] as $syn) {
                $synergies[] = "- **Sinergi Analitis & Fungsional:** {$syn}.";
            }
        }

        // 2. Evaluasi Kebutuhan A vs Properti B
        self::matchRequirementsAgainstProperties($cA, $cB, $nameA, $nameB, $synergies, $bottlenecks);

        // 3. Evaluasi Kebutuhan B vs Properti A (Simetri Dua Arah)
        self::matchRequirementsAgainstProperties($cB, $cA, $nameB, $nameA, $synergies, $bottlenecks);

        // 4. Evaluasi Kerentanan (Vulnerabilities) A vs Properti B
        self::matchVulnerabilitiesAgainstProperties($cA, $cB, $nameA, $nameB, $bottlenecks);

        // 5. Evaluasi Kerentanan B vs Properti A
        self::matchVulnerabilitiesAgainstProperties($cB, $cA, $nameB, $nameA, $bottlenecks);

        // 6. Kumpulkan Rekayasa Solusi / Adaptasi
        if (!empty($bottlenecks)) {
            if (!empty($cA['adaptations'])) {
                foreach ($cA['adaptations'] as $ad) {
                    $adaptations[] = "- Memerlukan sistem perlindungan berupa **{$ad}** untuk menetralkan lingkungan ekstrem.";
                }
            }
            if (!empty($cB['adaptations'])) {
                foreach ($cB['adaptations'] as $ad) {
                    $adaptations[] = "- Memerlukan sistem perlindungan berupa **{$ad}** untuk menetralkan lingkungan ekstrem.";
                }
            }
        }

        return [
            'synergies'   => array_values(array_unique($synergies)),
            'bottlenecks' => array_values(array_unique($bottlenecks)),
            'adaptations' => array_values(array_unique($adaptations))
        ];
    }

    /**
     * Pencocokan Kebutuhan terhadap Properti dengan Pengecekan Polaritas Nilai
     */
    protected static function matchRequirementsAgainstProperties(array $src, array $tgt, string $srcName, string $tgtName, array &$synergies, array &$bottlenecks): void
    {
        $reqs = $src['requirements'] ?? [];
        $props = $tgt['properties'] ?? [];

        foreach ($reqs as $req) {
            $reqDesc = is_array($req) ? ($req['description'] ?? '') : (string)$req;
            $reqDim = is_array($req) ? ($req['dimension'] ?? null) : null;
            $reqPol = is_array($req) ? ($req['polarity'] ?? null) : null;

            foreach ($props as $prop) {
                $propDesc = is_array($prop) ? ($prop['description'] ?? '') : (string)$prop;
                $propDim = is_array($prop) ? ($prop['dimension'] ?? null) : null;
                $propPol = is_array($prop) ? ($prop['polarity'] ?? null) : null;

                // Jika memiliki dimensi terstruktur yang sama
                if ($reqDim !== null && $propDim !== null) {
                    if ($reqDim === $propDim) {
                        // Cek apakah polaritas selaras (Sinergi) atau bertolak belakang (Hambatan)
                        if (self::isPolarityCompatible($reqPol, $propPol)) {
                            $synergies[] = "- **Sinergi Sumber Daya:** Kondisi pada {$tgtName} menyediakan *{$propDesc}* yang berkesesuaian dengan kebutuhan *{$reqDesc}* dari {$srcName}.";
                        } else {
                            $bottlenecks[] = "- **Faktor Pembatas Kritis:** Sifat *{$propDesc}* pada {$tgtName} bertolak belakang secara fisik dengan kebutuhan *{$reqDesc}* pada {$srcName}.";
                        }
                    }
                } else {
                    // Fallback afinitas semantik terkontrol (hanya jika dimensi tidak ada)
                    if (self::hasStrictSemanticAffinity($reqDesc, $propDesc)) {
                        if (self::isAntagonisticPhrase($reqDesc, $propDesc)) {
                            $bottlenecks[] = "- **Faktor Pembatas Kritis:** Sifat *{$propDesc}* pada {$tgtName} secara langsung memicu kerentanan pada {$srcName}.";
                        } else {
                            $synergies[] = "- **Sinergi Sumber Daya:** Kondisi pada {$tgtName} menyediakan *{$propDesc}* yang berkesesuaian dengan kebutuhan *{$reqDesc}* dari {$srcName}.";
                        }
                    }
                }
            }
        }
    }

    /**
     * Pencocokan Kerentanan terhadap Properti Lingkungan
     */
    protected static function matchVulnerabilitiesAgainstProperties(array $src, array $tgt, string $srcName, string $tgtName, array &$bottlenecks): void
    {
        $vulns = $src['vulnerabilities'] ?? [];
        $props = $tgt['properties'] ?? [];

        foreach ($vulns as $vuln) {
            $vulnDesc = is_array($vuln) ? ($vuln['description'] ?? '') : (string)$vuln;
            $vulnDim = is_array($vuln) ? ($vuln['dimension'] ?? null) : null;

            foreach ($props as $prop) {
                $propDesc = is_array($prop) ? ($prop['description'] ?? '') : (string)$prop;
                $propDim = is_array($prop) ? ($prop['dimension'] ?? null) : null;

                if ($vulnDim !== null && $propDim !== null) {
                    if ($vulnDim === $propDim) {
                        $bottlenecks[] = "- **Faktor Pembatas Kritis:** Sifat *{$propDesc}* pada {$tgtName} secara langsung memicu kerentanan *{$vulnDesc}* pada {$srcName}.";
                    }
                } else {
                    if (self::hasStrictSemanticAffinity($vulnDesc, $propDesc)) {
                        $bottlenecks[] = "- **Faktor Pembatas Kritis:** Sifat *{$propDesc}* pada {$tgtName} secara langsung memicu kerentanan *{$vulnDesc}* pada {$srcName}.";
                    }
                }
            }
        }
    }

    /**
     * Pengecekan Kompatibilitas Polaritas (Misal: co2_available vs co2_available = TRUE, warm vs cold = FALSE)
     */
    protected static function isPolarityCompatible(?string $reqPol, ?string $propPol): bool
    {
        if ($reqPol === null || $propPol === null) {
            return false;
        }

        if ($reqPol === $propPol) {
            return true;
        }

        // Pasangan kompatibel
        $compatible = [
            'co2_available' => ['co2_available'],
            'liquid_water'  => ['liquid_water'],
            'pattern_matching' => ['pattern_matching'],
            'stable_dc'     => ['stable_dc']
        ];

        return in_array($propPol, $compatible[$reqPol] ?? [], true);
    }

    /**
     * Deteksi Frasa Antagonistik / Bertolak Belakang (misal: "hangat" vs "dingin", "air cair" vs "ketiadaan air")
     */
    protected static function isAntagonisticPhrase(string $tA, string $tB): bool
    {
        $a = mb_strtolower($tA);
        $b = mb_strtolower($tB);

        // Suhu berlawanan
        if ((str_contains($a, 'hangat') && (str_contains($b, 'dingin') || str_contains($b, 'beku') || str_contains($b, '-60'))) ||
            (str_contains($b, 'hangat') && (str_contains($a, 'dingin') || str_contains($a, 'beku') || str_contains($a, '-60')))) {
            return true;
        }

        // Fase air berlawanan
        if ((str_contains($a, 'cair') && (str_contains($b, 'ketiadaan air') || str_contains($b, 'es padat') || str_contains($b, 'sublimasi'))) ||
            (str_contains($b, 'cair') && (str_contains($a, 'ketiadaan air') || str_contains($a, 'es padat') || str_contains($a, 'sublimasi')))) {
            return true;
        }

        // Tekanan berlawanan
        if ((str_contains($a, 'tekanan') && str_contains($b, 'tipis')) ||
            (str_contains($b, 'tekanan') && str_contains($a, 'tipis'))) {
            return true;
        }

        return false;
    }

    /**
     * Algoritma Perpaduan Multi-Konsep N-Way (N >= 3 Concepts Hypergraph Synthesis)
     */
    protected static function blendNWayConcepts(array $concepts, string $query): array
    {
        $names = array_map(fn($c) => $c['name'], $concepts);
        $nameList = implode(', ', array_slice($names, 0, -1)) . ' dan ' . end($names);

        $thought = "Menganalisis skenario komposit N-Way Hypergraph (" . count($concepts) . " domain pengetahuan: {$nameList}). Menghitung propagasi kendala simultan lintas sistem.";

        $allSynergies = [];
        $allBottlenecks = [];
        $allAdaptations = [];
        $allAssumptions = [];

        // Evaluasi pairwise untuk semua kombinasi simpul hypergraph
        $count = count($concepts);
        for ($i = 0; $i < $count; $i++) {
            $allAssumptions = array_merge($allAssumptions, $concepts[$i]['epistemic_assumptions'] ?? []);
            for ($j = $i + 1; $j < $count; $j++) {
                $eval = self::evaluateDimensionalInteractions($concepts[$i], $concepts[$j]);
                $allSynergies = array_merge($allSynergies, $eval['synergies']);
                $allBottlenecks = array_merge($allBottlenecks, $eval['bottlenecks']);
                $allAdaptations = array_merge($allAdaptations, $eval['adaptations']);
            }
        }

        $allSynergies = array_values(array_unique($allSynergies));
        $allBottlenecks = array_values(array_unique($allBottlenecks));
        $allAdaptations = array_values(array_unique($allAdaptations));
        $allAssumptions = array_values(array_unique($allAssumptions));

        // Kalkulasi Skor Kelayakan Multi-Domain
        $feasibility = self::calculateMultiFeasibilityScore($concepts, $allSynergies, $allBottlenecks, $allAdaptations);

        $sections = [];
        $sections[] = "🔬 **Sintesis Analitis Multi-Domain Hypergraph (Sentinel AI N-Way Reasoning):**\n\n" .
                      "Ketika kita memadukan sekaligus " . count($concepts) . " domain sistemik (**{$nameList}**), interaksi fisika, biologi, dan komputasi membentuk jaringan kendala terintegrasi:";

        $sections[] = "### 📌 Evaluasi Terpadu Multi-Sistem:\n" .
                      (!empty($allBottlenecks)
                          ? "Sistem gabungan menghadapi akumulasi hambatan multifaktorial ekstrem di mana kegagalan pada satu domain memicu efek domino pada subsistem lainnya."
                          : "Sistem gabungan menunjukkan tingkat kompatibilitas simbiotik tinggi antar-domain.") . "\n\n" .
                      "📊 **Indeks Kelayakan Realisasi Terpadu ($\Phi_{\text{multi}}$):**\n" .
                      "- **Kondisi Alami (Kumulatif):** **{$feasibility['natural_pct']}%** ({$feasibility['natural_label']})\n" .
                      "- **Dengan Rekayasa Multilateral:** **{$feasibility['engineered_pct']}%** ({$feasibility['engineered_label']})";

        if (!empty($allSynergies)) {
            $sections[] = "### 🟢 Sinergi Antar-Sistem Terkonfirmasi:\n" . implode("\n", array_slice($allSynergies, 0, 5));
        }

        if (!empty($allBottlenecks)) {
            $sections[] = "### 🔴 Hambatan & Titik Kritis Kumulatif:\n" . implode("\n", array_slice($allBottlenecks, 0, 6));
        }

        if (!empty($allAdaptations)) {
            $sections[] = "### 💡 Arsitektur Rekayasa Multi-Lapis:\n" . implode("\n", array_slice($allAdaptations, 0, 5));
        }

        if (!empty($allAssumptions)) {
            $sections[] = "🛡️ **Batas Asumsi Epistemik:**\n" . implode("\n", array_map(fn($a) => "- {$a}", array_slice($allAssumptions, 0, 3)));
        }

        $reply = implode("\n\n", $sections);

        return [
            'reply'            => $reply,
            'thought'          => $thought,
            'confidence'       => round($feasibility['engineered_score'], 2),
            'matched_concepts' => $nameList,
            'feasibility'      => $feasibility
        ];
    }

    /**
     * Kalkulasi Indeks Kelayakan Kuantitatif (Quantitative Feasibility Index Phi)
     */
    protected static function calculateFeasibilityScore(array $cA, array $cB, array $synergies, array $bottlenecks, array $adaptations): array
    {
        $numReqs = max(1, count($cA['requirements'] ?? []) + count($cB['requirements'] ?? []));
        $numSyn = count($synergies);
        $numBot = count($bottlenecks);
        $numAdp = count($adaptations);

        // Jika tidak ada hambatan kritis (misal AI x DNA)
        if ($numBot === 0) {
            $naturalScore = 0.92;
            $engineeredScore = 0.98;
        } else {
            // Skor alami terdegradasi secara proporsional terhadap rasio hambatan
            $naturalScore = max(0.02, min(0.35, round(($numSyn * 0.15) / ($numBot * 1.5 + 1), 3)));
            // Rekayasa mitigasi mengembalikan kelayakan
            $mitigationFactor = min(1.0, ($numAdp * 0.35) / max(1, $numBot * 0.4));
            $engineeredScore = round(min(0.95, $naturalScore + (0.75 * $mitigationFactor)), 2);
        }

        $naturalPct = (int)round($naturalScore * 100);
        $engineeredPct = (int)round($engineeredScore * 100);

        return [
            'natural_score'    => $naturalScore,
            'natural_pct'      => $naturalPct,
            'natural_label'    => $naturalPct >= 70 ? 'Sangat Layak' : ($naturalPct >= 35 ? 'Moderately Challenging' : 'Kritis / Terancam Gagal Alami'),
            'engineered_score' => $engineeredScore,
            'engineered_pct'   => $engineeredPct,
            'engineered_label' => $engineeredPct >= 75 ? 'Sangat Layak dengan Rekayasa' : 'Memerlukan Riset Material Lebih Lanjut'
        ];
    }

    /**
     * Kalkulasi Indeks Kelayakan untuk Multi-Domain N-Way
     */
    protected static function calculateMultiFeasibilityScore(array $concepts, array $synergies, array $bottlenecks, array $adaptations): array
    {
        $numSyn = count($synergies);
        $numBot = count($bottlenecks);
        $numAdp = count($adaptations);

        if ($numBot === 0) {
            $naturalScore = 0.88;
            $engineeredScore = 0.96;
        } else {
            // Pada multi-domain, hambatan berlipat ganda
            $naturalScore = max(0.01, min(0.20, round(0.15 / ($numBot * 0.6 + 1), 3)));
            $mitigationFactor = min(1.0, ($numAdp * 0.28) / max(1, $numBot * 0.35));
            $engineeredScore = round(min(0.90, $naturalScore + (0.70 * $mitigationFactor)), 2);
        }

        $naturalPct = (int)round($naturalScore * 100);
        $engineeredPct = (int)round($engineeredScore * 100);

        return [
            'natural_score'    => $naturalScore,
            'natural_pct'      => $naturalPct,
            'natural_label'    => 'Kritis Alami (Kumulatif)',
            'engineered_score' => $engineeredScore,
            'engineered_pct'   => $engineeredPct,
            'engineered_label' => 'Dapat Dicapai via Rekayasa Multilateral'
        ];
    }

    /**
     * Ekstrapolasi kondisi batas hipotetis untuk 1 konsep utama
     */
    protected static function extrapolateHypotheticalBoundary(array $concept, string $query): array
    {
        $name = $concept['name'];
        $domain = $concept['domain'];

        $thought = "Menganalisis skenario hipotetis batas untuk konsep '{$name}' ({$domain}). Menghitung proyeksi logika sebab-akibat ketika variabel lingkungan diubah secara ekstrem.";

        $reqText = !empty($concept['requirements'])
            ? implode(', ', array_map(fn($r) => is_array($r) ? $r['description'] : $r, $concept['requirements']))
            : 'kondisi standar';

        $vulnText = !empty($concept['vulnerabilities'])
            ? implode('; ', array_map(fn($v) => is_array($v) ? $v['description'] : $v, $concept['vulnerabilities']))
            : 'parameter di luar ambang batas';

        $reply = "🧭 **Ekstrapolasi Hipotetis Sentinel AI:**\n\n" .
                 "Dalam menelaah skenario pengujian terhadap **{$name}** ({$domain}):\n\n" .
                 "- **Hukum Dasar:** Sistem ini secara mutlak mengandalkan: *{$reqText}*.\n" .
                 "- **Analisis Konsekuensi:** Jika salah satu parameter tersebut ditiadakan atau dipaksa ke titik ekstrem, sistem akan mengalami *degradasi fungsional* karena: *{$vulnText}*.\n" .
                 "- **Proyeksi:** Dalam ketiadaan faktor pendukung utama, sistem tidak dapat bertahan dalam bentuk konvensionalnya kecuali mengalami mutasi struktural atau disokong oleh intervensi teknologi kompensasi eksternal.\n\n" .
                 "💡 *Hipotesis ini dirumuskan melalui penelusuran hubungan sebab-akibat logis dari prinsip dasar {$domain}.*";

        return [
            'reply'            => $reply,
            'thought'          => $thought,
            'confidence'       => 0.88,
            'matched_concepts' => $name
        ];
    }

    /**
     * Mengukur afinitas semantik ketat antar frasa bebas
     */
    protected static function hasStrictSemanticAffinity(string $textA, string $textB): bool
    {
        $tA = mb_strtolower($textA);
        $tB = mb_strtolower($textB);

        $affinityPairs = [
            ['co2', 'karbon dioksida', 'fiksasi karbon'],
            ['air', 'h2o', 'pelarut', 'donor elektron'],
            ['suhu', 'panas', 'dingin', 'beku', 'termal', 'enzim'],
            ['radiasi', 'uv', 'ultraviolet', 'kosmik', 'ion'],
            ['gravitasi', 'mikrogravitasi', 'konveksi', 'apung'],
            ['dna', 'genetika', 'genom', 'sekuens', 'basa'],
            ['cpu', 'komputer', 'prosesor', 'kalor', 'tdp', 'throttling']
        ];

        foreach ($affinityPairs as $pair) {
            $matchA = false;
            $matchB = false;
            foreach ($pair as $term) {
                if (str_contains($tA, $term)) $matchA = true;
                if (str_contains($tB, $term)) $matchB = true;
            }
            if ($matchA && $matchB) {
                return true;
            }
        }

        return false;
    }

    /**
     * Memuat Ontologi dari data/ontology.json (dengan fallback in-memory)
     */
    public static function getOntology(): array
    {
        if (self::$cachedOntology !== null) {
            return self::$cachedOntology;
        }

        $jsonPath = __DIR__ . '/../../data/ontology.json';
        if (file_exists($jsonPath)) {
            $raw = file_get_contents($jsonPath);
            $decoded = json_decode($raw, true);
            if (is_array($decoded) && !empty($decoded)) {
                self::$cachedOntology = $decoded;
                return self::$cachedOntology;
            }
        }

        return [];
    }

    /**
     * Pendaftaran Konsep Baru ke Ontologi secara Dinamis (Self-Expanding Ontology)
     */
    public static function registerConcept(string $key, array $concept): bool
    {
        $ontology = self::getOntology();
        $ontology[$key] = $concept;
        self::$cachedOntology = $ontology;

        $jsonPath = __DIR__ . '/../../data/ontology.json';
        $encoded = json_encode($ontology, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        return file_put_contents($jsonPath, $encoded) !== false;
    }
}
