<?php
session_start();

if (isset($_SESSION['user_id'])) {
    header("Location: dashboard/index.php");
    exit;
}
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Manajemen-PHP - Sistem Manajemen Pengguna 5 Role</title>

    <script src="https://cdn.jsdelivr.net/npm/@tailwindcss/browser@4"></script>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@fortawesome/fontawesome-free@6.5.1/css/all.min.css">
</head>

<body class="min-h-screen bg-slate-950 text-white">

    <nav class="border-b border-white/10 bg-slate-950/80 backdrop-blur">
        <div class="mx-auto flex max-w-6xl items-center justify-between px-6 py-5">

            <a href="index.php" class="text-xl font-bold flex items-center gap-2">
                <span class="flex h-8 w-8 items-center justify-center rounded-lg bg-blue-600 text-white shadow-md shadow-blue-500/30 text-sm">
                    <i class="fa-solid fa-bolt"></i>
                </span>
                <span>Manajemen<span class="text-blue-500">-PHP</span></span>
            </a>

            <div class="flex items-center gap-3">
                <a href="ppdb.php"
                   class="inline-flex items-center gap-1.5 rounded-xl border border-emerald-500/30 bg-emerald-500/10 px-3.5 py-1.5 text-xs sm:text-sm font-semibold text-emerald-300 hover:bg-emerald-500/20 transition">
                    <i class="fa-solid fa-graduation-cap"></i>
                    <span>PPDB 2026/2027</span>
                </a>

                <a href="auth/login.php"
                   class="rounded-xl px-4 py-2 text-sm font-medium text-slate-300 hover:bg-white/10 transition">
                    Login
                </a>

                <a href="auth/register.php"
                   class="rounded-xl bg-blue-600 px-4 py-2 text-sm font-semibold hover:bg-blue-500 transition">
                    Daftar
                </a>
            </div>

        </div>
    </nav>

    <main class="flex min-h-[calc(100vh-81px)] items-center">

        <div class="mx-auto grid max-w-6xl items-center gap-12 px-6 py-16 md:grid-cols-2">

            <div>

                <div class="mb-6 flex flex-wrap gap-2">
                    <span class="inline-flex items-center gap-1.5 rounded-full border border-blue-400/20 bg-blue-400/10 px-4 py-1.5 text-xs sm:text-sm text-blue-300 font-medium">
                        <i class="fa-solid fa-school-flag"></i>
                        <span>Sistem Manajemen Sekolah Terpadu</span>
                    </span>
                    <a href="ppdb.php" class="inline-flex items-center gap-1.5 rounded-full border border-emerald-400/30 bg-emerald-400/10 px-4 py-1.5 text-xs sm:text-sm text-emerald-300 font-semibold hover:bg-emerald-400/20 transition">
                        <span class="relative flex h-2 w-2">
                            <span class="animate-ping absolute inline-flex h-full w-full rounded-full bg-emerald-400 opacity-75"></span>
                            <span class="relative inline-flex rounded-full h-2 w-2 bg-emerald-500"></span>
                        </span>
                        PPDB Online Dibuka!
                    </a>
                </div>

                <h1 class="text-4xl font-extrabold leading-tight md:text-6xl">
                    Selamat datang di
                    <span class="text-blue-500">Manajemen-PHP</span>
                </h1>

                <p class="mt-6 max-w-xl text-lg leading-8 text-slate-400">
                    Website autentikasi dan manajemen sekolah modern berbasis hak akses: 
                    <strong class="text-white">Administrator, Staf, Guru, Orang Tua,</strong> dan <strong class="text-white">Siswa</strong>. Dilengkapi PPDB Online, Perpustakaan Digital, E-Learning, dan Keuangan.
                </p>

                <div class="mt-8 flex flex-wrap gap-4">

                    <a href="ppdb.php"
                       class="inline-flex items-center gap-2 rounded-xl border border-emerald-500/40 bg-emerald-600/20 px-6 py-3 font-semibold text-emerald-300 transition hover:bg-emerald-600/30 shadow-lg shadow-emerald-500/10">
                        <i class="fa-solid fa-graduation-cap"></i>
                        <span>Daftar Siswa Baru (PPDB) →</span>
                    </a>

                    <a href="auth/login.php"
                       class="rounded-xl border border-white/10 bg-white/5 px-6 py-3 font-semibold transition hover:bg-white/10">
                        Login Portal
                    </a>

                </div>

            </div>

            <div class="relative">

                <div class="absolute -inset-4 rounded-3xl bg-blue-600/20 blur-3xl"></div>

                <div class="relative rounded-3xl border border-white/10 bg-white/5 p-8 shadow-2xl backdrop-blur">

                    <div class="mb-8 flex items-center justify-between">

                        <div>
                            <p class="text-sm text-slate-400">
                                Dashboard Portal
                            </p>

                            <h2 class="mt-1 text-2xl font-bold text-white">
                                Selamat Datang
                            </h2>
                        </div>

                        <div class="flex h-12 w-12 items-center justify-center rounded-2xl bg-blue-600 text-white shadow-lg shadow-blue-500/30">
                            <i class="fa-solid fa-circle-user text-2xl"></i>
                        </div>

                    </div>

                    <div class="space-y-4">

                        <div class="rounded-2xl bg-slate-900 p-5">
                            <p class="text-sm text-slate-400">
                                Status Sistem
                            </p>

                            <p class="mt-1 font-semibold text-emerald-400 flex items-center gap-2">
                                <i class="fa-solid fa-circle-check"></i>
                                <span>Operasional & Aktif</span>
                            </p>
                        </div>

                        <div class="rounded-2xl bg-slate-900 p-5">
                            <p class="text-sm text-slate-400">
                                Teknologi
                            </p>

                            <p class="mt-1 font-semibold flex items-center gap-2">
                                <i class="fa-solid fa-server text-blue-400"></i>
                                <span>PHP 8 + MySQL PDO</span>
                            </p>
                        </div>

                    </div>

                </div>

            </div>

        </div>

    </main>

</body>
</html>