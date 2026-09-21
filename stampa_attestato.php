<?php
// stampa_attestato.php - Generazione PDF Attestato (Aggiornato per firme per Area)
if (session_status() === PHP_SESSION_NONE) { session_start(); }

require_once 'config.php';
require_once 'functions.php'; 

sync_sso_user($conn);

$code = trim($_GET['code'] ?? '');
if (empty($code)) { die("Codice di sicurezza non valido."); }

$p = get_attestato($conn, $code);
if (!$p) { die("Nessun dato trovato per questo codice."); }

// CONTROLLO DI SICUREZZA
if ((int)$p['presente'] !== 1) {
    die("<div style='text-align:center; font-family:sans-serif; margin-top:50px;'><h2 style='color:#dc3545;'>Attestato non disponibile</h2><p>Questo attestato viene generato solo per gli utenti che hanno fisicamente partecipato all'evento (check-in effettuato).</p></div>");
}

$u_id = $_SESSION['utente_id'] ?? 0;
$u_ruolo = $_SESSION['utente_ruolo_id'] ?? 5;
$sec_roles = isset($_SESSION['utente_ruoli_secondari']) ? explode(',', $_SESSION['utente_ruoli_secondari']) : [];

$is_admin_or_gestore = ($u_ruolo == 1 || $u_ruolo == 2 || in_array('1', $sec_roles) || in_array('2', $sec_roles));
$is_owner = ($u_id > 0 && ($p['utente_id'] == $u_id || strtolower($p['email']) === strtolower($_SESSION['utente_email'] ?? '')));

if (!$is_admin_or_gestore && !$is_owner) { die("Accesso negato. Non sei autorizzato a visualizzare questo attestato."); }

// CALCOLO ORE
$inizio_ts = strtotime($p['orario_inizio']);
$fine_ts = strtotime($p['orario_fine']);
$ore_totali = round(($fine_ts - $inizio_ts) / 3600, 1);
$ore_testo = str_replace('.0', '', (string)$ore_totali);

$data_evento = date('d/m/Y', strtotime($p['data_turno']));

// Usa il nuovo logo dell'Area se esiste, altrimenti usa quello standard globale
$logo_src = !empty($p['logo_attestato_path']) ? htmlspecialchars($p['logo_attestato_path']) : (!empty($p['logo_path']) ? htmlspecialchars($p['logo_path']) : '');

// DATI FIRMA DINAMICA PER AREA
$nome_firma = !empty($p['firma_nome']) ? $p['firma_nome'] : 'Mauro F. La Russa';
$titolo_firma = !empty($p['firma_titolo']) ? $p['firma_titolo'] : 'Il Direttore del Dipartimento';
?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <title>Attestato - <?php echo htmlspecialchars($p['nome'] . ' ' . $p['cognome']); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <!-- FONT GOOGLE PER LA FIRMA CORSIVA -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Dancing+Script:wght@700&display=swap" rel="stylesheet">
    
    <style>
        /* RESET GENERALE PER LA STAMPA */
        body, html { margin: 0; padding: 0; background-color: #e2e8f0; font-family: 'Georgia', 'Times New Roman', serif; color: #1e293b; box-sizing: border-box; }
        
        @page { size: A4 landscape; margin: 0; }
        
        @media print {
            body { background-color: white; -webkit-print-color-adjust: exact; margin: 0; padding: 0; overflow: hidden; }
            .no-print { display: none !important; }
            .cert-container { 
                box-shadow: none !important; 
                margin: 0 !important; 
                width: 100vw !important; 
                height: 100vh !important; 
                padding: 12mm !important; 
                page-break-after: avoid; 
                page-break-before: avoid;
                page-break-inside: avoid;
            }
        }

        .cert-container {
            width: 297mm;
            height: 210mm;
            margin: 20px auto;
            background: #ffffff;
            box-shadow: 0 10px 30px rgba(0,0,0,0.15);
            padding: 10mm;
            position: relative;
            box-sizing: border-box;
            overflow: hidden;
        }

        .cert-border-outer { border: 4px solid #B30000; padding: 5px; height: 100%; border-radius: 4px; box-sizing: border-box; }
        
        .cert-border-inner { 
            border: 2px solid #0056b3; 
            height: 100%; 
            padding: 20px 40px; 
            text-align: center; 
            position: relative; 
            border-radius: 2px; 
            box-sizing: border-box; 
            display: flex; 
            flex-direction: column; 
            justify-content: space-between; 
        }
        
        .cert-header { display: flex; justify-content: center; align-items: center; gap: 20px; }
        .cert-logo { max-height: 80px; object-fit: contain; padding: 5px; border-radius: 6px; }
        
        .cert-main-content { display: flex; flex-direction: column; justify-content: center; flex-grow: 1; }
        
        .cert-title { font-size: 3.2rem; font-weight: bold; color: #B30000; letter-spacing: 2px; margin: 0 0 10px 0; text-transform: uppercase; line-height: 1.1; }
        .cert-subtitle { font-size: 1.4rem; color: #64748b; font-style: italic; margin-bottom: 20px; }
        
        .cert-body { font-size: 1.3rem; line-height: 1.5; }
        .cert-name { font-size: 2.3rem; font-weight: bold; color: #1e293b; border-bottom: 1px solid #cbd5e1; display: inline-block; padding: 0 40px; margin: 10px 0; }
        .cert-event { font-size: 1.6rem; font-weight: bold; color: #0056b3; margin: 10px 0; display: block; line-height: 1.2; }
        
        .cert-footer { display: flex; justify-content: space-between; align-items: flex-end; padding: 0 20px; }
        .cert-signature { width: 300px; text-align: center; font-size: 1.1rem; }
        
        .signature-text { 
            font-family: 'Dancing Script', cursive; 
            font-size: 2.6rem; 
            color: #1e293b; 
            line-height: 0.6; 
            margin-bottom: 10px; 
            transform: rotate(-3deg); 
            white-space: nowrap; 
        }
        
        .cert-stamp { position: absolute; bottom: 50%; left: 50%; transform: translate(-50%, 50%); opacity: 0.05; font-size: 15rem; color: #B30000; pointer-events: none; }
        .cert-id { position: absolute; bottom: 5px; left: 10px; font-size: 0.75rem; color: #94a3b8; font-family: monospace; }
    </style>
</head>
<body>

    <div class="text-center my-3 no-print">
        <button onclick="window.print()" class="btn btn-danger fw-bold px-4 py-2 shadow-sm fs-5" style="background:#B30000; border:none;"><i class="fa fa-print me-2"></i> Stampa / Salva in PDF</button>
        <button onclick="window.close()" class="btn btn-outline-secondary py-2 px-4 ms-2 fw-bold fs-5">Chiudi</button>
        <p class="text-muted mt-2 small mb-0"><i class="fa fa-info-circle me-1"></i> <strong>Consiglio:</strong> Nelle impostazioni di stampa, seleziona <strong>Orizzontale (Landscape)</strong>, imposta Margini su <strong>Predefiniti</strong> e abilita la <strong>Grafica in background</strong>.</p>
    </div>

    <div class="cert-container">
        <div class="cert-border-outer">
            <div class="cert-border-inner">
                
                <i class="fa fa-award cert-stamp"></i>

                <!-- BLOCCO SUPERIORE -->
                <div class="cert-header">
                    <?php if ($logo_src): ?><img src="<?php echo $logo_src; ?>" class="cert-logo" alt="Logo"><?php endif; ?>
                    <div>
                        <h4 class="fw-bold m-0" style="color: #334155;"><?php echo htmlspecialchars($p['nome_portale']); ?></h4>
                        <span style="font-size: 1.1rem; color: #64748b;"><?php echo htmlspecialchars($p['sottotitolo_portale']); ?></span>
                    </div>
                </div>

                <!-- BLOCCO CENTRALE ELASTICO -->
                <div class="cert-main-content">
                    <h1 class="cert-title">Attestato di Partecipazione</h1>
                    <div class="cert-subtitle">Si attesta che</div>

                    <div class="cert-body">
                        <span class="cert-name"><?php echo htmlspecialchars(mb_strtoupper($p['nome'] . ' ' . $p['cognome'])); ?></span><br>
                        <?php if (!empty($p['matricola_effettiva'])): ?>
                            <span style="font-size: 1.1rem; color: #64748b;">(Matricola: <?php echo htmlspecialchars($p['matricola_effettiva']); ?>)</span><br>
                        <?php endif; ?>
                        
                        <span class="mt-3 d-block">ha partecipato all'attività formativa/evento denominata:</span>
                        <span class="cert-event">"<?php echo htmlspecialchars($p['evento_titolo']); ?>"</span>
                        <span class="d-block mt-2">
                            Svoltasi in data <strong><?php echo $data_evento; ?></strong> presso <?php echo htmlspecialchars($p['evento_luogo'] ?: 'le nostre strutture'); ?> 
                            <strong>per un numero di ore pari a <?php echo $ore_testo; ?></strong>.
                        </span>
                    </div>
                </div>

                <!-- BLOCCO INFERIORE -->
                <div class="cert-footer">
                    <div style="text-align: left; font-size: 1.1rem; padding-bottom: 10px;">
                        <strong>Data di rilascio:</strong> <?php echo date('d/m/Y'); ?><br>
                        <strong>Rif. Iniziativa:</strong> <?php echo htmlspecialchars($p['pagina_titolo']); ?>
                    </div>
                    
                    <div class="cert-signature">
                        <div class="signature-text"><?php echo htmlspecialchars($nome_firma); ?></div>
                        <div class="border-top border-dark pt-1 mt-1">
                            <span class="fw-bold d-block">Prof. <?php echo htmlspecialchars($nome_firma); ?></span>
                            <small style="color: #64748b;"><?php echo htmlspecialchars($titolo_firma); ?></small>
                        </div>
                    </div>
                </div>

                <div class="cert-id">Codice Verifica Autenticità: <?php echo htmlspecialchars($p['codice_prenotazione']); ?></div>

            </div>
        </div>
    </div>
</body>
</html>
