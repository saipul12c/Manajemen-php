<?php

session_start();

if (isset($_SESSION['user_id'])) {
    header("Location: ../dashboard/index.php");
    exit;
}

require_once __DIR__ . "/../config/database.php";

$message = "";
$message_type = "";

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    $name = trim($_POST["name"] ?? "");
    $email = trim($_POST["email"] ?? "");
    $password = $_POST["password"] ?? "";
    $confirm_password = $_POST["confirm_password"] ?? "";
    $role = trim($_POST["role"] ?? "siswa");

    // Validasi role valid
    if (!array_key_exists($role, ROLES)) {
        $role = "siswa";
    }

    if ($name === "" || $email === "" || $password === "") {

        $message = "Semua field wajib diisi.";
        $message_type = "error";

    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {

        $message = "Format email tidak valid.";
        $message_type = "error";

    } elseif (strlen($password) < 6) {

        $message = "Password minimal 6 karakter.";
        $message_type = "error";

    } elseif ($password !== $confirm_password) {

        $message = "Konfirmasi password tidak sama.";
        $message_type = "error";

    } else {

        $check = $pdo->prepare(
            "SELECT id FROM users WHERE email = ?"
        );

        $check->execute([$email]);

        if ($check->fetch()) {

            $message = "Email sudah terdaftar.";
            $message_type = "error";

        } else {

            $hashed_password = password_hash(
                $password,
                PASSWORD_DEFAULT
            );

            $stmt = $pdo->prepare(
                "INSERT INTO users (name, email, password, role)
                 VALUES (?, ?, ?, ?)"
            );

            $stmt->execute([
                $name,
                $email,
                $hashed_password,
                $role
            ]);

            header("Location: login.php?registered=1");
            exit;
        }
    }
}

?>

<!DOCTYPE html>
<html lang="id">

<head>

    <meta charset="UTF-8">

    <meta name="viewport"
          content="width=device-width, initial-scale=1.0">

    <title>Daftar Akun</title>

    <script src="https://cdn.jsdelivr.net/npm/@tailwindcss/browser@4"></script>

</head>

<body class="min-h-screen bg-slate-950 text-white">

<div class="flex min-h-screen items-center justify-center px-6 py-12">

    <div class="w-full max-w-md">

        <div class="mb-8 text-center">

            <a href="../index.php"
               class="text-2xl font-bold">
                Manajemen-php
            </a>

            <h1 class="mt-6 text-3xl font-bold">
                Buat Akun
            </h1>

            <p class="mt-2 text-slate-400">
                Daftar untuk membuat akun baru
            </p>

        </div>

        <?php if ($message !== ""): ?>

            <div class="mb-5 rounded-xl border border-red-500/20 bg-red-500/10 px-4 py-3 text-sm text-red-300">
                <?= htmlspecialchars($message) ?>
            </div>

        <?php endif; ?>

        <div class="rounded-3xl border border-white/10 bg-white/5 p-8 shadow-2xl">

            <form method="POST" class="space-y-5">

                <div>

                    <label class="mb-2 block text-sm font-medium text-slate-300">
                        Nama
                    </label>

                    <input
                        type="text"
                        name="name"
                        required
                        value="<?= htmlspecialchars($_POST['name'] ?? '') ?>"
                        placeholder="Nama lengkap"
                        class="w-full rounded-xl border border-white/10 bg-slate-900 px-4 py-3 outline-none transition focus:border-blue-500"
                    >

                </div>

                <div>

                    <label class="mb-2 block text-sm font-medium text-slate-300">
                        Email
                    </label>

                    <input
                        type="email"
                        name="email"
                        required
                        value="<?= htmlspecialchars($_POST['email'] ?? '') ?>"
                        placeholder="nama@email.com"
                        class="w-full rounded-xl border border-white/10 bg-slate-900 px-4 py-3 outline-none transition focus:border-blue-500"
                    >

                </div>

                <div>

                    <label class="mb-2 block text-sm font-medium text-slate-300">
                        Daftar Sebagai (Peran)
                    </label>

                    <select
                        name="role"
                        required
                        class="w-full rounded-xl border border-white/10 bg-slate-900 px-4 py-3 text-white outline-none transition focus:border-blue-500"
                    >
                        <?php foreach (ROLES as $key => $label): ?>
                            <option value="<?= $key ?>" <?= (($_POST['role'] ?? 'siswa') === $key) ? 'selected' : '' ?>>
                                <?= htmlspecialchars($label) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>

                </div>

                <div>

                    <label class="mb-2 block text-sm font-medium text-slate-300">
                        Password
                    </label>

                    <input
                        type="password"
                        name="password"
                        required
                        placeholder="Minimal 6 karakter"
                        class="w-full rounded-xl border border-white/10 bg-slate-900 px-4 py-3 outline-none transition focus:border-blue-500"
                    >

                </div>

                <div>

                    <label class="mb-2 block text-sm font-medium text-slate-300">
                        Konfirmasi Password
                    </label>

                    <input
                        type="password"
                        name="confirm_password"
                        required
                        placeholder="Ulangi password"
                        class="w-full rounded-xl border border-white/10 bg-slate-900 px-4 py-3 outline-none transition focus:border-blue-500"
                    >

                </div>

                <button
                    type="submit"
                    class="w-full rounded-xl bg-blue-600 py-3 font-semibold transition hover:bg-blue-500">
                    Daftar Sekarang
                </button>

            </form>

            <p class="mt-6 text-center text-sm text-slate-400">

                Sudah punya akun?

                <a href="login.php"
                   class="font-semibold text-blue-400 hover:text-blue-300">
                    Login
                </a>

            </p>

        </div>

        <p class="mt-6 text-center text-sm text-slate-600">
            © <?= date("Y") ?> Manajemen-php
        </p>

    </div>

</div>

</body>
</html>