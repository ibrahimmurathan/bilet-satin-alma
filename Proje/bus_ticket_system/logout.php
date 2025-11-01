<?php
// bus_ticket_system/logout.php
// Güvenli oturum kapatma işlemi

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Tüm oturum verilerini temizle
$_SESSION = [];

// Session cookie'sini iptal et
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(
        session_name(),
        '',
        time() - 42000,
        $params["path"],
        $params["domain"],
        $params["secure"],
        $params["httponly"]
    );
}

// Oturumu tamamen yok et
session_destroy();

// Ana sayfaya yönlendir
header("Location: index.php");
exit;
?>
