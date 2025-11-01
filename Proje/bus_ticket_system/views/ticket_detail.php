<?php
// views/ticket_detail.php
session_start();
require_once __DIR__ . '/../includes/db.php';

// ---------------------- Yardımcılar ----------------------
function h($s){ return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8'); }

function fmtDateTR($iso){
    if (empty($iso)) return '-';
    try {
        $dt = new DateTime($iso, new DateTimeZone('Europe/Istanbul'));
        return $dt->format('d.m.Y H:i');
    } catch (Exception $e) { return $iso; }
}

function fmtTL($n){
    $n = (float)$n;
    if (fmod($n,1.0) == 0.0) return number_format($n, 0, ',', '.') . ' TL';
    return number_format($n, 2, ',', '.') . ' TL';
}

// ---------------------- Giriş/Yetki ----------------------
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}
$userRole = $_SESSION['user_role'] ?? ($_SESSION['role'] ?? 'user');

$ticket_id = $_GET['ticket_id'] ?? '';
$is_pdf    = isset($_GET['pdf']) && $_GET['pdf'] == '1';
if (empty($ticket_id)) {
    http_response_code(400);
    exit('Geçersiz bilet isteği.');
}

// ---------------------- Bilet + Trip + Company + User ----------------------
$stmt = $db->prepare("
    SELECT 
        t.id               AS ticket_id,
        t.status,
        t.total_price,
        t.passenger_gender,
        t.created_at       AS ticket_created,
        tr.id              AS trip_id,
        tr.departure_city,
        tr.destination_city,
        tr.departure_time,
        tr.arrival_time,
        tr.price           AS trip_price,
        tr.capacity,
        bc.company_name,
        bc.logo_path,
        u.id               AS user_id,
        u.full_name,
        u.email
    FROM Tickets t
    JOIN Trips tr            ON t.trip_id = tr.id
    LEFT JOIN Bus_Company bc ON tr.company_id = bc.id
    JOIN User u              ON t.user_id = u.id
    WHERE t.id = :tid
    LIMIT 1
");
$stmt->execute([':tid' => $ticket_id]);
$ticket = $stmt->fetch(PDO::FETCH_ASSOC);
$stmt->closeCursor();

if (!$ticket) {
    http_response_code(404);
    exit('Bilet bulunamadı.');
}

// Sahibiyet / Rol kontrolü
$isOwner = ($ticket['user_id'] === ($_SESSION['user_id'] ?? ''));
if (!$isOwner && !in_array($userRole, ['admin','company'], true)) {
    http_response_code(403);
    exit('Bu bilete erişim yetkiniz yok.');
}

// ---------------------- Koltuklar ----------------------
$stmt = $db->prepare("
    SELECT seat_number
    FROM Booked_Seats
    WHERE ticket_id = :tid
    ORDER BY seat_number ASC
");
$stmt->execute([':tid' => $ticket_id]);
$seats = $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [];
$stmt->closeCursor();

// ---------------------- PDF Akışı ----------------------
if ($is_pdf) {
    // Font yolu garanti — FPDF, libs/font altını kullansın
    if (!defined('FPDF_FONTPATH')) {
        define('FPDF_FONTPATH', __DIR__ . '/../libs/font/');
    }
    // PHP 8 uyumu: magic_quotes fonksiyonları yoksa stub
    if (!function_exists('get_magic_quotes_runtime')) {
        function get_magic_quotes_runtime(){ return false; }
    }
    if (!function_exists('set_magic_quotes_runtime')) {
        function set_magic_quotes_runtime($v){ return false; }
    }

    $fpdfPath = __DIR__ . '/../libs/fpdf.php';
    if (!file_exists($fpdfPath)) {
        header('Content-Type: text/plain; charset=UTF-8');
        http_response_code(500);
        echo "FPDF bulunamadı. Lütfen 'libs/fpdf.php' ve 'libs/font/' klasörünü ekleyiniz.";
        exit;
    }
    require_once $fpdfPath;

    // FPDF ANSI beklentisi nedeniyle basit dönüştürücü (utf8 → latin1)
    $toTR = function($s){ return utf8_decode((string)$s); };

    $koltukLabel = (count($seats) === 1) ? 'Koltuk No' : 'Koltuklar';
    $seatText    = $seats ? implode(', ', array_map('intval', $seats)) : '-';
    $pnr         = strtoupper(substr(sha1($ticket['ticket_id']), 0, 12));

    // Logo çözümü
    $logoPath = '';
    if (!empty($ticket['logo_path'])) {
        $abs = realpath(__DIR__ . '/../' . ltrim($ticket['logo_path'], '/\\'));
        if ($abs && file_exists($abs)) $logoPath = $abs;
    }

    // PDF Oluştur
    $pdf = new FPDF('P','mm','A4');
    $pdf->AddPage();
    $pdf->SetTitle($toTR('Tripin - Bilet'));
    $pdf->SetAuthor('Tripin');

    // Üst alan
    if ($logoPath) { $pdf->Image($logoPath, 10, 10, 28); }
    $pdf->SetFont('Arial','B',18);
    $pdf->Cell(0,10, $toTR('Yolcu Bileti'), 0,1,'R');

    $pdf->SetFont('Arial','',11);
    $pdf->Cell(0,6, $toTR('Firma: ') . $toTR($ticket['company_name'] ?: '-'), 0,1,'R');
    $pdf->Ln(2);

    // PNR/Bilet No çubuğu
    $pdf->SetFillColor(245,245,245);
    $pdf->SetDrawColor(220,220,220);
    $pdf->SetFont('Arial','B',12);
    $pdf->Cell(0,8, $toTR('Bilet No: ') . $ticket['ticket_id'] . '   |   PNR: ' . $pnr, 1,1,'L', true);
    $pdf->Ln(2);

    // Sefer Bilgileri
    $pdf->SetFont('Arial','B',13);
    $pdf->Cell(0,8, $toTR('Sefer Bilgileri'), 0,1,'L');

    $pdf->SetFont('Arial','',11);
    // Satır 1
    $pdf->Cell(95,7, $toTR('Güzergah: ') . $toTR($ticket['departure_city']) . ' → ' . $toTR($ticket['destination_city']), 0,0,'L');
    $pdf->Cell(95,7, $toTR($koltukLabel . ': ') . $seatText, 0,1,'R');
    // Satır 2
    $pdf->Cell(95,7, $toTR('Kalkış: ') . $toTR(fmtDateTR($ticket['departure_time'])), 0,0,'L');
    $pdf->Cell(95,7, $toTR('Varış: ') . $toTR(fmtDateTR($ticket['arrival_time'])), 0,1,'R');
    // Satır 3
    $genderTR = $ticket['passenger_gender']==='female' ? 'Kadın' : ($ticket['passenger_gender']==='male' ? 'Erkek' : '-');
    $pdf->Cell(95,7, $toTR('Yolcu Cinsiyeti: ') . $toTR($genderTR), 0,0,'L');
    $pdf->Cell(95,7, $toTR('Durum: ') . $toTR($ticket['status'] ?: '-'), 0,1,'R');

    $pdf->Ln(2);

    // Ücret Bilgisi
    $pdf->SetFont('Arial','B',13);
    $pdf->Cell(0,8, $toTR('Ücret Bilgisi'), 0,1,'L');

    $pdf->SetFont('Arial','',11);
    $pdf->Cell(95,7, $toTR('Toplam Tutar: ') . $toTR(fmtTL($ticket['total_price'])), 0,0,'L');
    $pdf->Cell(95,7, $toTR('Araç Kapasitesi: ') . (int)$ticket['capacity'], 0,1,'R');

    $pdf->Ln(2);

    // Yolcu Bilgisi
    $pdf->SetFont('Arial','B',13);
    $pdf->Cell(0,8, $toTR('Yolcu Bilgisi'), 0,1,'L');

    $pdf->SetFont('Arial','',11);
    $pdf->Cell(95,7, $toTR('Ad Soyad: ') . $toTR($ticket['full_name']), 0,0,'L');
    $pdf->Cell(95,7, $toTR('E-posta: ') . $toTR($ticket['email']), 0,1,'R');

    $pdf->Ln(4);

    // Notlar
    $pdf->SetFont('Arial','',10);
    $pdf->SetTextColor(80,80,80);
    $notes = "• Bu bilet yalnızca kimlik ile birlikte geçerlidir.\n"
           . "• Kalkıştan en az 15 dakika önce peronda hazır bulununuz.\n"
           . "• İptal/iadeler için profil sayfasındaki kurallara bakınız.\n"
           . "• Tripin referans kodu (PNR): $pnr";
    $pdf->MultiCell(0,5, $toTR($notes));
    $pdf->SetTextColor(0,0,0);

    // Çıktı
    $filename = 'bilet_' . preg_replace('/[^a-zA-Z0-9_-]/','', $ticket['ticket_id']) . '.pdf';
    $pdf->Output('I', $filename);
    exit;
}

// ---------------------- HTML Görünüm ----------------------
$seatText = $seats ? implode(', ', array_map('intval', $seats)) : '-';
?>
<!doctype html>
<html lang="tr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Bilet Detay</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">
<div class="container my-4">
  <div class="card shadow-sm">
    <div class="card-body">
      <h4 class="mb-3">Bilet Detay</h4>

      <div class="row g-3">
        <div class="col-md-6">
          <p class="mb-1"><strong>Firma:</strong> <?= h($ticket['company_name'] ?: '-') ?></p>
          <p class="mb-1"><strong>Güzergah:</strong> <?= h($ticket['departure_city']) ?> → <?= h($ticket['destination_city']) ?></p>
          <p class="mb-1"><strong>Kalkış:</strong> <?= h(fmtDateTR($ticket['departure_time'])) ?></p>
          <p class="mb-1"><strong>Varış:</strong> <?= h(fmtDateTR($ticket['arrival_time'])) ?></p>
          <p class="mb-1"><strong><?= (count($seats) === 1) ? 'Koltuk No' : 'Koltuklar' ?>:</strong> <?= h($seatText) ?></p>
        </div>
        <div class="col-md-6">
          <p class="mb-1"><strong>Yolcu:</strong> <?= h($ticket['full_name']) ?> (<?= h($ticket['email']) ?>)</p>
          <p class="mb-1"><strong>Durum:</strong> <?= h($ticket['status']) ?></p>
          <p class="mb-1"><strong>Cinsiyet:</strong> <?= h($ticket['passenger_gender']==='female' ? 'Kadın' : ($ticket['passenger_gender']==='male' ? 'Erkek' : '-')) ?></p>
          <p class="mb-1"><strong>Oluşturulma:</strong> <?= h(fmtDateTR($ticket['ticket_created'])) ?></p>
          <p class="mb-1"><strong>Toplam:</strong> <?= h(fmtTL($ticket['total_price'])) ?></p>
        </div>
      </div>

      <hr>
      <a class="btn btn-primary" href="ticket_detail.php?ticket_id=<?= h($ticket['ticket_id']) ?>&pdf=1">PDF İndir</a>
      <a class="btn btn-outline-secondary" href="profile.php">Geri</a>
    </div>
  </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
