<?php
// bus_ticket_system/index.php

session_start();

// Eğer kullanıcı zaten giriş yaptıysa home.php’ye yönlendir
if (isset($_SESSION['user_id'])) {
    header("Location: views/home.php");
    exit;
}

// Giriş yapılmamışsa yine home.php’ye gönderiyoruz (herkese açık liste var)
header("Location: views/home.php");
exit;
?>
