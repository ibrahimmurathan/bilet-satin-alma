<?php
require_once __DIR__ . '/../includes/db.php';
session_start();

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['login'])) {
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

    // Alan kontrolü
    if ($email === '' || $password === '') {
        $errors[] = "Lütfen tüm alanları doldurun.";
    } else {
        // Kullanıcıyı bul
        $stmt = $db->prepare("SELECT id, full_name, email, password, role FROM User WHERE email = ?");
        $stmt->execute([$email]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        // Kullanıcı var mı ve şifre doğru mu?
        if ($user && password_verify($password, $user['password'])) {
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['user_name'] = $user['full_name'];
            $_SESSION['user_role'] = $user['role'];

            echo "<p style='color:green;'>Giriş başarılı! Yönlendiriliyorsunuz...</p>";

            // Role'e göre yönlendirme
            if ($user['role'] === 'admin') {
                header("refresh:1; url=home.php");
            } elseif ($user['role'] === 'company_admin') {
                header("refresh:1; url=home.php");
            } else {
                header("refresh:1; url=home.php");
            }
            exit;
        } else {
            $errors[] = "E-posta veya şifre hatalı.";
        }
    }
}
?>

<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <title>Giriş Yap - Tripin</title>
    <link rel="stylesheet" href="../assets/css/register_login.css">
</head>
<body>
    <div class="register-container"> <!-- Aynı class: Görsel bütünlük için -->
        <h2>Giriş Yap</h2>

        <?php if (!empty($errors)): ?>
            <ul class="error-list">
                <?php foreach ($errors as $err): ?>
                    <li><?= htmlspecialchars($err) ?></li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>

        <form action="" method="POST">
            <label>E-posta:</label><br>
            <input type="email" name="email" required><br><br>

            <label>Şifre:</label><br>
            <input type="password" name="password" required><br><br>

            <button type="submit" name="login">Giriş Yap</button>
        </form>

        <p class="alt-link">Hesabın yok mu? <a href="register.php">Kayıt ol</a></p>
    </div>
    
</body>
</html>
