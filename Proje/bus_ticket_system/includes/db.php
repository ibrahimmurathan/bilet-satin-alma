<?php
// --- Doğrudan erişimi engelle ---
if (isset($_SERVER['SCRIPT_FILENAME']) && realpath($_SERVER['SCRIPT_FILENAME']) === realpath(__FILE__)) {
    http_response_code(403);
    exit('403 - Forbidden.');
}

// --- Hata/log ayarları ---
ini_set('display_errors', 0);
ini_set('log_errors', 1);

// Log klasörü yoksa oluştur
$logDir = __DIR__ . '/../logs';
if (!is_dir($logDir)) {
    @mkdir($logDir, 0775, true);
}
ini_set('error_log', $logDir . '/php_errors.log');

try {
    // --- Veritabanı yolu belirleme (Docker ENV -> fallback yollar) ---

    // 1) Docker/Compose ile gelen ortam değişkeni (Yol A: /Tripin_database/Tripin.db)
    $envPath = getenv('SQLITE_PATH');
    $candidates = [];

    if ($envPath && is_string($envPath)) {
        $candidates[] = $envPath;
    }

    // 2) Mevcut yapına göre (includes -> bus_ticket_system -> Proje -> ROOT -> Tripin_database)
    $candidates[] = __DIR__ . '/../../../Tripin_database/Tripin.db';

    // 3) Alternatif: Tripin_database proje kökünün bir üstünde değilse (bazı kurulumlar için)
    $candidates[] = __DIR__ . '/../Tripin_database/Tripin.db';

    // 4) Alternatif: document root referansı (XAMPP/Apache için olası senaryo)
    if (!empty($_SERVER['DOCUMENT_ROOT'])) {
        $candidates[] = rtrim($_SERVER['DOCUMENT_ROOT'], '/\\') . '/../Tripin_database/Tripin.db';
    }

    // Aday yolları sırayla dene
    $dbPath = false;
    foreach ($candidates as $path) {
        $real = realpath($path);
        if ($real !== false && file_exists($real)) {
            $dbPath = $real;
            break;
        }
        // ENV ile gelmişse ve absolute ise (container içinde /Tripin_database/Tripin.db gibi),
        // realpath false dönebilir; yine de dosya varsa kabul et.
        if ($real === false && file_exists($path)) {
            $dbPath = $path;
            break;
        }
    }

    if ($dbPath === false) {
        // Tanılama için adayları logla
        error_log('SQLite dosyası bulunamadı. Denenen yollar: ' . implode(' | ', $candidates));
        throw new RuntimeException('SQLite dosyası bulunamadı.');
    }

    // --- PDO bağlantısı ---
    $db = new PDO('sqlite:' . $dbPath);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // PDO katmanı timeout (saniye)
    $db->setAttribute(PDO::ATTR_TIMEOUT, 5);
    // $db->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);

    // SQLite PRAGMA ayarları
    $db->exec('PRAGMA foreign_keys = ON');
    $db->exec('PRAGMA journal_mode = WAL');
    $db->exec('PRAGMA synchronous = NORMAL');
    $db->exec('PRAGMA busy_timeout = 5000');        // 5 sn (ms cinsinden)

    // Performans ve geçici veriler
    $db->exec('PRAGMA temp_store = MEMORY');        // küçük temp tablolar RAM'de
    $db->exec('PRAGMA mmap_size = 268435456');      // 256 MB memory-mapped I/O (uyumlu FS'lerde)
    $db->exec('PRAGMA cache_size = -20000');        // ~20 MB page cache (KB olarak negatif)

    // WAL checkpoint davranışı
    $db->exec('PRAGMA wal_autocheckpoint = 1000');  // ~1000 sayfa

    // Optimize
    $db->exec('PRAGMA optimize');

    // --- (Opsiyonel) Tanılama ---
    // $jm = $db->query("PRAGMA journal_mode")->fetchColumn();
    // $wa = $db->query("PRAGMA wal_autocheckpoint")->fetchColumn();
    // error_log("SQLite journal_mode=$jm wal_autocheckpoint=$wa, path=$dbPath");

} catch (Throwable $e) {
    error_log('DB bağlantı/ayar hatası: ' . $e->getMessage());
    http_response_code(500);
    exit('Sistem hatası. Lütfen daha sonra tekrar deneyin.');
}
