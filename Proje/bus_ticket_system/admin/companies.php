<?php
session_start();
require_once __DIR__ . '/../includes/db.php';

if (!isset($_SESSION['user_id']) || ($_SESSION['user_role'] ?? '') !== 'admin') {
    header("Location: ../views/login.php"); exit;
}

function h($s){ return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }
$messages = [];

/* -------- Firma CRUD -------- */
/* CREATE */
if($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['create_company'])){
    $name = trim($_POST['company_name'] ?? '');
    $logo = trim($_POST['logo_path'] ?? '');
    if($name===''){
        $messages[] = ['type'=>'danger','text'=>'Firma adı zorunlu.'];
    } else {
        $stmt = $db->prepare("INSERT INTO Bus_Company (company_name, logo_path) VALUES (:n,:l)");
        $stmt->execute([':n'=>$name, ':l'=>$logo ?: null]);
        $messages[] = ['type'=>'success','text'=>'Firma eklendi.'];
    }
}

/* UPDATE */
if($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['update_company'])){
    $id   = $_POST['id'] ?? '';
    $name = trim($_POST['company_name'] ?? '');
    $logo = trim($_POST['logo_path'] ?? '');
    if($id==='' || $name===''){
        $messages[] = ['type'=>'danger','text'=>'Geçersiz firma güncelleme talebi.'];
    } else {
        $stmt = $db->prepare("UPDATE Bus_Company SET company_name=:n, logo_path=:l WHERE id=:id");
        $stmt->execute([':n'=>$name, ':l'=>$logo ?: null, ':id'=>$id]);
        $messages[] = ['type'=>'success','text'=>'Firma güncellendi.'];
    }
}

/* DELETE */
if($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['delete_company'])){
    $id = $_POST['id'] ?? '';
    if($id!==''){
        $stmt = $db->prepare("DELETE FROM Bus_Company WHERE id=:id");
        $stmt->execute([':id'=>$id]);
        $messages[] = ['type'=>'success','text'=>'Firma silindi.'];
    }
}

/* LİSTE: Firmalar */
$companies = $db->query("SELECT id, company_name, logo_path, created_at FROM Bus_Company ORDER BY company_name ASC")->fetchAll(PDO::FETCH_ASSOC);

/* -------- Firma Admin Yönetimi (role='company') -------- */
/* Yeni Firma Admin Oluştur + Firmaya Ata */
if($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['create_company_admin'])){
    $full_name  = trim($_POST['full_name'] ?? '');
    $email      = trim($_POST['email'] ?? '');
    $password   = $_POST['password'] ?? '';
    $company_id = $_POST['company_id'] ?? '';

    if($full_name==='' || $email==='' || $password==='' || $company_id===''){
        $messages[] = ['type'=>'danger','text'=>'Ad Soyad, e-posta, şifre ve firma alanları zorunlu.'];
    } elseif(!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $messages[] = ['type'=>'danger','text'=>'Geçerli bir e-posta girin.'];
    } else {
        $chk = $db->prepare("SELECT 1 FROM User WHERE email=:e LIMIT 1");
        $chk->execute([':e'=>$email]);
        if($chk->fetch()){
            $messages[] = ['type'=>'danger','text'=>'Bu e-posta zaten kayıtlı.'];
        } else {
            $hash = password_hash($password, PASSWORD_DEFAULT);
            $ins  = $db->prepare("INSERT INTO User (full_name, email, role, password, balance, company_id) VALUES (:n,:e,'company',:p,800,:cid)");
            $ins->execute([':n'=>$full_name, ':e'=>$email, ':p'=>$hash, ':cid'=>$company_id]);
            $messages[] = ['type'=>'success','text'=>'Firma admini oluşturuldu ve firmaya atandı.'];
        }
    }
}

/* Firma Admini Yeniden Ata (re-assign) */
if($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['reassign_company_admin'])){
    $uid = $_POST['user_id'] ?? '';
    $cid = $_POST['company_id'] ?? '';
    if($uid!=='' && $cid!==''){
        $upd = $db->prepare("UPDATE User SET company_id=:cid WHERE id=:id AND role='company'");
        $upd->execute([':cid'=>$cid, ':id'=>$uid]);
        $messages[] = ['type'=>'success','text'=>'Firma admin ataması güncellendi.'];
    } else {
        $messages[] = ['type'=>'danger','text'=>'Geçersiz atama talebi.'];
    }
}

/* Firma Admini Sil */
if($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['delete_company_admin'])){
    $uid = $_POST['user_id'] ?? '';
    if($uid!==''){
        $del = $db->prepare("DELETE FROM User WHERE id=:id AND role='company'");
        $del->execute([':id'=>$uid]);
        $messages[] = ['type'=>'success','text'=>'Firma admini silindi.'];
    }
}

/* LİSTE: Firma Adminleri (role='company') + firma adı */
$company_admins = $db->query("
    SELECT u.id, u.full_name, u.email, u.company_id, u.created_at, bc.company_name
      FROM User u
 LEFT JOIN Bus_Company bc ON bc.id = u.company_id
     WHERE u.role='company'
  ORDER BY u.created_at DESC
")->fetchAll(PDO::FETCH_ASSOC);
?>
<!doctype html>
<html lang="tr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Admin | Firmalar & Firma Adminleri</title>
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

  <!-- Firma Ekle -->
  <div class="card mb-4">
    <div class="card-body">
      <h5 class="mb-3">Yeni Firma Ekle</h5>
      <form method="post" class="row g-2">
        <input type="hidden" name="create_company" value="1">
        <div class="col-md-4"><input class="form-control" name="company_name" placeholder="Firma adı" required></div>
        <div class="col-md-6"><input class="form-control" name="logo_path" placeholder="Logo yolu (örn: assets/images/logo.png)"></div>
        <div class="col-md-2"><button class="btn btn-success w-100">Ekle</button></div>
      </form>
    </div>
  </div>

  <!-- Firma Listesi -->
  <h5>Firmalar</h5>
  <div class="table-responsive mb-5">
    <table class="table table-striped align-middle">
      <thead><tr><th>Logo</th><th>Ad</th><th>Oluşturulma</th><th class="text-end">İşlemler</th></tr></thead>
      <tbody>
        <?php foreach($companies as $c): ?>
        <tr>
          <td style="width:120px"><?php if($c['logo_path']): ?><img src="/<?= h($c['logo_path']) ?>" style="max-height:40px"><?php endif; ?></td>
          <td><?= h($c['company_name']) ?></td>
          <td><?= h($c['created_at']) ?></td>
          <td class="text-end">
            <form method="post" class="d-inline-flex gap-2">
              <input type="hidden" name="id" value="<?= h($c['id']) ?>">
              <input class="form-control form-control-sm" name="company_name" value="<?= h($c['company_name']) ?>" required>
              <input class="form-control form-control-sm" name="logo_path" value="<?= h($c['logo_path'] ?? '') ?>">
              <button class="btn btn-sm btn-primary" name="update_company">Güncelle</button>
              <button class="btn btn-sm btn-danger" name="delete_company" onclick="return confirm('Firma silinsin mi?')">Sil</button>
            </form>
          </td>
        </tr>
        <?php endforeach; ?>
        <?php if(empty($companies)): ?>
        <tr><td colspan="4" class="text-muted">Henüz firma yok.</td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>

  <!-- Firma Admini Oluştur -->
  <div class="card mb-4">
    <div class="card-body">
      <h5 class="mb-3">Yeni Firma Admini Oluştur (role='company')</h5>
      <form method="post" class="row g-2">
        <input type="hidden" name="create_company_admin" value="1">
        <div class="col-md-3"><input class="form-control" name="full_name" placeholder="Ad Soyad" required></div>
        <div class="col-md-3"><input type="email" class="form-control" name="email" placeholder="E-posta" required></div>
        <div class="col-md-3"><input type="password" class="form-control" name="password" placeholder="Geçici Şifre" required></div>
        <div class="col-md-2">
          <select class="form-select" name="company_id" required>
            <option value="" disabled selected>Firma seçin</option>
            <?php foreach($companies as $c): ?>
              <option value="<?= h($c['id']) ?>"><?= h($c['company_name']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-md-1"><button class="btn btn-success w-100">Ekle</button></div>
      </form>
      <small class="text-muted d-block mt-2">Oluşturulan kullanıcı <strong>role='company'</strong> olarak kaydedilir ve seçilen firmaya atanır.</small>
    </div>
  </div>

  <!-- Firma Adminleri Listesi -->
  <h5>Firma Adminleri</h5>
  <div class="table-responsive">
    <table class="table table-striped align-middle">
      <thead><tr><th>Ad Soyad</th><th>E-posta</th><th>Firma</th><th>Oluşturulma</th><th class="text-end">İşlemler</th></tr></thead>
      <tbody>
        <?php foreach($company_admins as $a): ?>
        <tr>
          <td><?= h($a['full_name']) ?></td>
          <td><?= h($a['email']) ?></td>
          <td><?= h($a['company_name'] ?? '(atanmamış)') ?></td>
          <td><?= h($a['created_at']) ?></td>
          <td class="text-end">
            <form method="post" class="d-inline-flex gap-2">
              <input type="hidden" name="user_id" value="<?= h($a['id']) ?>">
              <select class="form-select form-select-sm" name="company_id" style="width:240px" required>
                <?php foreach($companies as $c): ?>
                  <option value="<?= h($c['id']) ?>" <?= ($a['company_id'] ?? '')===$c['id']?'selected':'' ?>>
                    <?= h($c['company_name']) ?>
                  </option>
                <?php endforeach; ?>
              </select>
              <button class="btn btn-sm btn-primary" name="reassign_company_admin">Ata/Güncelle</button>
              <button class="btn btn-sm btn-danger" name="delete_company_admin" onclick="return confirm('Firma admini silinsin mi?')">Sil</button>
            </form>
          </td>
        </tr>
        <?php endforeach; ?>
        <?php if(empty($company_admins)): ?>
        <tr><td colspan="5" class="text-muted">Henüz firma admini yok.</td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>

</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
