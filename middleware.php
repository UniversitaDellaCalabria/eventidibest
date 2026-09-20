<?php
// middleware.php - Centralizzazione Autenticazione e Controllo Accessi (Fase 4)
if (session_status() === PHP_SESSION_NONE) { session_start(); }

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/functions.php';

// 1. Sincronizza automaticamente l'utente tramite SSO
sync_sso_user($conn);

// 2. Controllo Autenticazione Base
if (empty($_SESSION['utente_id'])) {
    header("Location: saml_login.php?redirect=" . urlencode($_SERVER['REQUEST_URI']));
    exit;
}

// 3. Estrazione Variabili Standardizzate per le pagine protette
$u_id = (int)$_SESSION['utente_id'];
$u_ruolo = (int)($_SESSION['utente_ruolo_id'] ?? 5);
$sec_roles = isset($_SESSION['utente_ruoli_secondari']) ? explode(',', $_SESSION['utente_ruoli_secondari']) : [];

$is_full_admin = ($u_ruolo === 1 || in_array('1', $sec_roles));
$is_gestore = ($u_ruolo === 2 || in_array('2', $sec_roles));

// 4. Recupero dati utente completi dal DB (A disposizione di tutte le pagine incluse)
$stmt_mw = $conn->prepare("SELECT * FROM utenti WHERE id = ? LIMIT 1");
$stmt_mw->bind_param("i", $u_id);
$stmt_mw->execute();
$user_info = $stmt_mw->get_result()->fetch_assoc();
$u_email_sql = strtolower($user_info['email'] ?? '');

// 5. Helper: Protezione Aree Riservate a Gestori e Admin
if (!function_exists('require_admin_or_gestore')) {
    function require_admin_or_gestore() {
        global $is_full_admin, $is_gestore;
        if (!$is_full_admin && !$is_gestore) {
            die("<!DOCTYPE html>
                 <html lang='it'><head><title>Accesso Negato</title>
                 <style>body{font-family:sans-serif;background:#f8fafc;color:#1e293b;text-align:center;padding-top:10vh;} a{color:#0056b3;font-weight:bold;text-decoration:none;}</style></head>
                 <body>
                    <svg width='80' height='80' fill='none' stroke='#dc3545' stroke-width='2' viewBox='0 0 24 24'><path stroke-linecap='round' stroke-linejoin='round' d='M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z'/></svg>
                    <h2 style='color:#dc3545;margin-top:20px;'>Accesso Negato</h2>
                    <p>Non hai i privilegi necessari per visualizzare questa pagina.</p>
                    <a href='index.php'>Torna alla Home del Portale</a>
                 </body></html>");
        }
    }
}
?>
