<?php
// Remove this if your pages already call session_start()
// session_start();

define('BASE_URL', 'https://lbdigitalworks.com/websites/tools/AdminCP');

/* ---- WhatsApp via Twilio (PHP cURL) ----
   Replace with your real credentials. Never commit real secrets to git. */
define('TWILIO_SID', 'AC3c144b02bbf462c91bee02b87f9eb738');
define('TWILIO_TOKEN', '1709ab1435263722a8e41919e97a0071');
define('TWILIO_WHATSAPP_FROM', 'whatsapp:+7397248191'); // your Twilio WhatsApp-enabled number (or sandbox)

/* ---- Database ---- */
$host = "localhost";
$db   = "lbdigita_adminpanel"; // must include prefix from cPanel
$user = "lbdigita_vortex";     // full user name with prefix
$pass = "y74Lryckq8UgPTJs";    // MySQL password from cPanel

try {
    $pdo = new PDO("mysql:host=$host;dbname=$db;charset=utf8mb4", $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
    ]);
} catch (PDOException $e) {
    die("DB Connection failed: " . $e->getMessage());
}
