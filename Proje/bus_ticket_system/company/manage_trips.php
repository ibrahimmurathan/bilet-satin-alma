<?php
session_start();
require_once __DIR__ . '/../includes/db.php'; // $db PDO bağlantısı

/* ---------- Helper ---------- */
function h($s) { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }
$messages = [];

/* ---------- Kullanıcı yetki & kimlik ---------- */
if (!isset($_SESSION['user_id'])) {
    header('Location: ../views/login.php'); exit;
}

// Rol yoksa DB'den çek
if (!isset($_SESSION['role'])) {
    $stmt = $db->prepare("SELECT role, company_id FROM User WHERE id = :id LIMIT 1");
    $stmt->execute([':id' => $_SESSION['user_id']]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    $_SESSION['role'] = $row['role'] ?? 'user';
    $_SESSION['company_id'] = $row['company_id'] ?? null;
}

// company_id yoksa (rol daha önce set edilmiş olabilir) sadece company_id'yi çek
if (!isset($_SESSION['company_id'])) {
    $stmt = $db->prepare("SELECT company_id FROM User WHERE id = :id LIMIT 1");
    $stmt->execute([':id' => $_SESSION['user_id']]);
    $_SESSION['company_id'] = $stmt->fetchColumn() ?: null;
}

$role = $_SESSION['role'] ?? 'user';
$company_id = $_SESSION['company_id'] ?? null;

// Firma yetkilisi değilse engelle
if ($role !== 'company') {
    http_response_code(403);
    die('Erişim engellendi: Bu sayfa yalnızca Firma Yetkilileri içindir.');
}

// Firma ataması yoksa (null) engelle
if (empty($company_id)) {
    http_response_code(403);
    ?>
    <!doctype html>
    <html lang="tr"><head>
    <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Firma Paneli</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    </head><body class="bg-light">
    <nav class="navbar navbar-expand-lg navbar-white bg-white shadow-sm">
      <div class="container">
        <a class="navbar-brand fw-bold" href="#">Firma Paneli</a>
        <div class="ms-auto">
          <a href="../logout.php" class="btn btn-outline-danger">Çıkış Yap</a>
        </div>
      </div>
    </nav>
    <div class="container my-5">
      <div class="alert alert-warning">
        Bu hesaba atanmış bir <strong>firma</strong> bulunamadı. Lütfen sistem yöneticisi ile iletişime geçin.
      </div>
      <a href="../views/home.php" class="btn btn-primary">Ana sayfaya dön</a>
    </div>
    </body></html>
    <?php
    exit;
}

/* ---------- Sefer ekleme ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_trip'])) {
    $departure_city  = trim($_POST['departure_city'] ?? '');
    $destination_city= trim($_POST['destination_city'] ?? '');
    $departure_time  = trim($_POST['departure_time'] ?? '');
    $arrival_time    = trim($_POST['arrival_time'] ?? '');
    $price           = (int)($_POST['price'] ?? 0);

    if ($departure_city && $destination_city && $departure_time && $arrival_time && $price > 0) {
        $stmt = $db->prepare("
            INSERT INTO Trips (company_id, departure_city, destination_city, departure_time, arrival_time, price)
            VALUES (:company_id, :departure_city, :destination_city, :departure_time, :arrival_time, :price)
        ");
        $stmt->execute([
            ':company_id'      => $company_id,
            ':departure_city'  => $departure_city,
            ':destination_city'=> $destination_city,
            ':departure_time'  => $departure_time,
            ':arrival_time'    => $arrival_time,
            ':price'           => $price
        ]);
        $messages[] = ['type'=>'success','text'=>'Sefer eklendi.'];
    } else {
        $messages[] = ['type'=>'danger','text'=>'Lütfen tüm alanları eksiksiz doldurun.'];
    }
}

/* ---------- Kupon ekleme ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_coupon'])) {
    $code        = trim($_POST['code'] ?? '');
    $discount    = (float)($_POST['discount'] ?? 0);
    $usage_limit = (int)($_POST['usage_limit'] ?? 0);
    $expire_date = trim($_POST['expire_date'] ?? '');

    if ($code && $discount > 0 && $usage_limit > 0 && $expire_date) {
        $stmt = $db->prepare("
            INSERT INTO Coupons (code, discount, usage_limit, expire_date, company_id)
            VALUES (:code, :discount, :usage_limit, :expire_date, :company_id)
        ");
        $stmt->execute([
            ':code'        => $code,
            ':discount'    => $discount,
            ':usage_limit' => $usage_limit,
            ':expire_date' => $expire_date,
            ':company_id'  => $company_id
        ]);
        $messages[] = ['type'=>'success','text'=>'Kupon eklendi.'];
    } else {
        $messages[] = ['type'=>'danger','text'=>'Lütfen tüm alanları eksiksiz doldurun.'];
    }
}

/* ---------- Mevcut seferler ve kuponlar ---------- */
$tripsStmt = $db->prepare("SELECT * FROM Trips WHERE company_id = :company_id ORDER BY datetime(departure_time) ASC");
$tripsStmt->execute([':company_id' => $company_id]);
$trips = $tripsStmt->fetchAll(PDO::FETCH_ASSOC);

$couponsStmt = $db->prepare("SELECT * FROM Coupons WHERE company_id = :company_id ORDER BY datetime(created_at) DESC");
$couponsStmt->execute([':company_id' => $company_id]);
$coupons = $couponsStmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!doctype html>
<html lang="tr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Firma Paneli - Sefer & Kupon Yönetimi</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">

<nav class="navbar navbar-expand-lg navbar-white bg-white shadow-sm">
  <div class="container">
    <a class="navbar-brand fw-bold" href="#">Firma Paneli</a>
    <div class="d-flex ms-auto gap-2">
      <a href="../company/company_dashboard.php" class="btn btn-outline-secondary">Dashboard'a Dön</a>
      <a href="../logout.php" class="btn btn-outline-danger">Çıkış Yap</a>
    </div>
  </div>
</nav>

<div class="container my-4">
  <?php foreach($messages as $m): ?>
    <div class="alert alert-<?= h($m['type']) ?>"><?= h($m['text']) ?></div>
  <?php endforeach; ?>

  <div class="row">
    <div class="col-md-6">
      <h4>Yeni Sefer Ekle</h4>
      <form method="post">
        <input type="hidden" name="add_trip">
        <div class="mb-2"><input class="form-control" name="departure_city" placeholder="Kalkış"></div>
        <div class="mb-2"><input class="form-control" name="destination_city" placeholder="Varış"></div>
        <div class="mb-2"><input type="datetime-local" class="form-control" name="departure_time" placeholder="Kalkış zamanı"></div>
        <div class="mb-2"><input type="datetime-local" class="form-control" name="arrival_time" placeholder="Varış zamanı"></div>
        <div class="mb-2"><input type="number" class="form-control" name="price" placeholder="Fiyat"></div>
        <button class="btn btn-primary w-100" type="submit">Sefer Ekle</button>
      </form>
    </div>

    <div class="col-md-6">
      <h4>Yeni Kupon Ekle</h4>
      <form method="post">
        <input type="hidden" name="add_coupon">
        <div class="mb-2"><input class="form-control" name="code" placeholder="Kupon Kodu"></div>
        <div class="mb-2"><input type="number" step="0.01" class="form-control" name="discount" placeholder="İndirim (%)"></div>
        <div class="mb-2"><input type="number" class="form-control" name="usage_limit" placeholder="Kullanım Limiti"></div>
        <div class="mb-2"><input type="date" class="form-control" name="expire_date"></div>
        <button class="btn btn-success w-100" type="submit">Kupon Ekle</button>
      </form>
    </div>
  </div>

  <hr>
  <h4>Mevcut Seferler</h4>
  <div class="table-responsive">
    <table class="table table-striped align-middle">
      <thead><tr>
        <th>Kalkış</th><th>Varış</th><th>Kalkış Zamanı</th><th>Varış Zamanı</th><th>Fiyat</th><th>İşlem</th>
      </tr></thead>
      <tbody>
      <?php if(empty($trips)): ?>
        <tr><td colspan="6" class="text-muted">Kayıtlı sefer yok.</td></tr>
      <?php else: foreach($trips as $t): ?>
        <tr>
          <td><?= h($t['departure_city']) ?></td>
          <td><?= h($t['destination_city']) ?></td>
          <td><?= h($t['departure_time']) ?></td>
          <td><?= h($t['arrival_time']) ?></td>
          <td><?= h($t['price']) ?> ₺</td>
          <td>
            <a href="edit.php?trip_id=<?= h($t['id']) ?>" class="btn btn-sm btn-warning">Düzenle</a>
          </td>
        </tr>
      <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>

  <h4>Mevcut Kuponlar</h4>
  <div class="table-responsive">
    <table class="table table-striped align-middle">
      <thead><tr>
        <th>Kod</th><th>İndirim</th><th>Kullanım Limiti</th><th>Bitiş Tarihi</th><th>İşlem</th>
      </tr></thead>
      <tbody>
      <?php if(empty($coupons)): ?>
        <tr><td colspan="5" class="text-muted">Kayıtlı kupon yok.</td></tr>
      <?php else: foreach($coupons as $c): ?>
        <tr>
          <td><?= h($c['code']) ?></td>
          <td><?= h($c['discount']) ?> %</td>
          <td><?= h($c['usage_limit']) ?></td>
          <td><?= h($c['expire_date']) ?></td>
          <td>
            <a href="edit.php?coupon_id=<?= h($c['id']) ?>" class="btn btn-sm btn-warning">Düzenle</a>
          </td>
        </tr>
      <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>

</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
