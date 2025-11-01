<?php
session_start();
require_once __DIR__ . '/../includes/db.php';

/* ---------- Oturum/Rol standardizasyonu ---------- */
// Backward-compat: eski anahtar varsa tekilleştir
if (isset($_SESSION['role']) && !isset($_SESSION['user_role'])) {
    $_SESSION['user_role'] = $_SESSION['role'];
}

if (!isset($_SESSION['user_id'])) {
    http_response_code(302);
    header('Location: ../views/login.php'); exit;
}

// Rol yoksa DB’den çek ve cache’le
if (!isset($_SESSION['user_role'])) {
    $sr = $db->prepare("SELECT role, company_id FROM User WHERE id = :id LIMIT 1");
    $sr->execute([':id' => $_SESSION['user_id']]);
    $r = $sr->fetch(PDO::FETCH_ASSOC);
    $sr->closeCursor();
    $_SESSION['user_role']  = $r['role'] ?? 'user';
    if (!isset($_SESSION['company_id']) && !empty($r['company_id'])) {
        $_SESSION['company_id'] = $r['company_id'];
    }
}

// Sadece company erişsin
if (($_SESSION['user_role'] ?? '') !== 'company') {
    http_response_code(403);
    exit('Bu sayfaya erişim yetkiniz yok.');
}

// company_id yoksa DB’den doldur
if (empty($_SESSION['company_id'])) {
    $sc = $db->prepare("SELECT company_id FROM User WHERE id = :id LIMIT 1");
    $sc->execute([':id' => $_SESSION['user_id']]);
    $_SESSION['company_id'] = $sc->fetchColumn() ?: null;
    $sc->closeCursor();
    if (empty($_SESSION['company_id'])) {
        http_response_code(403);
        exit('Firma hesabınız ile ilişkili company_id bulunamadı.');
    }
}

$company_id = $_SESSION['company_id'];

/* ---------- Firma adı/logo ---------- */
$stmt = $db->prepare("SELECT company_name, logo_path FROM Bus_Company WHERE id = :id LIMIT 1");
$stmt->execute([':id' => $company_id]);
$company = $stmt->fetch(PDO::FETCH_ASSOC);
$stmt->closeCursor();
if (!$company) { http_response_code(403); exit('Firma bilgisi bulunamadı.'); }

/* ---------- Firma Seferleri + Doluluk ---------- */
$stmt = $db->prepare("
    SELECT 
        tr.*,
        IFNULL(COUNT(bs.id),0) AS dolu_koltuk,
        ROUND((CAST(COUNT(bs.id) AS REAL) / tr.capacity) * 100, 1) AS doluluk_orani
    FROM Trips tr
    LEFT JOIN Tickets t ON t.trip_id = tr.id AND lower(trim(t.status)) = 'active'
    LEFT JOIN Booked_Seats bs ON bs.ticket_id = t.id
    WHERE tr.company_id = :company_id
    GROUP BY tr.id
    ORDER BY datetime(tr.departure_time) ASC
");
$stmt->execute([':company_id' => $company_id]);
$trips = $stmt->fetchAll(PDO::FETCH_ASSOC);
$stmt->closeCursor();

/* ---------- Firma Kuponları ---------- */
$stmt = $db->prepare("SELECT * FROM Coupons WHERE company_id = :company_id ORDER BY created_at DESC");
$stmt->execute([':company_id' => $company_id]);
$coupons = $stmt->fetchAll(PDO::FETCH_ASSOC);
$stmt->closeCursor();
?>
<!doctype html>
<html lang="tr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Firma Dashboard</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
</head>
<body class="bg-light">

<nav class="navbar navbar-expand-lg navbar-white bg-white shadow-sm">
<div class="container">
  <a class="navbar-brand fw-bold" href="#">Firma Paneli - <?= htmlspecialchars($company['company_name'] ?? 'Firma') ?></a>
  <div class="d-flex align-items-center gap-2">
    <a href="manage_trips.php" class="btn btn-outline-success">
      <i class="bi bi-plus-circle"></i> Yeni Sefer Ekle
    </a>
    <div class="dropdown">
      <a class="btn btn-outline-secondary dropdown-toggle" href="#" role="button" data-bs-toggle="dropdown" aria-expanded="false">
        <?= htmlspecialchars($_SESSION['user_name'] ?? 'Kullanıcı') ?>
      </a>
      <ul class="dropdown-menu dropdown-menu-end">
        <li><a class="dropdown-item" href="../views/home.php">Ana Sayfa</a></li>
        <li><a class="dropdown-item" href="../views/profile.php">Profil</a></li>
        <li><hr class="dropdown-divider"></li>
        <li><a class="dropdown-item" href="../logout.php">Çıkış Yap</a></li>
      </ul>
    </div>
  </div>
</div>
</nav>

<div class="container my-4">

  <div class="d-flex justify-content-between align-items-center mb-3">
    <h3>Firma Seferleri</h3>
    <a href="manage_trips.php" class="btn btn-sm btn-outline-primary">Seferleri Yönet</a>
  </div>

  <div class="row g-3 mb-4">
    <?php foreach($trips as $trip): ?>
    <div class="col-md-6 col-lg-4">
      <div class="card shadow-sm h-100">
        <div class="card-body">
          <h5 class="card-title"><?= htmlspecialchars($trip['departure_city']) ?> → <?= htmlspecialchars($trip['destination_city']) ?></h5>
          <p class="mb-1"><strong>Fiyat:</strong> <?= htmlspecialchars($trip['price']) ?> ₺</p>
          <p class="mb-1"><strong>Kalkış:</strong> <?= htmlspecialchars($trip['departure_time']) ?></p>
          <p class="mb-1"><strong>Varış:</strong> <?= htmlspecialchars($trip['arrival_time']) ?></p>
          <p class="mb-1 text-secondary">
            <strong>Doluluk:</strong>
            <?= htmlspecialchars($trip['dolu_koltuk']) ?> / <?= htmlspecialchars($trip['capacity']) ?>
            (<?= htmlspecialchars($trip['doluluk_orani']) ?>%)
          </p>
          <a href="manage_trips.php?edit=<?= htmlspecialchars($trip['id']) ?>" class="btn btn-outline-primary w-100 mt-2">Düzenle</a>
        </div>
      </div>
    </div>
    <?php endforeach; ?>
    <?php if (empty($trips)): ?>
      <div class="col-12">
        <div class="alert alert-info">
          Henüz seferiniz bulunmuyor. <a href="manage_trips.php" class="alert-link">Buradan yeni sefer ekleyin.</a>
        </div>
      </div>
    <?php endif; ?>
  </div>

  <h3>Firma Kuponları</h3>
  <table class="table table-striped">
    <thead>
      <tr>
        <th>Kod</th>
        <th>İndirim (%)</th>
        <th>Kullanım Limiti</th>
        <th>Bitiş Tarihi</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach($coupons as $c): ?>
      <tr>
        <td><?= htmlspecialchars($c['code']) ?></td>
        <td><?= htmlspecialchars($c['discount']) ?></td>
        <td><?= htmlspecialchars($c['usage_limit']) ?></td>
        <td><?= htmlspecialchars($c['expire_date']) ?></td>
      </tr>
      <?php endforeach; ?>
      <?php if (empty($coupons)): ?>
      <tr><td colspan="4" class="text-muted">Kupon bulunamadı.</td></tr>
      <?php endif; ?>
    </tbody>
  </table>

</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
