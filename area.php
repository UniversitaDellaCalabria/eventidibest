<?php
// area.php - Il Vigile Urbano Dinamico (Pagine + Archivi)
if (session_status() === PHP_SESSION_NONE) { session_start(); }
require_once 'config.php';

$slug_richiesto = isset($_GET['slug']) ? $conn->real_escape_string(trim($_GET['slug'])) : '';

if (empty($slug_richiesto)) {
    header("Location: index.php");
    exit;
}

// 1. Capiamo se sta cercando l'archivio
$is_archivio = false;
$slug_db = $slug_richiesto;

if (substr($slug_richiesto, -9) === '_archivio') {
    $is_archivio = true;
    $slug_db = substr($slug_richiesto, 0, -9);
}

// 2. Controlliamo se la pagina base esiste nel DB
$sql = "SELECT * FROM pagine_eventi WHERE slug = '$slug_db' LIMIT 1"; 
$res = $conn->query($sql);

// 3. GESTIONE 404 - Se non esiste, fermiamo tutto e mostriamo errore
if (!$res || $res->num_rows === 0) {
    header("HTTP/1.0 404 Not Found");
    $page_cfg = ['titolo' => 'Pagina non trovata'];
    require_once 'header.php';
    echo '<div class="container my-5 py-5 text-center" style="min-height: 50vh;">
            <i class="fa fa-exclamation-triangle fa-3x text-warning mb-3"></i>
            <h2 class="fw-bold">404 - Pagina inesistente</h2>
            <p>L\'area richiesta non esiste nel database.</p>
          </div>';
    require_once 'footer.php';
    exit; // Fermiamo qui l'esecuzione. Il master template non viene caricato!
}

// 4. SE ESISTE: Passiamo lo slug corretto al Master Template!
$page_slug = $slug_db; // Variabile fondamentale per il master_template.php

if ($is_archivio && file_exists('master_archivio.php')) {
    require_once 'master_archivio.php';
} elseif (file_exists('master_template.php')) {
    require_once 'master_template.php';
} else {
    die("Errore critico: master_template.php mancante.");
}