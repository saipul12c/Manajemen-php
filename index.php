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
</head>

<body class="min-h-screen bg-slate-950 text-white">

    <nav class="border-b border-white/10 bg-slate-950/80 backdrop-blur">
        <div class="mx-auto flex max-w-6xl items-center justify-between px-6 py-5">

            <a href="index.php" class="text-xl font-bold">
                Manajemen-php
            </a>

            <div class="flex items-center gap-3">
                <a href="auth/login.php"
                   class="rounded-xl px-4 py-2 text-sm font-medium text-slate-300 hover:bg-white/10">
                    Login
                </a>

                <a href="auth/register.php"
                   class="rounded-xl bg-blue-600 px-4 py-2 text-sm font-semibold hover:bg-blue-500">
                    Daftar
                </a>
            </div>

        </div>
    </nav>

    <main class="flex min-h-[calc(100vh-81px)] items-center">

        <div class="mx-auto grid max-w-6xl items-center gap-12 px-6 py-16 md:grid-cols-2">

            <div>

                <div class="mb-6 inline-flex rounded-full border border-blue-400/20 bg-blue-400/10 px-4 py-2 text-sm text-blue-300">
                    🚀 Sistem Manajemen Pengguna 5 Role
                </div>

                <h1 class="text-4xl font-extrabold leading-tight md:text-6xl">
                    Selamat datang di
                    <span class="text-blue-500">Manajemen-PHP</span>
                </h1>

                <p class="mt-6 max-w-xl text-lg leading-8 text-slate-400">
                    Website autentikasi dan manajemen pengguna berbasis hak akses: 
                    <strong class="text-white">Administrator, Staf, Guru, Orang Tua,</strong> dan <strong class="text-white">Siswa</strong>.
                </p>

                <div class="mt-8 flex flex-wrap gap-4">

                    <a href="auth/register.php"
                       class="rounded-xl bg-blue-600 px-6 py-3 font-semibold transition hover:bg-blue-500">
                        Mulai Sekarang →
                    </a>

                    <a href="auth/login.php"
                       class="rounded-xl border border-white/10 bg-white/5 px-6 py-3 font-semibold transition hover:bg-white/10">
                        Login
                    </a>

                </div>

            </div>

            <div class="relative">

                <div class="absolute -inset-4 rounded-3xl bg-blue-600/20 blur-3xl"></div>

                <div class="relative rounded-3xl border border-white/10 bg-white/5 p-8 shadow-2xl backdrop-blur">

                    <div class="mb-8 flex items-center justify-between">

                        <div>
                            <p class="text-sm text-slate-400">
                                Dashboard
                            </p>

                            <h2 class="mt-1 text-2xl font-bold">
                                Welcome 👋
                            </h2>
                        </div>

                        <div class="flex h-12 w-12 items-center justify-center rounded-2xl bg-blue-600">
                            👤
                        </div>

                    </div>

                    <div class="space-y-4">

                        <div class="rounded-2xl bg-slate-900 p-5">
                            <p class="text-sm text-slate-400">
                                Status akun
                            </p>

                            <p class="mt-1 font-semibold text-green-400">
                                ● Aktif
                            </p>
                        </div>

                        <div class="rounded-2xl bg-slate-900 p-5">
                            <p class="text-sm text-slate-400">
                                Sistem
                            </p>

                            <p class="mt-1 font-semibold">
                                PHP + MySQL
                            </p>
                        </div>

                    </div>

                </div>

            </div>

        </div>

    </main>

</body>
</html>