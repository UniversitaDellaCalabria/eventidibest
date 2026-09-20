<?php
// admin/index.php - Router Intelligente RBAC
ob_start();
require_once 'admin_header.php';
ob_end_clean();

// Se l'utente non ha trovato aree a cui accedere, lo mandiamo alla home del sito con un errore.
if (empty($pagine_disponibili)) {
    die("<div style='text-align:center; margin-top:50px; font-family:sans-serif;'><h3>Accesso Negato</h3><p>Non risulti assegnato a nessuna area di lavoro come gestore.</p><a href='../index.php'>Torna al Sito Pubblico</a></div>");
}

// Porta l'utente alla dashboard principale
header("Location: dashboard.php?p_id=$filtro_p");
exit;
?>
