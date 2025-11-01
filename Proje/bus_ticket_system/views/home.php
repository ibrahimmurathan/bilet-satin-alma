<?php
session_start();
require_once __DIR__ . '/../includes/db.php'; // $db (PDO, SQLite)

// -----------------------------------------------------------------------------
// Helper
// -----------------------------------------------------------------------------
function h($s) { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }
function applyDiscount($amount, $percent){
    $p = max(0, min(100, (float)$percent));
    return (int) round($amount * (100 - $p) / 100);
}
$messages = [];

/* ---------- POST: Bilet Alma (+ Kupon + Bakiye Düşümü) ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['book_seat'])) {
    if (!isset($_SESSION['user_id'])) {
        header('Location: login.php');
        exit;
    }

    // Rol cache
    if (!isset($_SESSION['user_role'])) {
        $stmtR = $db->prepare("SELECT role FROM User WHERE id = :id LIMIT 1");
        $stmtR->execute([':id' => $_SESSION['user_id']]);
        $_SESSION['user_role'] = $stmtR->fetchColumn() ?: 'user';
        $stmtR->closeCursor();
    }

    if ($_SESSION['user_role'] !== 'user') {
        $messages[] = ['type'=>'danger','text'=>'Bilet satın alma yetkiniz yok. (Sadece Yolcu kullanıcıları bilet alabilir)'];
    } else {
        $trip_id     = $_POST['trip_id'] ?? '';
        $seat_number = isset($_POST['seat_number']) ? (int)$_POST['seat_number'] : 0;
        $gender      = $_POST['gender'] ?? '';
        $coupon_code = trim($_POST['coupon_code'] ?? '');
        $user_id     = $_SESSION['user_id'];

        if (empty($trip_id) || $seat_number < 1 || $seat_number > 40 || !in_array($gender, ['male','female'], true)) {
            $messages[] = ['type'=>'danger','text'=>'Geçersiz talep. Lütfen tekrar deneyin.'];
        } else {

            $attempts = 0;
            $maxAttempts = 2;

            while ($attempts < $maxAttempts) {
                $attempts++;

                $stmt = $insertTicket = $insertSeat = $tmp = $stmtC = $stmtBal = $stmtUpdBal = null;
                try {
                    // Erken yazma kilidi
                    $db->exec('BEGIN IMMEDIATE');

                    // 1) Sefer
                    $stmt = $db->prepare("SELECT id, price, company_id FROM Trips WHERE id = :id LIMIT 1");
                    $stmt->execute([':id'=>$trip_id]);
                    $trip = $stmt->fetch(PDO::FETCH_ASSOC);
                    $stmt->closeCursor(); $stmt = null;
                    if (!$trip) { throw new Exception("Sefer bulunamadı."); }

                    // 2) Koltuk dolu mu?
                    $stmt = $db->prepare("
                        SELECT COUNT(*)
                        FROM Booked_Seats bs
                        JOIN Tickets t ON t.id = bs.ticket_id
                        WHERE t.trip_id = :trip_id
                          AND bs.seat_number = :seat_number
                          AND lower(trim(t.status)) = 'active'
                    ");
                    $stmt->execute([':trip_id'=>$trip_id, ':seat_number'=>$seat_number]);
                    $exists = (int)$stmt->fetchColumn();
                    $stmt->closeCursor(); $stmt = null;
                    if ($exists > 0) { throw new Exception("Seçtiğiniz koltuk dolu."); }

                    // 3) Kupon (opsiyonel)
                    $total_price = (int)$trip['price'];
                    $couponRow   = null;

                    if ($coupon_code !== '') {
                        // a) Kuponu çek (aynı firmaya ait veya global/NULL)
                        $stmtC = $db->prepare("
                            SELECT id, code, discount, usage_limit, expire_date, company_id
                            FROM Coupons
                            WHERE code = :code
                              AND (company_id = :cid OR company_id IS NULL)
                            LIMIT 1
                        ");
                        $stmtC->execute([':code'=>$coupon_code, ':cid'=>$trip['company_id']]);
                        $couponRow = $stmtC->fetch(PDO::FETCH_ASSOC);
                        $stmtC->closeCursor(); $stmtC = null;

                        if (!$couponRow) { throw new Exception("Kupon bulunamadı veya bu firma için geçerli değil."); }

                        // b) Tarih kontrolü
                        $stmt = $db->prepare("SELECT CASE WHEN date(:exp) >= date('now','localtime') THEN 1 ELSE 0 END");
                        $stmt->execute([':exp' => $couponRow['expire_date']]);
                        $notExpired = (int)$stmt->fetchColumn() === 1;
                        $stmt->closeCursor(); $stmt = null;
                        if (!$notExpired) { throw new Exception("Kuponun süresi dolmuş."); }

                        // c) Toplam kullanım limiti
                        $stmt = $db->prepare("SELECT COUNT(*) FROM User_Coupons WHERE coupon_id = :cid");
                        $stmt->execute([':cid' => $couponRow['id']]);
                        $usedCount = (int)$stmt->fetchColumn();
                        $stmt->closeCursor(); $stmt = null;

                        $limit = (int)$couponRow['usage_limit'];
                        if ($limit > 0 && $usedCount >= $limit) {
                            throw new Exception("Kupon kullanım limiti dolmuş.");
                        }

                        // d) Aynı kullanıcı tarafından daha önce kullanılmış mı?
                        $stmt = $db->prepare("SELECT COUNT(*) FROM User_Coupons WHERE coupon_id = :cid AND user_id = :uid");
                        $stmt->execute([':cid' => $couponRow['id'], ':uid' => $user_id]);
                        $alreadyUsedByUser = (int)$stmt->fetchColumn() > 0;
                        $stmt->closeCursor(); $stmt = null;
                        if ($alreadyUsedByUser) {
                            throw new Exception("Bu kuponu daha önce kullandınız.");
                        }

                        // e) İndirim uygula
                        $discount = max(0, min(100, (float)$couponRow['discount']));
                        $total_price = (int) round($total_price * (100 - $discount) / 100);
                        if ($total_price < 0) { $total_price = 0; }
                    }

                    // 4) Bakiye kontrolü & düşümü (sadece total_price > 0 ise)
if ($total_price > 0) {
    // Kullanıcının güncel bakiyesini oku (TRANS. içinde güvenli)
    $stmtBal = $db->prepare("SELECT COALESCE(balance,0) FROM User WHERE id = :uid LIMIT 1");
    $stmtBal->execute([':uid' => $user_id]);
    $balance = (float)$stmtBal->fetchColumn();
    $stmtBal->closeCursor(); $stmtBal = null;

    if ($balance < $total_price) {
        throw new Exception("Yetersiz bakiye. Gerekli: {$total_price} ₺, Bakiye: {$balance} ₺");
    }

    // Koşulsuz düş (BEGIN IMMEDIATE olduğundan yarış yok)
    $stmtUpdBal = $db->prepare("
        UPDATE User
           SET balance = CAST(COALESCE(balance,0) AS REAL) - :amt
         WHERE id = :uid
    ");
    $stmtUpdBal->execute([':amt' => (float)$total_price, ':uid' => $user_id]);
    $stmtUpdBal->closeCursor(); $stmtUpdBal = null;
}


                    // 5) Ticket insert — UUID'yi al
                    $ticket_id = null;
                    try {
                        $insertTicket = $db->prepare("
                            INSERT INTO Tickets (status, total_price, trip_id, user_id, passenger_gender)
                            VALUES ('active', :total_price, :trip_id, :user_id, :passenger_gender)
                            RETURNING id
                        ");
                        $insertTicket->execute([
                            ':total_price'      => $total_price,
                            ':trip_id'          => $trip_id,
                            ':user_id'          => $user_id,
                            ':passenger_gender' => $gender
                        ]);
                        $ticket_id = $insertTicket->fetchColumn();
                        $insertTicket->closeCursor(); $insertTicket = null;
                    } catch (Throwable $e) {
                        $insertTicket = $db->prepare("
                            INSERT INTO Tickets (status, total_price, trip_id, user_id, passenger_gender)
                            VALUES ('active', :total_price, :trip_id, :user_id, :passenger_gender)
                        ");
                        $insertTicket->execute([
                            ':total_price'      => $total_price,
                            ':trip_id'          => $trip_id,
                            ':user_id'          => $user_id,
                            ':passenger_gender' => $gender
                        ]);
                        $insertTicket->closeCursor(); $insertTicket = null;

                        $tmp = $db->query("SELECT id FROM Tickets WHERE rowid = last_insert_rowid()");
                        $ticket_id = $tmp->fetchColumn();
                        $tmp->closeCursor(); $tmp = null;
                    }
                    if (!$ticket_id) { throw new Exception("Bilet ID alınamadı."); }

                    // 6) Koltuk kaydı
                    $insertSeat = $db->prepare("
                        INSERT INTO Booked_Seats (seat_number, ticket_id)
                        VALUES (:seat_number, :ticket_id)
                    ");
                    $insertSeat->execute([
                        ':seat_number' => $seat_number,
                        ':ticket_id'   => $ticket_id
                    ]);
                    $insertSeat->closeCursor(); $insertSeat = null;

                    // 7) Kupon kullanımı kaydı (varsa)
                    if (!empty($couponRow)) {
                        $stmt = $db->prepare("
                            INSERT INTO User_Coupons (coupon_id, user_id)
                            VALUES (:cid, :uid)
                        ");
                        $stmt->execute([':cid' => $couponRow['id'], ':uid' => $user_id]);
                        $stmt->closeCursor(); $stmt = null;
                    }

                    // 8) Commit & yönlendir
                    $db->exec('COMMIT');
                    header("Location: profile.php?purchased=1");
                    exit;

                } catch (PDOException $e) {
                    if ($stmt)        { $stmt->closeCursor(); }
                    if ($stmtC)       { $stmtC->closeCursor(); }
                    if ($insertTicket){ $insertTicket->closeCursor(); }
                    if ($insertSeat)  { $insertSeat->closeCursor(); }
                    if ($stmtBal)     { $stmtBal->closeCursor(); }
                    if ($stmtUpdBal)  { $stmtUpdBal->closeCursor(); }
                    if ($tmp)         { $tmp->closeCursor(); }
                    if ($db->inTransaction()) { $db->exec('ROLLBACK'); }

                    $msg = $e->getMessage();
                    if (stripos($msg, 'database is locked') !== false && $attempts < $maxAttempts) {
                        usleep(150000);
                        continue;
                    }
                    $messages[] = ['type'=>'danger','text'=>'Rezervasyon hatası: ' . h($msg)];
                    break;

                } catch (Exception $e) {
                    if ($stmt)        { $stmt->closeCursor(); }
                    if ($stmtC)       { $stmtC->closeCursor(); }
                    if ($insertTicket){ $insertTicket->closeCursor(); }
                    if ($insertSeat)  { $insertSeat->closeCursor(); }
                    if ($stmtBal)     { $stmtBal->closeCursor(); }
                    if ($stmtUpdBal)  { $stmtUpdBal->closeCursor(); }
                    if ($tmp)         { $tmp->closeCursor(); }
                    if ($db->inTransaction()) { $db->exec('ROLLBACK'); }

                    $messages[] = ['type'=>'danger','text'=>'Rezervasyon hatası: ' . h($e->getMessage())];
                    break;
                }
            }
        }
    }
}

// -----------------------------------------------------------------------------
// Filtreleme
// -----------------------------------------------------------------------------
$filter_from = $_GET['from'] ?? '';
$filter_to   = $_GET['to'] ?? '';
$filter_date = $_GET['date'] ?? '';

$where = [];
$params = [];
if (!empty($filter_from)) { $where[] = 'departure_city = :from';          $params[':from']=$filter_from; }
if (!empty($filter_to))   { $where[] = 'destination_city = :to';          $params[':to']=$filter_to; }
if (!empty($filter_date)) { $where[] = "date(departure_time) = :date";    $params[':date']=$filter_date; }
$where_sql = count($where) ? 'WHERE ' . implode(' AND ', $where) : '';

$sql = "
    SELECT t.*, bc.company_name, bc.logo_path
    FROM Trips t
    LEFT JOIN Bus_Company bc ON t.company_id = bc.id
    $where_sql
    ORDER BY departure_time ASC
";
$stmt = $db->prepare($sql);
$stmt->execute($params);
$trips = $stmt->fetchAll(PDO::FETCH_ASSOC);

// -----------------------------------------------------------------------------
// Dropdown şehirler
// -----------------------------------------------------------------------------
$departureCities   = $db->query("SELECT DISTINCT departure_city FROM Trips ORDER BY departure_city ASC")->fetchAll(PDO::FETCH_COLUMN);
$destinationCities = $db->query("SELECT DISTINCT destination_city FROM Trips ORDER BY destination_city ASC")->fetchAll(PDO::FETCH_COLUMN);

// -----------------------------------------------------------------------------
// Modal Trip ve koltuklar
// -----------------------------------------------------------------------------
$modalTrip   = null;
$bookedSeats = [];

if (isset($_GET['show_seats']) && isset($_GET['trip_id'])) {
    $trip_id = $_GET['trip_id'];

    // 1) Sefer bilgisi
    $s = $db->prepare("
        SELECT t.id, t.departure_city, t.destination_city, t.price, t.departure_time, t.arrival_time
        FROM Trips t
        WHERE t.id = :id
        LIMIT 1
    ");
    $s->execute([':id' => $trip_id]);
    $modalTrip = $s->fetch(PDO::FETCH_ASSOC);

    if ($modalTrip) {
        // 2) Dolu koltuklar (sadece aktif biletler)
        $q = $db->prepare("
            SELECT bs.seat_number, t.passenger_gender, bs.ticket_id
            FROM Booked_Seats bs
            JOIN Tickets t ON t.id = bs.ticket_id
            WHERE t.trip_id = :trip_id
              AND lower(trim(t.status)) = 'active'
        ");
        $q->execute([':trip_id' => $trip_id]);

        while ($r = $q->fetch(PDO::FETCH_ASSOC)) {
            $bookedSeats[(int)$r['seat_number']] = [
                'gender'    => $r['passenger_gender'],
                'ticket_id' => $r['ticket_id']
            ];
        }
    } else {
        $messages[] = ['type'=>'warning','text'=>'Sefer bulunamadı.'];
    }
}
?>
<!doctype html>
<html lang="tr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Otobüs Seferleri</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<style>
.bg-pink { background-color: #e83e8c !important; }
.seat { width:56px;height:56px;display:inline-flex;align-items:center;justify-content:center;margin:6px;border-radius:6px;cursor:pointer;border:1px solid #ccc; }
.seat.is-disabled { cursor:not-allowed; opacity:0.85; pointer-events: none; }
.seat-legend .seat { width:36px;height:36px;margin:4px; }
@media (max-width:576px) { .seat { width:44px;height:44px;margin:4px;font-size:12px; } }
</style>
</head>
<body class="bg-light">

<nav class="navbar navbar-expand-lg navbar-white bg-white shadow-sm">
  <div class="container">
    <a class="navbar-brand fw-bold" href="#">Otobüs Seferleri</a>
    <div class="d-flex align-items-center">
    <?php if(isset($_SESSION['user_id'])): ?>
        <?php
        if(!isset($_SESSION['user_role'])) {
            $stmtRole = $db->prepare("SELECT role FROM User WHERE id = :id LIMIT 1");
            $stmtRole->execute([':id'=>$_SESSION['user_id']]);
            $_SESSION['user_role'] = $stmtRole->fetchColumn() ?: 'user';
        }
        if($_SESSION['user_role'] === 'company'): ?>
            <a href="../company/company_dashboard.php" class="btn btn-outline-success me-2">Firma Paneli</a>
        <?php elseif($_SESSION['user_role'] === 'admin'): ?>
            <a href="../admin/admin_dashboard.php" class="btn btn-outline-danger me-2">Admin Paneli</a>
        <?php endif; ?>

        <div class="dropdown">
          <button class="btn btn-outline-secondary dropdown-toggle" type="button" id="userDropdown" data-bs-toggle="dropdown" aria-expanded="false">
            <?= h($_SESSION['user_name'] ?? 'Kullanıcı') ?>
          </button>
          <ul class="dropdown-menu dropdown-menu-end" aria-labelledby="userDropdown">
            <li><a class="dropdown-item" href="profile.php">Profil</a></li>
            <li><hr class="dropdown-divider"></li>
            <li><a class="dropdown-item" href="../logout.php">Çıkış Yap</a></li>
          </ul>
        </div>
    <?php else: ?>
        <a href="login.php" class="btn btn-outline-primary">Giriş Yap</a>
    <?php endif; ?>
    </div>
  </div>
</nav>

<div class="container my-4">
  <?php foreach($messages as $m): ?>
    <div class="alert alert-<?= h($m['type']) ?>"><?= h($m['text']) ?></div>
  <?php endforeach; ?>

  <!-- Filtre form -->
  <div class="card mb-4 shadow-sm">
    <div class="card-body">
      <form method="get" class="row g-2 align-items-end">
        <div class="col-md-3">
          <label class="form-label">Kalkış (From)</label>
          <select class="form-select" name="from">
            <option value="">Tümü</option>
            <?php foreach($departureCities as $c): ?>
            <option value="<?= h($c) ?>" <?= $filter_from === $c ? 'selected' : '' ?>><?= h($c) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-md-3">
          <label class="form-label">Varış (To)</label>
          <select class="form-select" name="to">
            <option value="">Tümü</option>
            <?php foreach($destinationCities as $c): ?>
            <option value="<?= h($c) ?>" <?= $filter_to === $c ? 'selected' : '' ?>><?= h($c) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-md-3">
          <label class="form-label">Tarih</label>
          <input type="date" class="form-control" name="date" value="<?= h($filter_date) ?>">
        </div>
        <div class="col-md-3">
          <button type="submit" class="btn btn-primary w-100">Filtrele</button>
        </div>
      </form>
    </div>
  </div>

  <!-- Trips kartlar -->
  <div class="row g-3">
    <?php foreach($trips as $trip): ?>
    <div class="col-md-6 col-lg-4">
      <div class="card shadow-sm h-100">
        <?php
          $logo = $trip['logo_path'] ?? '';
          $logoSrc = $logo ? '/'.ltrim($logo,'/') : 'https://via.placeholder.com/300x140?text=Logo';
        ?>
        <img src="<?= h($logoSrc) ?>" class="card-img-top" alt="<?= h($trip['company_name'] ?? '') ?>" style="height:140px;object-fit:contain;">
        <div class="card-body">
          <h5 class="card-title"><?= h($trip['departure_city']) ?> → <?= h($trip['destination_city']) ?></h5>
          <p class="card-text mb-1"><strong>Firma:</strong> <?= h($trip['company_name'] ?? '-') ?></p>
          <p class="card-text mb-1"><strong>Fiyat:</strong> <?= h($trip['price']) ?> ₺</p>
          <p class="card-text mb-1"><strong>Kalkış:</strong> <?= h($trip['departure_time']) ?></p>
          <p class="card-text mb-1"><strong>Varış:</strong> <?= h($trip['arrival_time']) ?></p>
          <a href="?trip_id=<?= h($trip['id']) ?>&show_seats=1" class="btn btn-outline-primary w-100">Koltukları Gör</a>
        </div>
      </div>
    </div>
    <?php endforeach; ?>
  </div>
</div>

<!-- Koltuk modal -->
<?php if($modalTrip): ?>
<div class="modal fade" id="seatModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title"><?= h($modalTrip['departure_city']) ?> → <?= h($modalTrip['destination_city']) ?> Koltuklar</h5>
        <a href="home.php" class="btn-close"></a>
      </div>
      <div class="modal-body">
        <form method="post">
          <input type="hidden" name="trip_id" value="<?= h($modalTrip['id']) ?>">

          <div class="row">
            <div class="col-lg-8">
              <p>Koltuk seçiniz (2+2 düzen, 40 koltuk):</p>
              <div class="d-flex flex-wrap justify-content-center">
                <?php
                for($i=1;$i<=40;$i++){
                    $seatClass='seat';
                    $extra='';
                    $color='';
                    if(isset($bookedSeats[$i])){
                        $gender = $bookedSeats[$i]['gender'];
                        $color  = $gender==='female' ? 'bg-pink text-white' : 'bg-primary text-white';
                        $extra  = 'is-disabled';
                    }
                    echo "<div class='{$seatClass} {$color} {$extra}' data-seat='{$i}' tabindex='0' role='button' aria-disabled='".($extra?'true':'false')."'>{$i}</div>";
                    if($i%4==0) echo "<div class='w-100'></div>";
                }
                ?>
              </div>
              <input type="hidden" name="seat_number" id="selectedSeat">
            </div>

            <div class="col-lg-4">
              <div class="mb-3">
                <label class="form-label">Cinsiyet</label>
                <select class="form-select" name="gender" id="genderSelect" required>
                  <option value="">Seçiniz</option>
                  <option value="male">Erkek</option>
                  <option value="female">Kadın</option>
                </select>
              </div>

              <div class="mb-3">
                <label class="form-label">Kupon Kodu (opsiyonel)</label>
                <input type="text" class="form-control" name="coupon_code" placeholder="Örn: TRIPIN10">
                <small class="text-muted">Global veya bu firmaya ait geçerli kupon girilebilir.</small>
              </div>

              <button type="submit" name="book_seat" class="btn btn-success w-100">Bileti Al</button>
            </div>
          </div>

        </form>
      </div>
    </div>
  </div>
</div>
<?php endif; ?>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>

<?php if($modalTrip): ?>
<script>
document.addEventListener('DOMContentLoaded', function(){
  var modalEl = document.getElementById('seatModal');
  if(modalEl){
    var seatModal = new bootstrap.Modal(modalEl);
    seatModal.show();

    document.querySelectorAll('.seat').forEach(function(s){
      s.addEventListener('click', function(){
        if (this.classList.contains('is-disabled')) return;
        document.querySelectorAll('.seat').forEach(el => el.classList.remove('bg-secondary'));
        this.classList.add('bg-secondary');
        document.getElementById('selectedSeat').value = this.dataset.seat;
      });
    });
  }
});
</script>
<?php endif; ?>
</body>
</html>
