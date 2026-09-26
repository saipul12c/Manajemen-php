<?php
/**
 * Cetak Label Barcode & Nomor Punggung Buku (Spine Label)
 * Menghasilkan lembar cetak stiker barcode dan label klasifikasi DDC untuk ditempel di fisik buku perpustakaan.
 */
declare(strict_types=1);

session_start();
require_once __DIR__ . "/../../config/database.php";

requireLogin();
requireRole(['staf', 'administrator']);

$page_title = "Cetak Label & Barcode Buku";

// Ambil data identitas sekolah
$school_name = getSetting($pdo, 'school_name', 'PERPUSTAKAAN SEKOLAH');

// Filter Kategori & Pencarian
$category_filter = trim($_GET['category'] ?? '');
$search_q        = trim($_GET['q'] ?? '');
$preselected_id  = isset($_GET['book_id']) ? (int)$_GET['book_id'] : 0;

$sql_where = [];
$sql_params = [];

if (!empty($category_filter)) {
    $sql_where[] = "category = ?";
    $sql_params[] = $category_filter;
}
if (!empty($search_q)) {
    $sql_where[] = "(title LIKE ? OR code LIKE ? OR author LIKE ? OR isbn LIKE ?)";
    $q_like = "%$search_q%";
    $sql_params = array_merge($sql_params, [$q_like, $q_like, $q_like, $q_like]);
}

$where_clause = !empty($sql_where) ? "WHERE " . implode(" AND ", $sql_where) : "";

$books = $pdo->prepare("SELECT * FROM library_books $where_clause ORDER BY code ASC");
$books->execute($sql_params);
$book_list = $books->fetchAll();

// Ambil daftar kategori unik
$categories = $pdo->query("SELECT DISTINCT category FROM library_books ORDER BY category ASC")->fetchAll(PDO::FETCH_COLUMN);

include __DIR__ . "/../includes/header.php";
?>

<script src="https://cdn.jsdelivr.net/npm/jsbarcode@3.11.5/dist/JsBarcode.all.min.js"></script>

<style>
@media print {
    /* Sembunyikan semua elemen UI kecuali lembar label */
    body * {
        visibility: hidden;
    }
    #printArea, #printArea * {
        visibility: visible;
    }
    #printArea {
        position: absolute;
        left: 0;
        top: 0;
        width: 100%;
        margin: 0;
        padding: 10mm;
        background: white !important;
        color: black !important;
    }
    .no-print {
        display: none !important;
    }
}
</style>

<div class="space-y-6">

    <!-- Sub Navigasi Modul Perpustakaan (Disembunyikan saat print) -->
    <div class="no-print">
        <?php include __DIR__ . "/_nav.php"; ?>

        <!-- Header Section -->
        <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
            <div>
                <div class="inline-flex items-center gap-2 rounded-full border border-teal-500/20 bg-teal-500/10 px-3 py-1 text-xs font-semibold text-teal-400 mb-2">
                    <i class="fa-solid fa-tags"></i> Otomatisasi Koleksi Fisik
                </div>
                <h1 class="text-2xl sm:text-3xl font-extrabold text-white">Cetak Label Barcode & Spine Buku</h1>
                <p class="text-sm text-slate-400 mt-1">Cetak stiker barcode inventaris dan nomor panggil punggung buku (DDC) format kertas stiker.</p>
            </div>

            <div class="flex items-center gap-3">
                <button type="button" onclick="window.print()" 
                        class="rounded-xl bg-blue-600 hover:bg-blue-500 px-5 py-2.5 text-xs sm:text-sm font-bold text-white shadow-lg shadow-blue-600/25 transition flex items-center gap-2 cursor-pointer">
                    <i class="fa-solid fa-print"></i> Cetak Label Sekarang (Print)
                </button>
            </div>
        </div>

        <!-- Toolbar Pengaturan & Filter -->
        <div class="rounded-3xl border border-white/10 bg-slate-900/60 p-5 backdrop-blur-xl shadow-xl space-y-4">
            <div class="flex flex-col lg:flex-row lg:items-center justify-between gap-4">
                
                <!-- Format Label & Opsi Tampilan -->
                <div class="flex flex-wrap items-center gap-4 text-xs">
                    <div>
                        <span class="text-slate-400 block mb-1 font-semibold">Format Tampilan Label:</span>
                        <div class="flex items-center gap-1.5 bg-slate-950 p-1 rounded-xl border border-white/10">
                            <button type="button" onclick="setLabelMode('combo')" id="modeComboBtn"
                                    class="px-3 py-1 rounded-lg font-bold text-white bg-teal-600 transition">
                                Komplit (Barcode + Punggung)
                            </button>
                            <button type="button" onclick="setLabelMode('barcode')" id="modeBarcodeBtn"
                                    class="px-3 py-1 rounded-lg text-slate-400 hover:text-white transition">
                                Hanya Barcode
                            </button>
                            <button type="button" onclick="setLabelMode('spine')" id="modeSpineBtn"
                                    class="px-3 py-1 rounded-lg text-slate-400 hover:text-white transition">
                                Hanya Punggung (Spine)
                            </button>
                        </div>
                    </div>

                    <div>
                        <span class="text-slate-400 block mb-1 font-semibold">Duplikasi Per Buku:</span>
                        <select id="duplicateCount" onchange="generateLabels()"
                                class="rounded-xl border border-white/10 bg-slate-950 px-3 py-1.5 text-xs text-white focus:border-teal-500 focus:outline-none">
                            <option value="1">1 Lembar Label</option>
                            <option value="2">2 Lembar Label</option>
                            <option value="3">3 Lembar Label</option>
                        </select>
                    </div>
                </div>

                <!-- Filter Kategori & Pencarian Buku -->
                <form method="GET" action="print_labels.php" class="flex flex-wrap items-center gap-2">
                    <select name="category" onchange="this.form.submit()"
                            class="rounded-xl border border-white/10 bg-slate-950 px-3 py-1.5 text-xs text-white focus:border-teal-500 focus:outline-none">
                        <option value="">-- Semua Kategori --</option>
                        <?php foreach ($categories as $cat): ?>
                            <option value="<?= htmlspecialchars($cat) ?>" <?= ($category_filter === $cat) ? 'selected' : '' ?>>
                                <?= htmlspecialchars($cat) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>

                    <input type="text" name="q" value="<?= htmlspecialchars($search_q) ?>" placeholder="Cari judul / kode..."
                           class="rounded-xl border border-white/10 bg-slate-950 px-3 py-1.5 text-xs text-white focus:border-teal-500 focus:outline-none w-40">
                    <button type="submit" class="rounded-xl bg-teal-600 hover:bg-teal-500 px-3 py-1.5 text-xs font-bold text-white transition">
                        <i class="fa-solid fa-magnifying-glass"></i>
                    </button>
                </form>

            </div>

            <!-- Kontrol Pemilihan Buku -->
            <div class="pt-3 border-t border-white/5 flex flex-wrap items-center justify-between gap-3 text-xs">
                <div class="flex items-center gap-3 text-slate-300">
                    <label class="flex items-center gap-2 cursor-pointer font-bold">
                        <input type="checkbox" id="selectAllCheckbox" onchange="toggleSelectAll(this)" checked class="accent-teal-500 rounded">
                        <span>Pilih Semua Buku (<?= count($book_list) ?>)</span>
                    </label>
                    <span>•</span>
                    <span id="selectedCountText" class="text-teal-400 font-bold"><?= count($book_list) ?> Buku Terpilih</span>
                </div>
                <div class="text-[11px] text-slate-500">
                    Kertas yang disarankan: Stiker Label A4 (Ukuran Tom & Jerry No. 103 / No. 121)
                </div>
            </div>
        </div>
    </div>

    <!-- AREA CETAK LABEL (Print Area) -->
    <div id="printArea" class="rounded-3xl border border-white/10 bg-white p-6 text-slate-900 shadow-2xl">
        <div id="labelGrid" class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
            <!-- Label Item Cards di-generate via JavaScript -->
        </div>
    </div>

</div>

<!-- DATA DARI PHP UNTUK JS GENERATOR -->
<script>
const allBooksData = <?= json_encode($book_list) ?>;
const schoolName   = "<?= htmlspecialchars($school_name) ?>";
const preselectedId = <?= $preselected_id ?>;
let selectedBookIds = new Set(allBooksData.map(b => parseInt(b.id)));
let currentLabelMode = 'combo'; // 'combo', 'barcode', 'spine'

// Jika ada preselected_id dari URL, hanya centang buku itu saja
if (preselectedId > 0) {
    selectedBookIds.clear();
    selectedBookIds.add(preselectedId);
    document.getElementById('selectAllCheckbox').checked = false;
}

function setLabelMode(mode) {
    currentLabelMode = mode;
    ['combo', 'barcode', 'spine'].forEach(m => {
        const btn = document.getElementById(`mode${m.charAt(0).toUpperCase() + m.slice(1)}Btn`);
        if (m === mode) {
            btn.className = "px-3 py-1 rounded-lg font-bold text-white bg-teal-600 transition";
        } else {
            btn.className = "px-3 py-1 rounded-lg text-slate-400 hover:text-white transition";
        }
    });
    generateLabels();
}

function toggleSelectAll(master) {
    if (master.checked) {
        allBooksData.forEach(b => selectedBookIds.add(parseInt(b.id)));
    } else {
        selectedBookIds.clear();
    }
    updateSelectedCount();
    generateLabels();
}

function toggleBookSelection(bookId) {
    bookId = parseInt(bookId);
    if (selectedBookIds.has(bookId)) {
        selectedBookIds.delete(bookId);
    } else {
        selectedBookIds.add(bookId);
    }
    document.getElementById('selectAllCheckbox').checked = (selectedBookIds.size === allBooksData.length);
    updateSelectedCount();
    generateLabels();
}

function updateSelectedCount() {
    document.getElementById('selectedCountText').textContent = `${selectedBookIds.size} Buku Terpilih`;
}

// Generate kode klasifikasi 3 huruf pengarang + 1 huruf judul
function getSpineCallNumber(book) {
    // 3 huruf pertama nama pengarang
    const cleanAuthor = (book.author || 'P').trim().replace(/^(Dr\.|Prof\.|H\.|Drs\.)\s*/i, '');
    const authorCode = cleanAuthor.substring(0, 3).toUpperCase();
    
    // 1 huruf pertama judul (abaikan kata depan)
    const cleanTitle = (book.title || 'B').trim();
    const titleCode = cleanTitle.charAt(0).toLowerCase();

    // Nomor kategori DDC sederhana berdasarkan kategori
    let ddcCode = '000';
    const cat = (book.category || '').toLowerCase();
    if (cat.includes('sains') || cat.includes('fisika') || cat.includes('matematika')) ddcCode = '500';
    else if (cat.includes('teknologi') || cat.includes('komputer') || cat.includes('it')) ddcCode = '005';
    else if (cat.includes('novel') || cat.includes('sastra')) ddcCode = '813';
    else if (cat.includes('sejarah') || cat.includes('ips')) ddcCode = '959';
    else if (cat.includes('bahasa') || cat.includes('kamus')) ddcCode = '400';
    else if (cat.includes('agama') || cat.includes('islam')) ddcCode = '297';

    return { ddc: ddcCode, author: authorCode, title: titleCode, shelf: book.shelf_location || 'Rak A-1' };
}

function generateLabels() {
    const grid = document.getElementById('labelGrid');
    grid.innerHTML = '';

    const duplicates = parseInt(document.getElementById('duplicateCount').value) || 1;
    const selectedBooks = allBooksData.filter(b => selectedBookIds.has(parseInt(b.id)));

    if (selectedBooks.length === 0) {
        grid.innerHTML = `
            <div class="col-span-full py-16 text-center text-slate-400">
                <i class="fa-solid fa-tags text-4xl mb-2 text-slate-300"></i>
                <p class="font-bold text-sm text-slate-600">Tidak ada buku yang dipilih untuk dicetak.</p>
                <p class="text-xs text-slate-400 mt-1">Centang buku pada daftar di atas untuk memuat label.</p>
            </div>
        `;
        return;
    }

    selectedBooks.forEach(book => {
        for (let i = 0; i < duplicates; i++) {
            const callNum = getSpineCallNumber(book);
            const labelCard = document.createElement('div');
            labelCard.className = "border-2 border-dashed border-slate-300 rounded-xl p-3 bg-white text-slate-900 text-xs flex gap-2.5 items-stretch shadow-sm page-break-inside-avoid";

            let innerHtml = '';

            // 1. BAGIAN SPINE LABEL (Punggung Buku)
            if (currentLabelMode === 'combo' || currentLabelMode === 'spine') {
                innerHtml += `
                    <div class="w-20 shrink-0 border border-slate-400 rounded-lg p-2 text-center flex flex-col justify-between bg-slate-50 font-mono">
                        <div class="text-[9px] uppercase font-bold text-slate-500 border-b border-slate-300 pb-0.5">PERPUS</div>
                        <div class="py-1 space-y-0.5 font-bold">
                            <div class="text-sm tracking-widest text-slate-900">${callNum.ddc}</div>
                            <div class="text-xs text-slate-800">${callNum.author}</div>
                            <div class="text-xs text-slate-800">${callNum.title}</div>
                        </div>
                        <div class="text-[8px] border-t border-slate-300 pt-0.5 text-slate-600 truncate">${callNum.shelf}</div>
                    </div>
                `;
            }

            // 2. BAGIAN BARCODE UTAMA
            if (currentLabelMode === 'combo' || currentLabelMode === 'barcode') {
                const svgId = `barcode_${book.id}_${i}`;
                innerHtml += `
                    <div class="flex-1 flex flex-col justify-between border border-slate-400 rounded-lg p-2 bg-slate-50 text-center overflow-hidden">
                        <div class="text-[9px] font-bold text-slate-700 uppercase tracking-tight truncate border-b border-slate-200 pb-0.5">
                            ${schoolName}
                        </div>
                        <div class="text-[11px] font-bold text-slate-900 line-clamp-1 mt-1 leading-tight">
                            ${book.title}
                        </div>
                        <div class="py-1 flex items-center justify-center">
                            <svg id="${svgId}" class="w-full max-h-11"></svg>
                        </div>
                        <div class="flex items-center justify-between text-[9px] text-slate-500 border-t border-slate-200 pt-0.5 font-mono">
                            <span class="font-bold text-slate-800">${book.code}</span>
                            <span>${book.shelf_location || 'Rak A-1'}</span>
                        </div>
                    </div>
                `;
            }

            labelCard.innerHTML = innerHtml;
            grid.appendChild(labelCard);

            // Render SVG Barcode dengan JsBarcode
            if (currentLabelMode === 'combo' || currentLabelMode === 'barcode') {
                const svgElem = document.getElementById(`barcode_${book.id}_${i}`);
                if (svgElem) {
                    JsBarcode(svgElem, book.code, {
                        format: "CODE128",
                        height: 32,
                        width: 1.4,
                        fontSize: 0,
                        displayValue: false,
                        margin: 0
                    });
                }
            }
        }
    });
}

// Inisialisasi awal saat halaman termuat
document.addEventListener('DOMContentLoaded', function() {
    updateSelectedCount();
    generateLabels();
});
</script>

<?php include __DIR__ . "/../includes/footer.php"; ?>
