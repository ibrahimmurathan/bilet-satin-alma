<?php
require_once __DIR__ . '/../includes/db.php'; // Güvenli PDO bağlantısı

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['register'])) {
    $fullname = trim($_POST['fullname'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';

    $errors = [];

    // Alan doğrulama
    if ($fullname === '' || $email === '' || $password === '' || $confirm_password === '') {
        $errors[] = "Lütfen tüm alanları doldurun.";
    }

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = "Geçerli bir e-posta adresi girin.";
    }

    if ($password !== $confirm_password) {
        $errors[] = "Şifreler eşleşmiyor.";
    }

    //  E-posta benzersiz mi kontrol et
    if (empty($errors)) {
        $stmt = $db->prepare("SELECT id FROM User WHERE email = ?");
        $stmt->execute([$email]);
        if ($stmt->fetch()) {
            $errors[] = "Bu e-posta zaten kayıtlı!";
        }
    }

    // Hata yoksa kullanıcı kaydı
    if (empty($errors)) {
        $hashedPassword = password_hash($password, PASSWORD_DEFAULT);

        $stmt = $db->prepare("
            INSERT INTO User (full_name, email, password, role, balance, company_id)
            VALUES (?, ?, ?, 'user', 800, NULL)
        ");
        $stmt->execute([$fullname, $email, $hashedPassword]);

        echo "<p style='color:green;'>Kayıt başarılı! Giriş sayfasına yönlendiriliyorsunuz...</p>";
        header("refresh:2; url=login.php");
        exit;
    }
}
?>

<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <title>Kayıt Ol - Tripin</title>
    <link rel="stylesheet" href="../assets/css/register_login.css">
</head>
<body>

<div class="register-container">
    <h2>Kayıt Ol</h2>

    <!-- Hata mesajları -->
    <?php if (!empty($errors)): ?>
        <ul>
            <?php foreach ($errors as $err): ?>
                <li><?= htmlspecialchars($err) ?></li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>

    <!-- Başarılı kayıt -->
    <?php if (isset($success) && $success): ?>
        <p class="success-msg">Kayıt başarılı! Giriş sayfasına yönlendiriliyorsunuz...</p>
    <?php endif; ?>

    <form action="" method="POST">
        <input type="text" name="fullname" placeholder="Ad Soyad" required>
        <input type="email" name="email" placeholder="E-posta" required>
        <input type="password" name="password" placeholder="Şifre" required>
    <input type="password" name="confirm_password" placeholder="Şifre (Tekrar)" required>
    <button type="submit" name="register">Kayıt Ol</button>
    <p class="have-account">Zaten üye misin? <a href="login.php">Giriş yap</a></p>
    </form>
</div>

</body>
</html>
