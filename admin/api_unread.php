<?php
// admin/api_unread.php - Endpoint JSON per il badge messaggi non letti (polling AJAX)
ini_set('display_errors', 0);
if (session_status() === PHP_SESSION_NONE) session_start();

require_once '../config.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if (empty($_SESSION['utente_id'])) {
    echo json_encode(['unread' => 0, 'auth' => false]);
    exit;
}

$p_id = isset($_GET['p_id']) ? (int)$_GET['p_id'] : 0;
if ($p_id <= 0) {
    echo json_encode(['unread' => 0]);
    exit;
}

$sql = "SELECT COUNT(DISTINCT m.prenotazione_id) as n
        FROM messaggi_prenotazioni m
        JOIN prenotazioni p ON m.prenotazione_id = p.id
        JOIN turni t ON p.turno_id = t.id
        JOIN eventi e ON t.evento_id = e.id
        WHERE m.letto = 0 AND m.mittente_tipo = 'utente'
          AND e.pagina_id = $p_id";

$res = $conn->query($sql);
$n   = ($res !== false) ? (int)($res->fetch_assoc()['n'] ?? 0) : 0;
echo json_encode(['unread' => $n, 'auth' => true]);
