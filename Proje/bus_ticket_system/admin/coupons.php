<?php
session_start();
require_once __DIR__ . '/../includes/db.php';

if (!isset($_SESSION['user_id']) || ($_SESSION['user_role'] ?? '') !== 'admin') {
    header("Location: ../views/login.php"); exit;
}

function h($s){ return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }
$messages = [];

/* CREATE (global) */
if($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['create_coupon'])){
    $code = trim($_POST['code'] ?? '');
    $discount = $_POST['discount'] ?? '';
    $usage_limit = $_POST['usage_limit'] ?? '';
    $expire_date = $_POST['expire_date'] ?? '';
    if($code==='' || $discount==='' || $usage_limit==='' || $expire_date===''){
        $messages[] = ['type'=>'danger','text'=>'Tüm alanlar zorunlu.'];
    } else {
        $stmt = $db->prepare("INSERT INTO Coupons (code, discount, usage_limit, expire_date, company_id) VALUES (:c,:d,:u,:e,NULL)");
        $stmt->execute([':c'=>$code, ':d'=>$discount, ':u'=>$usage_limit, ':e'=>$expire_date]);
        $messages[] = ['type'=>'success','text'=>'Global kupon eklendi.'];
    }
}

/* UPDATE (global) */
if($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['update_coupon'])){
    $id = $_POST['id'] ?? '';
    $code = trim($_POST['code'] ?? '');
    $discount = $_POST['discount'] ?? '';
    $usage_limit = $_POST['usage_limit'] ?? '';
    $expire_date = $_POST['expire_date'] ?? '';
    if($id==='' || $code==='' || $discount==='' || $usage_limit==='' || $expire_date===''){
        $messages[] = ['type'=>'danger','text'=>'Güncelleme için tüm alanlar zorunlu.'];
    } else {
        $stmt = $db->prepare("UPDATE Coupons SET code=:c, discount=:d, usage_limit=:u, expire_date=:e WHERE id=:id AND company_id IS NULL");
        $stmt->execute([':c'=>$code, ':d'=>$discount, ':u'=>$usage_limit, ':e'=>$expire_date, ':id'=>$id]);
        $messages[] = ['type'=>'success','text'=>'Global kupon güncellendi.'];
    }
}

/* DELETE (global) */
if($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['delete_coupon'])){
    $id = $_POST['id'] ?? '';
    if($id!==''){
        $stmt = $db->prepare("DELETE FROM Coupons WHERE id=:id AND company_id IS NULL");
        $stmt->execute([':id'=>$id]);
        $messages[] = ['type'=>'success','text'=>'Global kupon silindi.'];
    }
}

/* LİSTE: sadece global kuponlar */
$coupons = $db->query("SELECT id, code, discount, usage_limit, expire_date, created_at FROM Coupons WHERE company_id IS NULL ORDER BY created_at DESC")->fetchAll(PDO::FETCH_ASSOC);
?>
<!doctype html>
<html lang="tr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Admin | Global Kuponlar</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">

<nav class="navbar navbar-expand-lg bg-white shadow-sm">
  <div class="container">
    <a class="navbar-brand fw-bold" href="admin_dashboard.php">Admin Paneli</a>
    <div class="d-flex ms-auto gap-2">
      <a href="../views/home.php" class="btn btn-outline-primary">Anasayfa</a>
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

<div class="container py-4">
  <?php foreach($messages as $m): ?>
    <div class="alert alert-<?= h($m['type']) ?>"><?= h($m['text']) ?></div>
  <?php endforeach; ?>

  <div class="card mb-4">
    <div class="card-body">
      <h5 class="mb-3">Yeni Global Kupon Ekle</h5>
      <form method="post" class="row g-2">
        <input type="hidden" name="create_coupon" value="1">
        <div class="col-md-3"><input class="form-control" name="code" placeholder="Kod" required></div>
        <div class="col-md-2"><input type="number" step="0.01" class="form-control" name="discount" placeholder="İndirim (%)" required></div>
        <div class="col-md-2"><input type="number" class="form-control" name="usage_limit" placeholder="Kullanım Limiti" required></div>
        <div class="col-md-3"><input type="date" class="form-control" name="expire_date" required></div>
        <div class="col-md-2"><button class="btn btn-success w-100">Ekle</button></div>
      </form>
      <small class="text-muted d-block mt-2">Bu sayfadan oluşturulan kuponlar <strong>tüm firmalarda geçerlidir</strong> (global).</small>
    </div>
  </div>

  <h5>Global Kuponlar</h5>
  <div class="table-responsive">
    <table class="table table-striped align-middle">
      <thead><tr><th>Kod</th><th>İndirim</th><th>Limit</th><th>Bitiş</th><th>Oluşturulma</th><th class="text-end">İşlemler</th></tr></thead>
      <tbody>
        <?php foreach($coupons as $c): ?>
        <tr>
          <td><?= h($c['code']) ?></td>
          <td><?= h($c['discount']) ?> %</td>
          <td><?= h($c['usage_limit']) ?></td>
          <td><?= h($c['expire_date']) ?></td>
          <td><?= h($c['created_at']) ?></td>
          <td class="text-end">
            <form method="post" class="d-inline-flex gap-2 flex-wrap justify-content-end">
              <input type="hidden" name="id" value="<?= h($c['id']) ?>">
              <input class="form-control form-control-sm" name="code" value="<?= h($c['code']) ?>" required>
              <input type="number" step="0.01" class="form-control form-control-sm" name="discount" value="<?= h($c['discount']) ?>" required>
              <input type="number" class="form-control form-control-sm" name="usage_limit" value="<?= h($c['usage_limit']) ?>" required>
              <input type="date" class="form-control form-control-sm" name="expire_date" value="<?= h(date('Y-m-d', strtotime($c['expire_date']))) ?>" required>
              <button class="btn btn-sm btn-primary" name="update_coupon">Güncelle</button>
              <button class="btn btn-sm btn-danger" name="delete_coupon" onclick="return confirm('Silinsin mi?')">Sil</button>
            </form>
          </td>
        </tr>
        <?php endforeach; ?>
        <?php if(empty($coupons)): ?>
        <tr><td colspan="6" class="text-muted">Henüz global kupon yok.</td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>

</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
