<?php
// ATTIVAZIONE OUTPUT BUFFERING: Previene l'invio prematuro di HTML al browser
// permettendo il download pulito dei file CSV e Excel.
ob_start();

ini_set('display_errors', 0);
ini_set('display_startup_errors', 0);
ini_set('log_errors', 1);
error_reporting(E_ALL);

// Imposta il fuso orario di base per il server PHP
date_default_timezone_set('Europe/Rome');

if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'secure'   => true,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

// Previene la cache del browser sulle pagine dinamiche (protegge dallo stato sessione stale)
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Pragma: no-cache");

// HTTP Security Headers
header("Strict-Transport-Security: max-age=31536000; includeSubDomains");
header("X-Frame-Options: SAMEORIGIN");
header("X-Content-Type-Options: nosniff");
header("Referrer-Policy: strict-origin-when-cross-origin");
header("X-XSS-Protection: 1; mode=block");
header("Content-Security-Policy: default-src 'self'; " .
    "script-src 'self' 'unsafe-inline' cdn.jsdelivr.net cdnjs.cloudflare.com cdn.datatables.net code.jquery.com; " .
    "style-src 'self' 'unsafe-inline' cdn.jsdelivr.net cdnjs.cloudflare.com cdn.datatables.net fonts.googleapis.com; " .
    "font-src 'self' cdnjs.cloudflare.com fonts.gstatic.com data:; " .
    "img-src 'self' data: blob: api.qrserver.com; " .
    "connect-src 'self'; " .
    "frame-ancestors 'self';");

$_env = parse_ini_file(__DIR__ . '/.env');
$db_host = $_env['DB_HOST'] ?? 'localhost';
$db_user = $_env['DB_USER'] ?? '';
$db_pass = $_env['DB_PASS'] ?? '';
$db_name = $_env['DB_NAME'] ?? '';
unset($_env);

mysqli_report(MYSQLI_REPORT_OFF);
$conn = @new mysqli($db_host, $db_user, $db_pass, $db_name);

if ($conn->connect_error) {
    error_log('[DB] connect_error: ' . $conn->connect_error);
    http_response_code(503);
    die("Servizio temporaneamente non disponibile. Riprova tra poco.");
}

// Impostazioni definitive sulla connessione aperta
$conn->set_charset("utf8mb4");

// Calcola dinamicamente l'offset italiano (es. +02:00 o +01:00) e lo passa a MySQL
$offset = date('P');
$conn->query("SET time_zone = '$offset'");


// $for_update = true: da usare SOLO dentro una transazione già aperta (begin_transaction).
// Blocca le righe di 'prenotazioni' di questo turno finché la transazione non fa commit/rollback,
// così due prenotazioni concorrenti sullo stesso turno vengono serializzate invece di leggere
// lo stesso conteggio "vecchio" in parallelo (prevenzione overbooking - Fase 2).
//
// Posti occupati = somma di num_posti delle prenotazioni che tengono un posto:
// confermata, richiesta_conferma (posto offerto dalla lista d'attesa, 24h per confermare)
// e da_approvare (occupa MOMENTANEAMENTE: se rifiutata il posto si libera).
// Non occupano: in_attesa, annullata, rifiutata, scaduta. stato NULL = vecchie righe confermate.
function getPostiOccupati($conn, $turno_id, $for_update = false) {
    $turno_id = (int)$turno_id;
    $lock_clause = $for_update ? ' FOR UPDATE' : '';
    $res = $conn->query("SELECT COALESCE(SUM(num_posti), 0) as totale FROM prenotazioni
                         WHERE turno_id = $turno_id
                           AND IFNULL(stato, 'confermata') IN ('confermata', 'richiesta_conferma', 'da_approvare')" . $lock_clause);
    if ($res && $row = $res->fetch_assoc()) {
        return (int)$row['totale'];
    }
    return 0;
}

?>