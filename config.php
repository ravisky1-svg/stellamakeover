<?php
session_start();
date_default_timezone_set('Asia/Kolkata');

$dbFile = __DIR__ . '/salon.sqlite';
$pdo = new PDO('sqlite:' . $dbFile);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

$pdo->exec("CREATE TABLE IF NOT EXISTS admins (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    username TEXT UNIQUE NOT NULL,
    password_hash TEXT NOT NULL
)");
$pdo->exec("CREATE TABLE IF NOT EXISTS services (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL,
    price REAL NOT NULL DEFAULT 0,
    duration_minutes INTEGER NOT NULL DEFAULT 30,
    active INTEGER NOT NULL DEFAULT 1
)");
$pdo->exec("CREATE TABLE IF NOT EXISTS staff (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL,
    mobile TEXT,
    role TEXT,
    active INTEGER NOT NULL DEFAULT 1
)");
$pdo->exec("CREATE TABLE IF NOT EXISTS clients (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL,
    mobile TEXT,
    birthday TEXT,
    notes TEXT,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
)");
$pdo->exec("CREATE TABLE IF NOT EXISTS appointments (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    client_id INTEGER NOT NULL,
    service_id INTEGER NOT NULL,
    staff_id INTEGER,
    appointment_date TEXT NOT NULL,
    appointment_time TEXT NOT NULL,
    amount REAL NOT NULL DEFAULT 0,
    status TEXT NOT NULL DEFAULT 'Booked',
    payment_status TEXT NOT NULL DEFAULT 'Pending',
    notes TEXT,
    appointment_message_sent INTEGER NOT NULL DEFAULT 0,
    thank_you_message_sent INTEGER NOT NULL DEFAULT 0,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
)");
$pdo->exec("CREATE TABLE IF NOT EXISTS expenses (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    title TEXT NOT NULL,
    amount REAL NOT NULL DEFAULT 0,
    expense_date TEXT NOT NULL,
    notes TEXT,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
)");
$pdo->exec("CREATE TABLE IF NOT EXISTS settings (
    setting_key TEXT PRIMARY KEY,
    setting_value TEXT
)");
$pdo->exec("CREATE TABLE IF NOT EXISTS message_logs (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    client_id INTEGER,
    appointment_id INTEGER,
    mobile TEXT NOT NULL,
    message_type TEXT NOT NULL,
    message_text TEXT NOT NULL,
    api_status TEXT NOT NULL,
    api_response TEXT,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
)");

if ((int)$pdo->query("SELECT COUNT(*) FROM admins")->fetchColumn() === 0) {
    $stmt = $pdo->prepare("INSERT INTO admins(username,password_hash) VALUES(?,?)");
    $stmt->execute(['admin', password_hash('admin123', PASSWORD_DEFAULT)]);
}

$defaults = [
    'salon_name' => 'Stella Makeover',
    'salon_phone' => '',
    'salon_address' => '',
    'whatsapp_enabled' => '0',
    'whatsapp_api_url' => '',
    'whatsapp_api_token' => '',
    'whatsapp_sender_id' => '',
    'whatsapp_phone_field' => 'to',
    'whatsapp_message_field' => 'message',
    'whatsapp_token_header' => 'Authorization',
    'whatsapp_token_prefix' => 'Bearer ',
    'appointment_template' => 'Hi {name} 🌸 Your appointment for {service} is confirmed on {date} at {time}. We look forward to seeing you at {salon}.',
    'thank_you_template' => 'Thank you for visiting {salon}, {name} 💕 We hope you loved your {service} experience. We look forward to welcoming you again soon.',
    'offer_template' => '✨ Special Offer from {salon}! {offer} Book your appointment today. Call/WhatsApp: {salon_phone}'
];
$insertSetting = $pdo->prepare("INSERT OR IGNORE INTO settings(setting_key,setting_value) VALUES(?,?)");
foreach ($defaults as $k => $v) $insertSetting->execute([$k,$v]);
$pdo->exec("UPDATE settings SET setting_value='Stella Makeover' WHERE setting_key='salon_name' AND setting_value='Glow & Grace Salon'");

function e($value): string { return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'); }
function isLoggedIn(): bool { return !empty($_SESSION['admin_id']); }
function requireLogin(): void { if (!isLoggedIn()) { header('Location: login.php'); exit; } }
function getSetting(PDO $pdo, string $key, string $default = ''): string {
    $stmt = $pdo->prepare('SELECT setting_value FROM settings WHERE setting_key=?');
    $stmt->execute([$key]);
    $value = $stmt->fetchColumn();
    return $value === false ? $default : (string)$value;
}
function setSetting(PDO $pdo, string $key, string $value): void {
    $stmt = $pdo->prepare('INSERT INTO settings(setting_key,setting_value) VALUES(?,?) ON CONFLICT(setting_key) DO UPDATE SET setting_value=excluded.setting_value');
    $stmt->execute([$key,$value]);
}
function normalizeMobile(string $mobile): string {
    $digits = preg_replace('/\D+/', '', $mobile);
    if (strlen($digits) === 10) return '91' . $digits;
    return $digits;
}
function flash(string $type, string $message): void { $_SESSION['flash'] = ['type'=>$type,'message'=>$message]; }
function pullFlash(): ?array { $f = $_SESSION['flash'] ?? null; unset($_SESSION['flash']); return $f; }
