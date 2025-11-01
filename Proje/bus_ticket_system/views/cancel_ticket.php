<?php
// views/cancel_ticket.php
session_start();
require_once __DIR__ . '/../includes/db.php';

function h($s){ return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8'); }

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$user_id = $_SESSION['user_id'];
$user_role = $_SESSION['user_role'] ?? ($_SESSION['role'] ?? 'user');
$user_company_id = $_SESSION['company_id'] ?? null; // company rolü için gerekli
$ticket_id = $_POST['ticket_id'] ?? '';

if (empty($ticket_id)) {
    http_response_code(400);
    exit('Geçersiz istek.');
}

try {
    $db->exec('BEGIN IMMEDIATE');

    // Bilet + Trip + Owner + Company doğrula
    $stmt = $db->prepare("
        SELECT 
            t.id               AS ticket_id,
            t.user_id          AS owner_id,
            t.status,
            t.total_price,
            tr.departure_time  AS dep_time,
            tr.company_id      AS trip_company_id
        FROM Tickets t
        JOIN Trips tr ON tr.id = t.trip_id
        WHERE t.id = :tid
        LIMIT 1
    ");
    $stmt->execute([':tid'=>$ticket_id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    $stmt->closeCursor();

    if (!$row) { throw new Exception('Bilet bulunamadı.'); }

    // Yetki: sahibi veya company (aynı firmaya ait sefer)
    $isOwner = ($row['owner_id'] === $user_id);
    $isCompanyAllowed = ($user_role === 'company' && !empty($user_company_id) && $user_company_id === $row['trip_company_id']);

    if (!$isOwner && !$isCompanyAllowed) {
        throw new Exception('Bu bileti iptal etme yetkiniz yok.');
    }

    if (strtolower(trim($row['status'])) !== 'active') {
        throw new Exception('Sadece aktif biletler iptal edilebilir.');
    }

    // Kalkışa 1 saat kuralı
    $now = new DateTime('now', new DateTimeZone('Europe/Istanbul'));
    try {
        $dep = new DateTime($row['dep_time'], new DateTimeZone('Europe/Istanbul'));
    } catch (Exception $e) {
        throw new Exception('Sefer zamanı okunamadı.');
    }
    $diffSeconds = $dep->getTimestamp() - $now->getTimestamp();
    if ($diffSeconds < 3600) {
        throw new Exception('Kalkışa 1 saatten az kaldığı için iptal edilemez.');
    }

    // 1) Ticket status -> cancelled
    $u1 = $db->prepare("UPDATE Tickets SET status='cancelled' WHERE id = :tid");
    $u1->execute([':tid' => $ticket_id]);
    $u1->closeCursor();

    // 2) Koltuk(lar)ı boşalt
    $d1 = $db->prepare("DELETE FROM Booked_Seats WHERE ticket_id = :tid");
    $d1->execute([':tid' => $ticket_id]);
    $d1->closeCursor();

    // 3) Ücret iadesi yalnızca bileti satın alan yolcunun bakiyesine yapılır
    $refund = (int)$row['total_price'];
    if ($refund > 0) {
        $u2 = $db->prepare("UPDATE User SET balance = COALESCE(balance,0) + :r WHERE id = :uid");
        $u2->execute([':r'=>$refund, ':uid'=>$row['owner_id']]);
        $u2->closeCursor();
    }

    $db->exec('COMMIT');

    // İptali profile'a bildir
    header('Location: profile.php?cancelled=1');
    exit;

} catch (Exception $e) {
    if ($db->inTransaction()) { $db->exec('ROLLBACK'); }
    $msg = urlencode($e->getMessage());
    header("Location: profile.php?cancel_error={$msg}");
    exit;
}
