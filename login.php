<?php
declare(strict_types=1);

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

$error = null;

if (!empty($_SESSION['user_id'])) {
    header('Location: index.php');
    exit();
}

$credentials = require __DIR__ . '/env/auth.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = (string) ($_POST['password'] ?? '');

    $isValidUser = hash_equals($credentials['username'], $username)
        && password_verify($password, $credentials['password_hash']);

    if ($isValidUser) {
        session_regenerate_id(true);
        $_SESSION['user_id'] = $credentials['username'];
        header('Location: index.php');
        exit();
    }

    $error = 'نام کاربری یا رمز عبور اشتباه است.';
}
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ورود به SuitStore Manager Pro</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Vazirmatn:wght@200;400;600&display=swap" rel="stylesheet">
    <style>
        body {
            font-family: 'Vazirmatn', sans-serif;
            background: linear-gradient(135deg, #1d4ed8, #1e40af);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0;
        }

        .login-card {
            background: #ffffff;
            border-radius: 16px;
            box-shadow: 0 20px 40px rgba(15, 23, 42, 0.2);
            padding: 40px;
            width: min(90vw, 380px);
        }

        .login-card h1 {
            margin: 0 0 10px 0;
            color: #1f2937;
            font-size: 24px;
            font-weight: 700;
            text-align: center;
        }

        .login-card p {
            margin: 0 0 24px 0;
            color: #4b5563;
            font-size: 14px;
            text-align: center;
        }

        label {
            display: block;
            color: #1f2937;
            font-weight: 500;
            margin-bottom: 6px;
            font-size: 14px;
        }

        input[type="text"],
        input[type="password"] {
            width: 100%;
            padding: 12px 14px;
            border-radius: 12px;
            border: 1px solid #d1d5db;
            font-size: 14px;
            transition: border-color 0.2s ease, box-shadow 0.2s ease;
            direction: ltr;
        }

        input[type="text"]:focus,
        input[type="password"]:focus {
            outline: none;
            border-color: #2563eb;
            box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.2);
        }

        button {
            width: 100%;
            padding: 12px 14px;
            border: none;
            border-radius: 12px;
            background: linear-gradient(135deg, #2563eb, #1d4ed8);
            color: #fff;
            font-size: 15px;
            font-weight: 600;
            cursor: pointer;
            transition: transform 0.2s ease, box-shadow 0.2s ease;
            margin-top: 12px;
        }

        button:hover {
            transform: translateY(-1px);
            box-shadow: 0 10px 20px rgba(37, 99, 235, 0.25);
        }

        .error-message {
            background: #fee2e2;
            color: #b91c1c;
            padding: 12px 14px;
            border-radius: 12px;
            font-size: 13px;
            margin-bottom: 16px;
            display: flex;
            align-items: center;
            gap: 8px;
        }
    </style>
</head>
<body>
    <div class="login-card">
        <h1>SuitStore Manager Pro</h1>
        <p>برای دسترسی به داشبورد وارد شوید</p>
        <?php if ($error !== null): ?>
            <div class="error-message">
                <?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?>
            </div>
        <?php endif; ?>
        <form method="post" autocomplete="off">
            <div style="margin-bottom: 16px;">
                <label for="username">نام کاربری</label>
                <input type="text" id="username" name="username" required>
            </div>
            <div style="margin-bottom: 16px;">
                <label for="password">رمز عبور</label>
                <input type="password" id="password" name="password" required>
            </div>
            <button type="submit">ورود</button>
        </form>
    </div>
</body>
</html>
