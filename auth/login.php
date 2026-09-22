<?php

session_start();

if (isset($_SESSION['user_id'])) {
    header("Location: ../dashboard/index.php");
    exit;
}

require_once __DIR__ . "/../config/database.php";

$message = "";
$message_type = "";

if (isset($_GET["registered"])) {
    $message = "Pendaftaran berhasil. Silakan login.";
    $message_type = "success";
}

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    $email = trim($_POST["email"] ?? "");
    $password = $_POST["password"] ?? "";

    $stmt = $pdo->prepare(
        "SELECT id, name, email, password, role
         FROM users
         WHERE email = ?"
    );

    $stmt->execute([$email]);

    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($user && password_verify($password, $user["password"])) {

        session_regenerate_id(true);

        $_SESSION["user_id"] = $user["id"];
        $_SESSION["user_name"] = $user["name"];
        $_SESSION["user_email"] = $user["email"];
        $_SESSION["user_role"] = $user["role"] ?? "siswa";

        header("Location: ../dashboard/index.php");
        exit;

    } else {

        $message = "Email atau password salah.";
        $message_type = "error";

    }

}

?>

<!DOCTYPE html>
<html lang="id">

<head>

    <meta charset="UTF-8">

    <meta name="viewport"
          content="width=device-width, initial-scale=1.0">

    <title>Login</title>

    <script src="https://cdn.jsdelivr.net/npm/@tailwindcss/browser@4"></script>

</head>

<body class="min-h-screen bg-slate-950 text-white">

<div class="flex min-h-screen items-center justify-center px-6">

    <div class="w-full max-w-md">

        <div class="mb-8 text-center">

            <a href="../index.php"
               class="text-2xl font-bold">
                Manajemen-php
            </a>

            <h1 class="mt-6 text-3xl font-bold">
                Selamat Datang 👋
            </h1>

            <p class="mt-2 text-slate-400">
                Silakan login ke akun Anda
            </p>

        </div>

        <?php if ($message !== ""): ?>

            <div class="mb-5 rounded-xl border
                <?= $message_type === 'success'
                    ? 'border-green-500/20 bg-green-500/10 text-green-300'
                    : 'border-red-500/20 bg-red-500/10 text-red-300'
                ?>
                px-4 py-3 text-sm">

                <?= htmlspecialchars($message) ?>

            </div>

        <?php endif; ?>

        <div class="rounded-3xl border border-white/10 bg-white/5 p-8 shadow-2xl">

            <form method="POST" class="space-y-5">

                <div>

                    <label class="mb-2 block text-sm font-medium text-slate-300">
                        Email
                    </label>

                    <input
                        type="email"
                        name="email"
                        required
                        placeholder="nama@email.com"
                        class="w-full rounded-xl border border-white/10 bg-slate-900 px-4 py-3 outline-none transition focus:border-blue-500"
                    >

                </div>

                <div>

                    <div class="mb-2 flex justify-between">

                        <label class="block text-sm font-medium text-slate-300">
                            Password
                        </label>

                    </div>

                    <input
                        type="password"
                        name="password"
                        required
                        placeholder="Masukkan password"
                        class="w-full rounded-xl border border-white/10 bg-slate-900 px-4 py-3 outline-none transition focus:border-blue-500"
                    >

                </div>

                <button
                    type="submit"
                    class="w-full rounded-xl bg-blue-600 py-3 font-semibold transition hover:bg-blue-500">
                    Login
                </button>

            </form>

            <div class="my-6 flex items-center gap-4">

                <div class="h-px flex-1 bg-white/10"></div>

                <span class="text-xs text-slate-500">
                    ATAU
                </span>

                <div class="h-px flex-1 bg-white/10"></div>

            </div>

            <p class="text-center text-sm text-slate-400">

                Belum punya akun?

                <a href="register.php"
                   class="font-semibold text-blue-400 hover:text-blue-300">
                    Daftar sekarang
                </a>

            </p>

            <div class="mt-6 rounded-2xl border border-white/10 bg-slate-900/70 p-4 text-xs">
                <p class="mb-2 font-semibold text-slate-300">
                    💡 Akun Demo Siap Pakai (Password: <span class="font-mono text-blue-400">password</span>):
                </p>
                <div class="grid grid-cols-1 gap-1 text-slate-400">
                    <div>• <span class="text-rose-400 font-medium">Administrator:</span> <code class="text-slate-200">admin@sekolah.id</code></div>
                    <div>• <span class="text-amber-400 font-medium">Staf:</span> <code class="text-slate-200">staf@sekolah.id</code></div>
                    <div>• <span class="text-emerald-400 font-medium">Guru:</span> <code class="text-slate-200">guru@sekolah.id</code></div>
                    <div>• <span class="text-purple-400 font-medium">Orang Tua:</span> <code class="text-slate-200">orangtua@sekolah.id</code></div>
                    <div>• <span class="text-blue-400 font-medium">Siswa:</span> <code class="text-slate-200">siswa@sekolah.id</code></div>
                </div>
            </div>

        </div>

    </div>

</div>

</body>
</html>