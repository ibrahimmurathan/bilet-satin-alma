<?php
session_start();
require_once __DIR__ . '/../includes/db.php';

/* ---------- Helper ---------- */
function h($s) { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }

if(!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'company') {
    die('Erişim engellendi.');
}

$company_id = $_SESSION['company_id'];

/* ---------- Mevcut seferler ve kuponlar ---------- */
$trips = $db->prepare("SELECT * FROM Trips WHERE company_id=:company_id ORDER BY departure_time ASC");
$trips->execute([':company_id'=>$company_id]);
$trips = $trips->fetchAll(PDO::FETCH_ASSOC);

$coupons = $db->prepare("SELECT * FROM Coupons WHERE company_id=:company_id ORDER BY created_at DESC");
$coupons->execute([':company_id'=>$company_id]);
$coupons = $coupons->fetchAll(PDO::FETCH_ASSOC);

$messages = [];

/* ---------- Güncelleme veya Silme ---------- */
if($_SERVER['REQUEST_METHOD'] === 'POST') {

    // SEFER GÜNCELLE
    if(isset($_POST['update_trip'])) {
        $id = $_POST['trip_id'];
        $stmt = $db->prepare("UPDATE Trips SET departure_city=:d, destination_city=:to, departure_time=:dt, arrival_time=:at, price=:p WHERE id=:id AND company_id=:cid");
        $stmt->execute([
            ':d'=>$_POST['departure_city'],
            ':to'=>$_POST['destination_city'],
            ':dt'=>$_POST['departure_time'],
            ':at'=>$_POST['arrival_time'],
            ':p'=>$_POST['price'],
            ':id'=>$id,
            ':cid'=>$company_id
        ]);
        $messages[] = ['type'=>'success','text'=>'Sefer güncellendi.'];
    }

    // SEFER SİL
    if(isset($_POST['delete_trip'])) {
        $id = $_POST['trip_id'];
        $stmt = $db->prepare("DELETE FROM Trips WHERE id=:id AND company_id=:cid");
        $stmt->execute([':id'=>$id, ':cid'=>$company_id]);
        $messages[] = ['type'=>'success','text'=>'Sefer silindi.'];
    }

    // KUPON GÜNCELLE
    if(isset($_POST['update_coupon'])) {
        $id = $_POST['coupon_id'];
        $stmt = $db->prepare("UPDATE Coupons SET code=:code, discount=:discount, usage_limit=:usage_limit, expire_date=:expire_date WHERE id=:id AND company_id=:cid");
        $stmt->execute([
            ':code'=>$_POST['code'],
            ':discount'=>$_POST['discount'],
            ':usage_limit'=>$_POST['usage_limit'],
            ':expire_date'=>$_POST['expire_date'],
            ':id'=>$id,
            ':cid'=>$company_id
        ]);
        $messages[] = ['type'=>'success','text'=>'Kupon güncellendi.'];
    }

    // KUPON SİL
    if(isset($_POST['delete_coupon'])) {
        $id = $_POST['coupon_id'];
        $stmt = $db->prepare("DELETE FROM Coupons WHERE id=:id AND company_id=:cid");
        $stmt->execute([':id'=>$id, ':cid'=>$company_id]);
        $messages[] = ['type'=>'success','text'=>'Kupon silindi.'];
    }
}
?>
<!doctype html>
<html lang="tr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Düzenle - Firma Paneli</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">

<nav class="navbar navbar-expand-lg navbar-white bg-white shadow-sm">
<div class="container">
<a class="navbar-brand fw-bold" href="#">Firma Paneli - Düzenle</a>
<div class="d-flex ms-auto gap-2">
    <a href="manage_trips.php" class="btn btn-outline-primary">Manage Trips'e Dön</a>
    <a href="../logout.php" class="btn btn-outline-danger">Çıkış Yap</a>
</div>
</div>
</nav>


<div class="container my-4">
<?php foreach($messages as $m): ?>
<div class="alert alert-<?= h($m['type']) ?>"><?= h($m['text']) ?></div>
<?php endforeach; ?>

<h4>Seferler</h4>
<?php foreach($trips as $t): ?>
<form method="post" class="mb-3 p-3 border bg-white">
<input type="hidden" name="trip_id" value="<?= h($t['id']) ?>">
<div class="row g-2">
<div class="col-md-2"><input class="form-control" name="departure_city" value="<?= h($t['departure_city']) ?>"></div>
<div class="col-md-2"><input class="form-control" name="destination_city" value="<?= h($t['destination_city']) ?>"></div>
<div class="col-md-2"><input type="datetime-local" class="form-control" name="departure_time" value="<?= date('Y-m-d\TH:i', strtotime($t['departure_time'])) ?>"></div>
<div class="col-md-2"><input type="datetime-local" class="form-control" name="arrival_time" value="<?= date('Y-m-d\TH:i', strtotime($t['arrival_time'])) ?>"></div>
<div class="col-md-1"><input type="number" class="form-control" name="price" value="<?= h($t['price']) ?>"></div>
<div class="col-md-3 d-flex gap-2">
<button name="update_trip" class="btn btn-warning">Güncelle</button>
<button name="delete_trip" class="btn btn-danger" onclick="return confirm('Silmek istediğinize emin misiniz?')">Sil</button>
</div>
</div>
</form>
<?php endforeach; ?>

<h4>Kuponlar</h4>
<?php foreach($coupons as $c): ?>
<form method="post" class="mb-3 p-3 border bg-white">
<input type="hidden" name="coupon_id" value="<?= h($c['id']) ?>">
<div class="row g-2">
<div class="col-md-3"><input class="form-control" name="code" value="<?= h($c['code']) ?>"></div>
<div class="col-md-2"><input type="number" step="0.01" class="form-control" name="discount" value="<?= h($c['discount']) ?>"></div>
<div class="col-md-2"><input type="number" class="form-control" name="usage_limit" value="<?= h($c['usage_limit']) ?>"></div>
<div class="col-md-3"><input type="date" class="form-control" name="expire_date" value="<?= date('Y-m-d', strtotime($c['expire_date'])) ?>"></div>
<div class="col-md-2 d-flex gap-2">
<button name="update_coupon" class="btn btn-warning">Güncelle</button>
<button name="delete_coupon" class="btn btn-danger" onclick="return confirm('Silmek istediğinize emin misiniz?')">Sil</button>
</div>
</div>
</form>
<?php endforeach; ?>

</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
