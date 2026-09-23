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
} elseif (isset($_GET["auth"]) && $_GET["auth"] === "required") {
    $message = "Anda wajib login terlebih dahulu untuk mengakses halaman dashboard.";
    $message_type = "info";
}

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    // BUG-06 fix: Validate CSRF token
    if (!validateCsrfToken()) {
        $message = "Token keamanan tidak valid. Silakan coba lagi.";
        $message_type = "error";
    } else {

    // BUG-16 fix: Rate limiting — max 5 attempts per 15 minutes
    $now = time();
    if (!isset($_SESSION['login_attempts'])) {
        $_SESSION['login_attempts'] = [];
    }
    // Clean old attempts beyond 15 minutes
    $_SESSION['login_attempts'] = array_filter($_SESSION['login_attempts'], fn($t) => ($now - $t) < 900);

    if (count($_SESSION['login_attempts']) >= 5) {
        $message = "Terlalu banyak percobaan login. Silakan tunggu 15 menit.";
        $message_type = "error";
    } else {

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
        // BUG-15 fix: Reset CSRF token after login to prevent token reuse
        unset($_SESSION['csrf_token']);
        $_SESSION['login_attempts'] = [];

        $_SESSION["user_id"] = $user["id"];
        $_SESSION["user_name"] = $user["name"];
        $_SESSION["user_email"] = $user["email"];
        $_SESSION["user_role"] = $user["role"] ?? "siswa";

        header("Location: ../dashboard/index.php");
        exit;

    } else {

        $_SESSION['login_attempts'][] = $now;
        $message = "Email atau password salah.";
        $message_type = "error";

    }

    } // end rate limit check
    } // end CSRF check
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
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@fortawesome/fontawesome-free@6.5.1/css/all.min.css">

</head>

<body class="min-h-screen bg-slate-950 text-white">

<div class="flex min-h-screen items-center justify-center px-6">

    <div class="w-full max-w-md">

        <div class="mb-8 text-center">

            <a href="../index.php"
               class="text-2xl font-bold inline-flex items-center gap-2">
                <span class="flex h-8 w-8 items-center justify-center rounded-lg bg-blue-600 text-white text-sm shadow-md shadow-blue-500/30">
                    <i class="fa-solid fa-bolt"></i>
                </span>
                <span>Manajemen<span class="text-blue-500">-php</span></span>
            </a>

            <h1 class="mt-6 text-3xl font-bold">
                Selamat Datang
            </h1>

            <p class="mt-2 text-slate-400">
                Silakan login ke akun Anda
            </p>

        </div>

        <?php if ($message !== ""): ?>

            <div class="mb-5 rounded-xl border flex items-center gap-2.5
                <?= $message_type === 'success'
                    ? 'border-green-500/20 bg-green-500/10 text-green-300'
                    : ($message_type === 'info' 
                        ? 'border-blue-500/30 bg-blue-500/10 text-blue-300' 
                        : 'border-red-500/20 bg-red-500/10 text-red-300')
                ?>
                px-4 py-3 text-sm">

                <i class="fa-solid <?= $message_type === 'success' ? 'fa-circle-check text-green-400' : ($message_type === 'info' ? 'fa-lock text-blue-400' : 'fa-circle-exclamation text-red-400') ?>"></i>
                <span><?= htmlspecialchars($message) ?></span>

            </div>

        <?php endif; ?>

        <div class="rounded-3xl border border-white/10 bg-white/5 p-8 shadow-2xl">

            <form method="POST" class="space-y-5">
                <?= csrfField() ?>

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

            <!-- BUG-21 fix: Kredensial demo dihapus dari halaman publik untuk keamanan -->

        </div>

    </div>

</div>

</body>
</html>