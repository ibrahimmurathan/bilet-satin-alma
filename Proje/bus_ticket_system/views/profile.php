<?php
session_start();
require_once __DIR__ . '/../includes/db.php'; // $db (PDO)

/* ---------- Helper ---------- */
function h($s){ return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8'); }
function canCancelByTime($depIso){
    try {
        $now = new DateTime('now', new DateTimeZone('Europe/Istanbul'));
        $dep = new DateTime($depIso, new DateTimeZone('Europe/Istanbul'));
        return ($dep->getTimestamp() - $now->getTimestamp()) >= 3600;
    } catch (Exception $e) { return false; }
}

/* ---------- Erişim kontrolü: sadece user ve company ---------- */
if (!isset($_SESSION['user_id'])) { header('Location: login.php'); exit; }

/* Role yoksa DB'den çek ve cache'le */
if (!isset($_SESSION['user_role'])) {
    $sr = $db->prepare("SELECT role FROM User WHERE id = :id LIMIT 1");
    $sr->execute([':id' => $_SESSION['user_id']]);
    $_SESSION['user_role'] = $sr->fetchColumn() ?: 'user';
    $sr->closeCursor();
}
$role = $_SESSION['user_role'];
if (!in_array($role, ['user','company'], true)) { header('Location: home.php'); exit; }

/* ---------- Başarı/Hata mesajları ---------- */
$messages = [];
if (isset($_GET['purchased']) && $_GET['purchased'] == '1') {
    $messages[] = ['type'=>'success','text'=>'Biletiniz başarıyla satın alındı.'];
}
if (isset($_GET['cancelled'])) {
    $messages[] = ['type'=>'success','text'=>'Bilet iptal edildi, ücret bakiyenize eklendi.'];
}
if (isset($_GET['cancel_error'])) {
    $messages[] = ['type'=>'danger','text'=>'İptal başarısız: ' . h($_GET['cancel_error'])];
}

/* ---------- İşlemler: E-posta / Şifre Değiştirme ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Kullanıcıyı al
    $u = $db->prepare("SELECT id, email, password FROM User WHERE id = :id LIMIT 1");
    $u->execute([':id' => $_SESSION['user_id']]);
    $me = $u->fetch(PDO::FETCH_ASSOC);
    $u->closeCursor();
    if (!$me) { header('Location: login.php'); exit; }

    /* E-posta güncelle */
    if (isset($_POST['update_email'])) {
        $new_email = trim($_POST['new_email'] ?? '');
        if ($new_email === '' || !filter_var($new_email, FILTER_VALIDATE_EMAIL)) {
            $messages[] = ['type'=>'danger','text'=>'Geçerli bir e-posta girin.'];
        } else {
            $chk = $db->prepare("SELECT 1 FROM User WHERE email = :e AND id <> :id LIMIT 1");
            $chk->execute([':e'=>$new_email, ':id'=>$me['id']]);
            $exists = (bool)$chk->fetchColumn(); $chk->closeCursor();
            if ($exists) {
                $messages[] = ['type'=>'danger','text'=>'Bu e-posta başka bir hesapta kullanılıyor.'];
            } else {
                $up = $db->prepare("UPDATE User SET email = :e WHERE id = :id");
                $up->execute([':e'=>$new_email, ':id'=>$me['id']]);
                $up->closeCursor();
                header("Location: profile.php?ok=email"); exit;
            }
        }
    }

    /* Şifre güncelle */
    if (isset($_POST['update_password'])) {
        $current_password = $_POST['current_password'] ?? '';
        $new_password     = $_POST['new_password'] ?? '';
        $confirm_password = $_POST['confirm_password'] ?? '';

        if ($current_password === '' || $new_password === '' || $confirm_password === '') {
            $messages[] = ['type'=>'danger','text'=>'Lütfen tüm şifre alanlarını doldurun.'];
        } elseif (!password_verify($current_password, $me['password'])) {
            $messages[] = ['type'=>'danger','text'=>'Mevcut şifre hatalı.'];
        } elseif (strlen($new_password) < 6) {
            $messages[] = ['type'=>'danger','text'=>'Yeni şifre en az 6 karakter olmalı.'];
        } elseif ($new_password !== $confirm_password) {
            $messages[] = ['type'=>'danger','text'=>'Yeni şifre ve tekrar aynı olmalı.'];
        } else {
            $hashed = password_hash($new_password, PASSWORD_DEFAULT);
            $up = $db->prepare("UPDATE User SET password = :p WHERE id = :id");
            $up->execute([':p'=>$hashed, ':id'=>$me['id']]);
            $up->closeCursor();
            header("Location: profile.php?ok=pwd"); exit;
        }
    }
}

/* OK mesajları (PRG sonrası) */
if (isset($_GET['ok'])) {
    if ($_GET['ok'] === 'email') $messages[] = ['type'=>'success','text'=>'E-posta başarıyla güncellendi.'];
    if ($_GET['ok'] === 'pwd')   $messages[] = ['type'=>'success','text'=>'Şifre başarıyla güncellendi.'];
}

/* ---------- Kullanıcı bilgileri ---------- */
$u = $db->prepare("
    SELECT u.id, u.full_name, u.email, u.role, u.balance, u.company_id, bc.company_name
      FROM User u
 LEFT JOIN Bus_Company bc ON bc.id = u.company_id
     WHERE u.id = :id
     LIMIT 1
");
$u->execute([':id' => $_SESSION['user_id']]);
$user = $u->fetch(PDO::FETCH_ASSOC);
$u->closeCursor();
if (!$user) { header('Location: login.php'); exit; }

/* ---------- Kullanıcının biletleri (yalnız user görecek; koltuklar birleştirilmiş) ---------- */
$myTickets = [];
if ($role === 'user') {
    $ticketsStmt = $db->prepare("
        SELECT 
            t.id                AS ticket_id,
            t.status,
            t.total_price,
            t.created_at        AS bought_at,
            t.passenger_gender,
            tr.id               AS trip_id,
            tr.departure_city,
            tr.destination_city,
            tr.departure_time,
            tr.arrival_time,
            tr.price            AS trip_price,
            bc.company_name,
            (
                SELECT GROUP_CONCAT(bs.seat_number, ',')
                FROM Booked_Seats bs
                WHERE bs.ticket_id = t.id
            ) AS seats
        FROM Tickets t
        JOIN Trips tr            ON tr.id = t.trip_id
        LEFT JOIN Bus_Company bc ON bc.id = tr.company_id
        WHERE t.user_id = :uid
        ORDER BY datetime(t.created_at) DESC
    ");
    $ticketsStmt->execute([':uid' => $user['id']]);
    $myTickets = $ticketsStmt->fetchAll(PDO::FETCH_ASSOC);
    $ticketsStmt->closeCursor();
}

/* ---------- Company rolü için: Firma yolcu biletleri ---------- */
$companyTickets = [];
if ($role === 'company' && !empty($user['company_id'])) {
    $ct = $db->prepare("
        SELECT 
            t.id                AS ticket_id,
            t.status,
            t.total_price,
            t.created_at        AS bought_at,
            t.passenger_gender,
            tr.id               AS trip_id,
            tr.departure_city,
            tr.destination_city,
            tr.departure_time,
            tr.arrival_time,
            u.id                AS passenger_id,
            u.full_name         AS passenger_name,
            u.email             AS passenger_email,
            (
                SELECT GROUP_CONCAT(bs.seat_number, ',')
                FROM Booked_Seats bs
                WHERE bs.ticket_id = t.id
            ) AS seats
        FROM Tickets t
        JOIN Trips tr  ON tr.id = t.trip_id
        JOIN User  u   ON u.id = t.user_id
        WHERE tr.company_id = :cid
        ORDER BY datetime(t.created_at) DESC
    ");
    $ct->execute([':cid' => $user['company_id']]);
    $companyTickets = $ct->fetchAll(PDO::FETCH_ASSOC);
    $ct->closeCursor();
}
?>
<!doctype html>
<html lang="tr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Profilim</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<style>
.badge-gender-m { background:#0d6efd; } /* erkek mavi */
.badge-gender-f { background:#e83e8c; } /* kadın pembe */
</style>
</head>
<body class="bg-light">

<!-- Navbar -->
<nav class="navbar navbar-expand-lg bg-white shadow-sm">
  <div class="container">
    <a class="navbar-brand fw-bold" href="home.php">Tripin</a>

    <div class="d-flex align-items-center gap-2 ms-auto">
      <?php if($role === 'company'): ?>
        <a href="../company/company_dashboard.php" class="btn btn-outline-success">Firma Paneli</a>
      <?php endif; ?>

      <div class="dropdown">
        <button class="btn btn-outline-secondary dropdown-toggle" data-bs-toggle="dropdown">
          <?= h($_SESSION['user_name'] ?? $user['full_name'] ?? 'Hesabım') ?>
        </button>
        <ul class="dropdown-menu dropdown-menu-end">
          <li><a class="dropdown-item active" href="profile.php">Profil</a></li>
          <li><hr class="dropdown-divider"></li>
          <li><a class="dropdown-item text-danger" href="../logout.php">Çıkış Yap</a></li>
        </ul>
      </div>
    </div>
  </div>
</nav>

<div class="container py-4">

  <?php foreach ($messages as $m): ?>
    <div class="alert alert-<?= h($m['type']) ?>"><?= h($m['text']) ?></div>
  <?php endforeach; ?>

  <div class="row g-3">
    <!-- Sol: Profil Kartı + E-posta/Şifre -->
    <div class="col-lg-4">
      <div class="card shadow-sm mb-3">
        <div class="card-body">
          <h5 class="card-title mb-3">Profil Bilgileri</h5>
          <div class="mb-2"><strong>Ad Soyad:</strong> <?= h($user['full_name']) ?></div>
          <div class="mb-2"><strong>E-posta:</strong> <?= h($user['email']) ?></div>
          <div class="mb-2">
            <strong>Rol:</strong>
            <span class="badge bg-secondary"><?= h($user['role']) ?></span>
          </div>
          <?php if($role === 'company'): ?>
            <div class="mb-2">
              <strong>Firma:</strong> <?= h($user['company_name'] ?? '(atanmamış)') ?>
            </div>
          <?php endif; ?>
          <?php if($role === 'user'): ?>
            <hr>
            <div class="mb-2">
              <strong>Kredi (Balance):</strong>
              <span class="badge bg-success"><?= h(number_format((float)$user['balance'], 2, ',', '.')) ?> ₺</span>
            </div>
          <?php endif; ?>
        </div>
      </div>

      <!-- E-posta Değiştir -->
      <div class="card shadow-sm mb-3">
        <div class="card-body">
          <h6 class="card-title">E-posta Değiştir</h6>
          <form method="post">
            <input type="hidden" name="update_email" value="1">
            <div class="mb-2">
              <label class="form-label">Yeni E-posta</label>
              <input type="email" class="form-control" name="new_email" placeholder="ornek@mail.com" required>
            </div>
            <button class="btn btn-primary w-100" type="submit">E-postayı Güncelle</button>
          </form>
        </div>
      </div>

      <!-- Şifre Değiştir -->
      <div class="card shadow-sm">
        <div class="card-body">
          <h6 class="card-title">Şifre Değiştir</h6>
          <form method="post">
            <input type="hidden" name="update_password" value="1">
            <div class="mb-2">
              <label class="form-label">Mevcut Şifre</label>
              <input type="password" class="form-control" name="current_password" required>
            </div>
            <div class="mb-2">
              <label class="form-label">Yeni Şifre</label>
              <input type="password" class="form-control" name="new_password" minlength="6" required>
            </div>
            <div class="mb-3">
              <label class="form-label">Yeni Şifre (Tekrar)</label>
              <input type="password" class="form-control" name="confirm_password" minlength="6" required>
            </div>
            <button class="btn btn-warning w-100" type="submit">Şifreyi Güncelle</button>
          </form>
        </div>
      </div>
    </div>

    <!-- Sağ panel -->
    <div class="col-lg-8">

      <?php if($role === 'user'): ?>
      <!-- Kullanıcı Biletleri (Yalnızca USER) -->
      <div class="card shadow-sm mb-4">
        <div class="card-body">
          <div class="d-flex justify-content-between align-items-center mb-3">
            <h5 class="card-title mb-0">Biletlerim</h5>
            <a href="home.php" class="btn btn-sm btn-outline-primary">Yeni Bilet Al</a>
          </div>

          <div class="table-responsive">
            <table class="table table-striped align-middle">
              <thead>
                <tr>
                  <th>#</th>
                  <th>Sefer</th>
                  <th>Firma</th>
                  <th>Koltuk(lar)</th>
                  <th>Fiyat</th>
                  <th>Durum</th>
                  <th>Satın Alma</th>
                  <th>İşlem</th>
                </tr>
              </thead>
              <tbody>
                <?php if(empty($myTickets)): ?>
                  <tr><td colspan="8" class="text-muted">Henüz biletiniz yok.</td></tr>
                <?php else: ?>
                  <?php foreach($myTickets as $i => $t): 
                        $isActive = strtolower(trim($t['status'])) === 'active';
                        $canCancel = $isActive && canCancelByTime($t['departure_time']);
                  ?>
                  <tr>
                    <td><?= $i+1 ?></td>
                    <td>
                      <div class="small fw-semibold">
                        <?= h($t['departure_city']) ?> → <?= h($t['destination_city']) ?>
                      </div>
                      <div class="small text-muted">
                        Kalkış: <?= h($t['departure_time']) ?> | Varış: <?= h($t['arrival_time']) ?>
                      </div>
                    </td>
                    <td><?= h($t['company_name'] ?? '-') ?></td>
                    <td>
                      <?= h($t['seats'] ?: '-') ?>
                      <?php if($t['passenger_gender']==='male'): ?>
                        <span class="badge badge-gender-m ms-1">Erkek</span>
                      <?php elseif($t['passenger_gender']==='female'): ?>
                        <span class="badge badge-gender-f ms-1">Kadın</span>
                      <?php endif; ?>
                    </td>
                    <td><?= h($t['total_price']) ?> ₺</td>
                    <td>
                      <?php
                        $badge = 'secondary';
                        if ($t['status']==='active')    $badge='success';
                        if ($t['status']==='cancelled') $badge='danger';
                        if ($t['status']==='expired')   $badge='dark';
                      ?>
                      <span class="badge bg-<?= $badge ?>"><?= h($t['status']) ?></span>
                    </td>
                    <td class="small"><?= h($t['bought_at']) ?></td>

                    <!-- PDF sadece USER rolüne; iptal işleyişi aynen -->
                    <td class="d-flex gap-1">
                      <a class="btn btn-sm btn-outline-secondary"
                         href="ticket_detail.php?ticket_id=<?= h($t['ticket_id']) ?>&pdf=1"
                         target="_blank">PDF</a>

                      <?php if ($canCancel): ?>
                      <form method="post" action="cancel_ticket.php"
                            onsubmit="return confirm('Bu bileti iptal etmek istediğinize emin misiniz?');">
                        <input type="hidden" name="ticket_id" value="<?= h($t['ticket_id']) ?>">
                        <button type="submit" class="btn btn-sm btn-danger">İptal Et</button>
                      </form>
                      <?php endif; ?>
                    </td>
                    <!-- /PDF-User -->

                  </tr>
                  <?php endforeach; ?>
                <?php endif; ?>
              </tbody>
            </table>
          </div>

          <small class="text-muted d-block mt-2">
            Not: “PDF” bağlantısı <code>ticket_detail.php?ticket_id=...&pdf=1</code> ile PDF çıktısı üretir.
          </small>
        </div>
      </div>
      <?php endif; ?>

      <?php if($role === 'company'): ?>
      <!-- Firma Yolcu Biletleri (Yalnızca COMPANY) -->
      <div class="card shadow-sm">
        <div class="card-body">
          <h5 class="card-title mb-3">Firma Yolcu Biletleri</h5>

          <div class="table-responsive">
            <table class="table table-striped align-middle">
              <thead>
                <tr>
                  <th>#</th>
                  <th>Yolcu</th>
                  <th>Sefer</th>
                  <th>Koltuk(lar)</th>
                  <th>Fiyat</th>
                  <th>Durum</th>
                  <th>Satın Alma</th>
                  <th>İşlem</th>
                </tr>
              </thead>
              <tbody>
                <?php if(empty($companyTickets)): ?>
                  <tr><td colspan="8" class="text-muted">Firmanıza ait seferlerde bilet bulunamadı.</td></tr>
                <?php else: ?>
                  <?php foreach($companyTickets as $i => $t): 
                        $isActive = strtolower(trim($t['status'])) === 'active';
                        $canCancel = $isActive && canCancelByTime($t['departure_time']);
                  ?>
                  <tr>
                    <td><?= $i+1 ?></td>
                    <td>
                      <div class="small fw-semibold"><?= h($t['passenger_name']) ?></div>
                      <div class="small text-muted"><?= h($t['passenger_email']) ?></div>
                    </td>
                    <td>
                      <div class="small fw-semibold">
                        <?= h($t['departure_city']) ?> → <?= h($t['destination_city']) ?>
                      </div>
                      <div class="small text-muted">
                        Kalkış: <?= h($t['departure_time']) ?> | Varış: <?= h($t['arrival_time']) ?>
                      </div>
                    </td>
                    <td><?= h($t['seats'] ?: '-') ?></td>
                    <td><?= h($t['total_price']) ?> ₺</td>
                    <td>
                      <?php
                        $badge = 'secondary';
                        if ($t['status']==='active')    $badge='success';
                        if ($t['status']==='cancelled') $badge='danger';
                        if ($t['status']==='expired')   $badge='dark';
                      ?>
                      <span class="badge bg-<?= $badge ?>"><?= h($t['status']) ?></span>
                    </td>
                    <td class="small"><?= h($t['bought_at']) ?></td>
                    <td class="d-flex gap-1">
                      <!-- PDF GÖSTERİLMEZ; yalnız iptal uygunsa buton -->
                      <?php if ($canCancel): ?>
                      <form method="post" action="cancel_ticket.php"
                            onsubmit="return confirm('Bu bileti iptal etmek istiyor musunuz?');">
                        <input type="hidden" name="ticket_id" value="<?= h($t['ticket_id']) ?>">
                        <button type="submit" class="btn btn-sm btn-danger">İptal Et</button>
                      </form>
                      <?php endif; ?>
                    </td>
                  </tr>
                  <?php endforeach; ?>
                <?php endif; ?>
              </tbody>
            </table>
          </div>

          <small class="text-muted d-block mt-2">
            Not: Firma yetkilisi olarak, yalnızca firmanıza ait seferlerdeki aktif ve kalkışına ≥ 1 saat olan biletleri iptal edebilirsiniz.
          </small>
        </div>
      </div>
      <?php endif; ?>

    </div>
  </div>

</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
