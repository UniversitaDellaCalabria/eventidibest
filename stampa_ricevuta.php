<?php
if (session_status() === PHP_SESSION_NONE) { session_start(); }

require_once 'config.php';
require_once 'functions.php'; 

sync_sso_user($conn);

$code = trim($_GET['code'] ?? '');
$id   = (int)($_GET['id'] ?? 0);

if (empty($code) && $id <= 0) { die("Parametri non validi."); }

$p = get_prenotazione_ricevuta($conn, $code, $id);
if (!$p) { die("Ricevuta non trovata nel sistema."); }

$u_id = $_SESSION['utente_id'] ?? 0;
$u_ruolo = $_SESSION['utente_ruolo_id'] ?? 5;
$sec_roles = isset($_SESSION['utente_ruoli_secondari']) ? explode(',', $_SESSION['utente_ruoli_secondari']) : [];

$is_admin_or_gestore = ($u_ruolo == 1 || $u_ruolo == 2 || in_array('1', $sec_roles) || in_array('2', $sec_roles));
$is_owner = ($u_id > 0 && ($p['utente_id'] == $u_id || strtolower($p['email']) === strtolower($_SESSION['utente_email'] ?? '')));
$has_valid_code = (!empty($code) && $p['codice_prenotazione'] === $code);

if (!$is_admin_or_gestore && !$is_owner && !$has_valid_code) { die("Accesso non autorizzato a questa ricevuta."); }

$json_custom = json_decode($p['dati_custom_json'] ?? '', true) ?: [];

// GENERAZIONE LINK PER IL QR CODE
$proto = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? "https://" : "http://";
$domain = $_SERVER['HTTP_HOST'] ?? 'localhost';
$base_dir = rtrim(dirname($_SERVER['PHP_SELF']), '/\\');
$checkin_url = $proto . $domain . $base_dir . "/checkin.php?code=" . urlencode($p['codice_prenotazione']);
$qr_api_url = "https://api.qrserver.com/v1/create-qr-code/?size=180x180&data=" . urlencode($checkin_url);
?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <title>Ricevuta - <?php echo htmlspecialchars($p['codice_prenotazione']); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body { background-color: #f8fafc; font-family: 'Segoe UI', sans-serif; color: #334155; }
        .ticket-box { max-width: 800px; margin: 30px auto; background: #fff; border-radius: 12px; box-shadow: 0 10px 30px rgba(0,0,0,0.08); border: 2px solid #e2e8f0; overflow: hidden; }
        /* Aggiornato il rosso a #B80000 */
        .ticket-header { background: #B80000; color: white; padding: 25px; border-bottom: 4px solid #7a0000; }
        .ticket-body { padding: 30px; }
        .code-badge { font-size: 1.4rem; font-weight: 800; background: #f1f5f9; color: #0f172a; padding: 8px 16px; border-radius: 8px; border: 1px dashed #cbd5e1; display: inline-block; letter-spacing: 2px; }
        @media print { body { background: white; } .no-print { display: none !important; } .ticket-box { box-shadow: none; border: 1px solid #000; margin: 0; max-width: 100%; } }
    </style>
</head>
<body>

    <div class="container text-center my-4 no-print">
        <!-- Aggiornato il colore del bottone -->
        <button onclick="window.print()" class="btn btn-danger fw-bold px-4 py-2 shadow-sm" style="background:#B80000; border:none;"><i class="fa fa-print me-1"></i> Stampa / PDF</button>
        <button onclick="window.close()" class="btn btn-outline-secondary btn-sm ms-2">Chiudi</button>
    </div>

    <div class="ticket-box">
        <div class="ticket-header d-flex justify-content-between align-items-center flex-wrap gap-3">
            <div class="d-flex align-items-center gap-3">
                <!-- Rimosso il background bianco e il padding dal logo -->
                <?php if (!empty($p['logo_path'])): ?><img src="<?php echo htmlspecialchars($p['logo_path']); ?>" style="max-height: 75px; margin-right: 10px;"><?php endif; ?>
                <div>
                    <h4 class="fw-bold m-0"><?php echo htmlspecialchars($p['nome_portale'] ?: 'UNIVERSITÀ DELLA CALABRIA'); ?></h4>
                    <small class="opacity-75"><?php echo htmlspecialchars($p['sottotitolo_portale'] ?: 'Dipartimento di Biologia, Ecologia e Scienze della Terra'); ?></small>
                </div>
            </div>
            <div class="text-end">
                <span class="badge bg-white text-danger fw-bold text-uppercase fs-6 px-3 py-2 shadow-sm" style="color: #B80000 !important;">RICEVUTA DI PRENOTAZIONE</span>
            </div>
        </div>

        <div class="ticket-body">
            <div class="row mb-4 pb-3 border-bottom align-items-center">
                <div class="col-sm-8">
                    <span class="text-muted small d-block fw-bold text-uppercase">CODICE UNIVOCO:</span>
                    <div class="code-badge mt-1"><?php echo htmlspecialchars($p['codice_prenotazione']); ?></div>
                    <div class="mt-3">
                        <span class="text-muted small d-block fw-bold text-uppercase">STATO PRENOTAZIONE:</span>
                        <?php 
                            $st = $p['stato'] ?? 'confermata';
                            if ($st === 'da_approvare') echo '<span class="badge bg-info text-dark fs-6 mt-1"><i class="fa fa-hourglass-half"></i> In attesa</span>';
                            elseif ($st === 'in_attesa') echo '<span class="badge bg-warning text-dark fs-6 mt-1"><i class="fa fa-clock"></i> Lista d\'Attesa</span>';
                            elseif ($st === 'rifiutata') echo '<span class="badge bg-secondary fs-6 mt-1"><i class="fa fa-times"></i> Non Accolta</span>';
                            else echo '<span class="badge bg-success fs-6 mt-1"><i class="fa fa-check"></i> Confermata</span>';
                        ?>
                    </div>
                </div>
                <div class="col-sm-4 text-center mt-3 mt-sm-0">
                    <!-- IL QR CODE -->
                    <img src="<?php echo $qr_api_url; ?>" alt="QR Code" class="img-fluid border p-1 rounded shadow-sm" style="max-width: 150px;">
                    <div class="small text-muted mt-1 fw-bold">Scansiona all'ingresso</div>
                </div>
            </div>

            <div class="row g-4 mb-4">
                <div class="col-md-6 border-end">
                    <h6 class="fw-bold border-bottom pb-2 mb-3" style="color: #B80000;"><i class="fa fa-calendar-alt me-1"></i> Dettaglio Evento</h6>
                    <p class="mb-2"><strong>Iniziativa:</strong> <br><span class="text-secondary"><?php echo htmlspecialchars($p['pagina_titolo']); ?></span></p>
                    <p class="mb-2"><strong>Attività:</strong> <br><span class="text-dark fw-bold"><?php echo htmlspecialchars($p['evento_titolo']); ?></span></p>
                    <?php if (!empty($p['nome_turno'])): ?><p class="mb-2"><strong>Turno / Gruppo:</strong> <br><span class="fw-bold text-dark">🏷️ <?php echo htmlspecialchars($p['nome_turno']); ?></span></p><?php endif; ?>
                    <?php if (!empty($p['data_turno'])): ?><p class="mb-2"><strong>Data:</strong> <br><span class="fw-bold" style="color: #B80000;">📅 <?php echo date('d/m/Y', strtotime($p['data_turno'])); ?></span></p><?php endif; ?>
                    <?php if (orario_turno($p) !== ''): ?><p class="mb-2"><strong>Orario:</strong> <br><span class="text-dark">🕒 <?php echo orario_turno($p); ?></span></p><?php endif; ?>
                    <p class="mb-0"><strong>Luogo:</strong> <br><span class="text-secondary">📍 <?php echo htmlspecialchars($p['evento_luogo'] ?: 'DiBEST Unical'); ?></span></p>
                </div>

                <div class="col-md-6">
                    <h6 class="fw-bold border-bottom pb-2 mb-3" style="color: #B80000;"><i class="fa fa-user me-1"></i> Partecipante</h6>
                    <p class="mb-2"><strong>Nominativo:</strong> <br><span class="text-dark fw-bold"><?php echo htmlspecialchars($p['nome'] . ' ' . $p['cognome']); ?></span></p>
                    <p class="mb-2"><strong>Email:</strong> <br><span class="text-secondary"><?php echo htmlspecialchars($p['email']); ?></span></p>
                    <p class="mb-2"><strong>Matricola:</strong> <br><span class="text-secondary"><?php echo htmlspecialchars($p['matricola_effettiva'] ?: 'N/D'); ?></span></p>
                    <p class="mb-0"><strong>Posti Riservati:</strong> <br><span class="badge bg-dark fs-6"><?php echo $p['num_posti'] ?? 1; ?> Posto/i</span></p>
                </div>
            </div>

            <?php if (!empty($json_custom)): ?>
                <div class="bg-light p-3 rounded border mb-4">
                    <h6 class="fw-bold border-bottom pb-2 mb-3" style="color: #B80000;"><i class="fa fa-list-check me-1"></i> Informazioni Aggiuntive</h6>
                    <div class="row g-3">
                        <?php foreach ($json_custom as $key => $val): ?>
                            <div class="col-md-6">
                                <small class="text-muted d-block fw-bold text-uppercase" style="font-size:0.75rem;"><?php echo htmlspecialchars(str_replace('_', ' ', $key)); ?>:</small>
                                <span class="fw-semibold text-dark"><?php echo (strpos($val, 'uploads/') !== false) ? '📎 File Allegato' : htmlspecialchars($val); ?></span>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>
