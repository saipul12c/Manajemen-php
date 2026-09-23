<?php
session_start();
require_once __DIR__ . "/../../config/database.php";

requireRole(['administrator']);

// Query Audit Logs
$stmt = $pdo->query("
    SELECT l.*, u.name as user_name, u.role as user_role, u.email as user_email
    FROM audit_logs l
    LEFT JOIN users u ON l.user_id = u.id
    ORDER BY l.created_at DESC
    LIMIT 100
");
$logs = $stmt->fetchAll();

$page_title = "Audit Trail & Log Aktivitas";
require_once __DIR__ . "/../includes/header.php";
?>

<div class="mb-8 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
    <div>
        <div class="flex items-center gap-3">
            <span class="flex h-10 w-10 items-center justify-center rounded-2xl bg-rose-500/20 text-rose-400 text-lg border border-rose-500/30 shadow-lg shadow-rose-500/10">
                <i class="fa-solid fa-shield-halved"></i>
            </span>
            <div>
                <h1 class="text-2xl font-bold text-white tracking-tight">Audit Trail & Log Aktivitas Sistem</h1>
                <p class="text-sm text-slate-400">Rekam jejak aktivitas penting pengguna untuk audit keamanan dan transparansi</p>
            </div>
        </div>
    </div>

    <div class="flex items-center gap-2">
        <a href="settings.php" class="inline-flex items-center rounded-xl border border-white/10 bg-white/5 hover:bg-white/10 px-4 py-2 text-xs font-semibold text-slate-300 transition">
            <i class="fa-solid fa-gear mr-1.5"></i>Pengaturan Sekolah
        </a>
    </div>
</div>

<div class="overflow-hidden rounded-3xl border border-white/10 bg-slate-900/60 shadow-xl backdrop-blur">
    <div class="overflow-x-auto">
        <table class="w-full text-left text-xs text-slate-300">
            <thead class="border-b border-white/10 bg-white/5 text-[10px] uppercase font-bold text-slate-400">
                <tr>
                    <th class="px-6 py-3.5">Waktu</th>
                    <th class="px-6 py-3.5">Pengguna</th>
                    <th class="px-4 py-3.5">Role</th>
                    <th class="px-4 py-3.5">Tindakan (Action)</th>
                    <th class="px-6 py-3.5">Rincian Aktivitas</th>
                    <th class="px-4 py-3.5 font-mono text-right">Alamat IP</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-white/5 font-sans">
                <?php if (empty($logs)): ?>
                    <tr>
                        <td colspan="6" class="px-6 py-12 text-center text-slate-400">
                            Belum ada riwayat aktivitas tercatat dalam sistem.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($logs as $l): ?>
                        <tr class="hover:bg-white/[0.02] transition">
                            <td class="px-6 py-3 text-slate-400 font-mono">
                                <?= date('d/m/Y H:i:s', strtotime($l['created_at'])) ?>
                            </td>
                            <td class="px-6 py-3">
                                <span class="font-bold text-white"><?= htmlspecialchars($l['user_name'] ?? 'Sistem Otomatis') ?></span>
                                <?php if (!empty($l['user_email'])): ?>
                                    <span class="block text-[10px] text-slate-500 font-mono"><?= htmlspecialchars($l['user_email']) ?></span>
                                <?php endif; ?>
                            </td>
                            <td class="px-4 py-3">
                                <?php if (!empty($l['user_role'])): ?>
                                    <span class="inline-block px-2 py-0.5 rounded text-[10px] uppercase font-bold border <?= getRoleBadge($l['user_role']) ?>">
                                        <?= htmlspecialchars(getRoleLabel($l['user_role'])) ?>
                                    </span>
                                <?php else: ?>
                                    <span class="text-slate-500 text-[10px]">-</span>
                                <?php endif; ?>
                            </td>
                            <td class="px-4 py-3">
                                <span class="inline-block px-2 py-0.5 rounded text-[10px] font-mono font-bold bg-white/10 text-slate-200">
                                    <?= htmlspecialchars($l['action']) ?>
                                </span>
                            </td>
                            <td class="px-6 py-3 text-slate-200">
                                <?= htmlspecialchars($l['details'] ?: '-') ?>
                            </td>
                            <td class="px-4 py-3 text-right font-mono text-slate-400">
                                <?= htmlspecialchars($l['ip_address'] ?? '127.0.0.1') ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require_once __DIR__ . "/../includes/footer.php"; ?>
