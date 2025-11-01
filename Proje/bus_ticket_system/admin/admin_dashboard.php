<?php
session_start();
require_once __DIR__ . '/../includes/db.php';

/* --- Güvenlik --- */
if (!isset($_SESSION['user_id']) || ($_SESSION['user_role'] ?? '') !== 'admin') {
    header("Location: ../views/login.php");
    exit;
}

/* Helper */
function h($s){ return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }
?>
<!doctype html>
<html lang="tr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Admin Paneli - Tripin</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">

<nav class="navbar navbar-expand-lg navbar-white bg-white shadow-sm">
  <div class="container">
    <a class="navbar-brand fw-bold" href="../views/home.php">Tripin Admin Paneli</a>
    <div class="d-flex ms-auto gap-2">
      <div class="dropdown">
        <button class="btn btn-outline-secondary dropdown-toggle" data-bs-toggle="dropdown">
          <?= h($_SESSION['user_name'] ?? 'Admin') ?>
        </button>
        <ul class="dropdown-menu dropdown-menu-end">
          <li><a class="dropdown-item" href="../views/profile.php">Profil</a></li>
          <li><hr class="dropdown-divider"></li>
          <li><a class="dropdown-item text-danger" href="../logout.php">Çıkış Yap</a></li>
        </ul>
      </div>
    </div>
  </div>
</nav>

<div class="container py-5">
  <div class="text-center mb-4">
    <h2 class="fw-bold">Yönetim Paneli</h2>
    <p class="text-muted">Firma ve global kupon yönetimine buradan erişin.</p>
  </div>

  <div class="row g-4 justify-content-center">
    <div class="col-md-5">
      <div class="card h-100 shadow-sm">
        <div class="card-body text-center">
          <h5 class="card-title fw-bold mb-3">Firma Yönetimi</h5>
          <p class="text-muted">Firma CRUD ve firma admin (company) kullanıcı yönetimi.</p>
          <a href="companies.php" class="btn btn-outline-primary w-100">Firmaları Yönet</a>
        </div>
      </div>
    </div>

    <div class="col-md-5">
      <div class="card h-100 shadow-sm">
        <div class="card-body text-center">
          <h5 class="card-title fw-bold mb-3">Global Kuponlar</h5>
          <p class="text-muted">Tüm firmalarda geçerli kuponları yönetin.</p>
          <a href="coupons.php" class="btn btn-outline-warning w-100">Kuponları Yönet</a>
        </div>
      </div>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
