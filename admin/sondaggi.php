<?php
// sondaggi.php - Gestione Questionari, Feedback e Statistiche
require_once 'admin_header.php';

$is_archivio = (isset($_GET['archivio']) && $_GET['archivio'] == 1) || (isset($_POST['archivio']) && $_POST['archivio'] == 1) ? 1 : 0;

if (!$can_manage_sondaggi) {
    echo "<div class='alert alert-danger fw-bold shadow-sm m-4'><i class='fa fa-ban me-2'></i> Accesso negato. Non hai i permessi per gestire i sondaggi in quest'area.</div>";
    require_once 'admin_footer.php';
    exit;
}

function admin_redirect($url) { echo "<script>window.location.replace('$url');</script>"; exit; }

$f_sond_ev = isset($_GET['f_sond_ev']) ? (int)$_GET['f_sond_ev'] : 0;
$url_suffix = $is_archivio ? "&archivio=1" : "";

// ==============================================================================
// BLOCCO ELABORAZIONE AZIONI BACKEND (GET / POST)
// ==============================================================================

if (isset($_POST['export_sondaggio_xls'])) {
    csrf_verify($_POST['csrf_token'] ?? '');
    $sond_id_exp = (int)$_POST['sondaggio_id_export'];
    $domande_exp = [];
    $res_d_exp = $conn->query("SELECT id, testo_domanda, tipo FROM sondaggi_domande WHERE sondaggio_id = $sond_id_exp ORDER BY ordine ASC, id ASC");
    if ($res_d_exp) while($d = $res_d_exp->fetch_assoc()) $domande_exp[$d['id']] = $d;
    
    $risposte_raggruppate = [];
    $res_r_exp = $conn->query("SELECT * FROM sondaggi_risposte WHERE sondaggio_id = $sond_id_exp ORDER BY data_risposta DESC");
    if ($res_r_exp) {
        while ($r = $res_r_exp->fetch_assoc()) {
            $dr = $r['data_risposta'];
            if (!isset($risposte_raggruppate[$dr])) $risposte_raggruppate[$dr] = [];
            $risposte_raggruppate[$dr][$r['domanda_id']] = $r['risposta'];
        }
    }
    
    ob_end_clean(); 
    $filename = "Risultati_Sondaggio_" . date('Ymd_Hi');
    header("Content-Type: application/vnd.ms-excel; charset=utf-8");
    header("Content-Disposition: attachment; filename={$filename}.xls");
    header("Pragma: no-cache"); header("Expires: 0");
    
    echo '<html xmlns:o="urn:schemas-microsoft-com:office:office" xmlns:x="urn:schemas-microsoft-com:office:excel" xmlns="http://www.w3.org/TR/REC-html40"><head><meta charset="utf-8"></head><body><table border="1">';
    echo '<tr><th style="background-color:#198754; color:white;">Data Compilazione (Anonima)</th>';
    foreach ($domande_exp as $id => $dinfo) echo '<th style="background-color:#198754; color:white;">' . htmlspecialchars($dinfo['testo_domanda']) . '</th>';
    echo '</tr>';
    
    foreach ($risposte_raggruppate as $data_comp => $risp_date) {
        echo '<tr><td>' . htmlspecialchars($data_comp) . '</td>';
        foreach ($domande_exp as $id => $dinfo) {
            $val = $risp_date[$id] ?? 'N/D';
            if ($dinfo['tipo'] === 'matrice' && $val !== 'N/D') {
                $json = json_decode($val, true);
                if (is_array($json)) {
                    $arr = [];
                    foreach($json as $k => $v) $arr[] = "$k: $v/5";
                    $val = implode(" | ", $arr);
                }
            }
            echo '<td>' . htmlspecialchars($val) . '</td>';
        }
        echo '</tr>';
    }
    echo '</table></body></html>'; 
    exit;
}

// AZIONI DI MODIFICA CONSENTITE SOLO SE NON ARCHIVIATO
if (!$is_archivio) {
    if (isset($_POST['add_sondaggio'])) {
        csrf_verify($_POST['csrf_token'] ?? '');
        $ev_id = (int)$_POST['evento_id'];
        $titolo = $conn->real_escape_string($_POST['titolo_sondaggio']);
        $conn->query("INSERT INTO sondaggi (evento_id, titolo, attivo) VALUES ($ev_id, '$titolo', 0)");
        flash_set("✅ Sondaggio creato! Ora aggiungi le domande.");
        admin_redirect("sondaggi.php?p_id=$filtro_p&f_sond_ev=$ev_id$url_suffix");
    }

    if (isset($_POST['add_domanda_sondaggio'])) {
        csrf_verify($_POST['csrf_token'] ?? '');
        $s_id = (int)$_POST['sondaggio_id'];
        $ev_id = (int)$_POST['evento_id'];
        $testo = $conn->real_escape_string($_POST['testo_domanda']);
        $tipo = $conn->real_escape_string($_POST['tipo_domanda']);
        $opzioni = isset($_POST['opzioni']) ? $conn->real_escape_string(trim($_POST['opzioni'])) : '';
        $obbl = isset($_POST['obbligatorio']) ? 1 : 0;
        
        $conn->query("INSERT INTO sondaggi_domande (sondaggio_id, testo_domanda, tipo, obbligatorio, opzioni) VALUES ($s_id, '$testo', '$tipo', $obbl, '$opzioni')");
        flash_set("Domanda aggiunta con successo!");
        admin_redirect("sondaggi.php?p_id=$filtro_p&f_sond_ev=$ev_id$url_suffix");
    }

    if (isset($_GET['toggle_sondaggio'])) {
        $s_id = (int)$_GET['toggle_sondaggio'];
        $val = (int)$_GET['val'];
        $ev_id = (int)$_GET['ev_id'];
        $conn->query("UPDATE sondaggi SET attivo = $val WHERE id = $s_id");
        flash_set($val == 1 ? "Sondaggio Attivato!" : "Sondaggio Disattivato!");
        admin_redirect("sondaggi.php?p_id=$filtro_p&f_sond_ev=$ev_id$url_suffix");
    }

    if (isset($_GET['del_domanda_sondaggio'])) {
        $d_id = (int)$_GET['del_domanda_sondaggio'];
        $ev_id = (int)$_GET['ev_id'];
        $conn->query("DELETE FROM sondaggi_domande WHERE id = $d_id");
        flash_set("Domanda eliminata.");
        admin_redirect("sondaggi.php?p_id=$filtro_p&f_sond_ev=$ev_id$url_suffix");
    }

    if (isset($_GET['del_sondaggio'])) {
        $s_id = (int)$_GET['del_sondaggio'];
        $ev_id = (int)$_GET['ev_id'];
        
        $conn->query("DELETE FROM sondaggi_risposte WHERE sondaggio_id = $s_id");
        $conn->query("DELETE FROM sondaggi_domande WHERE sondaggio_id = $s_id");
        $conn->query("DELETE FROM sondaggi WHERE id = $s_id");
        
        if (function_exists('registra_log_audit')) registra_log_audit($conn, "Eliminazione Sondaggio", ["Sondaggio ID" => $s_id, "Evento ID" => $ev_id]);
        
        flash_set("🗑️ Sondaggio e relativi risultati eliminati definitivamente!");
        admin_redirect("sondaggi.php?p_id=$filtro_p&f_sond_ev=$ev_id$url_suffix");
    }

    if (isset($_POST['invia_mail_sondaggi'])) {
        csrf_verify($_POST['csrf_token'] ?? '');
        $ev_id = (int)$_POST['evento_id'];
        
        $check_col = $conn->query("SHOW COLUMNS FROM prenotazioni LIKE 'token_sondaggio'");
        if ($check_col && $check_col->num_rows == 0) {
            $conn->query("ALTER TABLE prenotazioni ADD COLUMN token_sondaggio VARCHAR(64) NULL, ADD COLUMN sondaggio_completato TINYINT(1) DEFAULT 0");
        }

        $sys = $conn->query("SELECT email_sondaggio_oggetto, email_sondaggio_corpo FROM impostazioni_sistema WHERE id = 1")->fetch_assoc();
        $oggetto_base = $sys['email_sondaggio_oggetto'] ?: 'La tua opinione è importante! Sondaggio Evento: {TITOLO_EVENTO}';
        $corpo_base = $sys['email_sondaggio_corpo'] ?: '
            <p>Gentile <strong>{NOME} {COGNOME}</strong>,</p>
            <p>Ti ringraziamo per aver partecipato all\'evento <strong>{TITOLO_EVENTO}</strong>.</p>
            <p>La tua opinione per noi è fondamentale per migliorare continuamente le nostre attività. Ti invitiamo a dedicare un paio di minuti per compilare il nostro questionario di gradimento in forma <strong>totalmente anonima</strong>.</p>
            <div style="text-align: center; margin: 35px 0;">{LINK_SONDAGGIO}</div>
            <p style="color: #6c757d; font-size: 0.9em; border-top: 1px solid #eee; padding-top: 15px;">
                <em>💡 <strong>Nota:</strong> Puoi ritrovare questo questionario in qualsiasi momento anche accedendo alla tua <strong><a href="{LINK_AREA_PERSONALE}" style="color: #0056b3;">Area Personale</a></strong>, all\'interno della scheda "Sondaggi".</em>
            </p>
        ';
        
        $sql_pr = "SELECT pr.*, t.data_turno, t.orario_inizio, t.orario_fine, e.titolo as evento_titolo, e.luogo as evento_luogo, e.abilita_presenze 
                   FROM prenotazioni pr JOIN turni t ON pr.turno_id = t.id JOIN eventi e ON t.evento_id = e.id 
                   WHERE e.id = $ev_id AND IFNULL(pr.stato, 'confermata') = 'confermata'";
        
        $res_pr = $conn->query($sql_pr);
        $count = 0;
        
        if ($res_pr && $res_pr->num_rows > 0) {
            $domain = "https://" . ($_SERVER['HTTP_HOST'] ?? 'localhost') . rtrim(dirname(dirname($_SERVER['PHP_SELF'])), '/\\');
            
            while ($p = $res_pr->fetch_assoc()) {
                if (empty($p['email'])) continue;

                $presente = (int)$p['presente'];
                $abilita_presenze = (int)($p['abilita_presenze'] ?? 1);
                $sond_completato = (int)($p['sondaggio_completato'] ?? 0);

                if ($sond_completato === 1) continue;
                if ($abilita_presenze === 1 && $presente === 0) continue;

                $token_sond = $p['token_sondaggio'] ?? '';
                $pr_id = (int)$p['id'];
                if (empty($token_sond)) {
                    $token_sond = bin2hex(random_bytes(16));
                    $conn->query("UPDATE prenotazioni SET token_sondaggio = '$token_sond' WHERE id = $pr_id");
                }
                
                $url_sondaggio = $domain . "/sondaggio.php?token=" . $token_sond;
                $url_area_personale = $domain . "/area_personale.php";
                $btn_sondaggio = "<a href='$url_sondaggio' style='background-color:#17a2b8; color:#ffffff; padding:12px 24px; text-decoration:none; border-radius:6px; display:inline-block; font-weight:bold; font-size:16px;'>📝 Compila il Questionario</a>";
                
                $r_find = ['{NOME}', '{COGNOME}', '{MATRICOLA}', '{TITOLO_EVENTO}', '{DATA_TURNO}', '{ORARIO_TURNO}', '{LUOGO}', '{LINK_SONDAGGIO}', '{LINK_AREA_PERSONALE}'];
                $ora_f = substr($p['orario_inizio'],0,5).' - '.substr($p['orario_fine'],0,5);
                $r_repl = [$p['nome'], $p['cognome'], $p['matricola'], $p['evento_titolo'], date('d/m/Y', strtotime($p['data_turno'])), $ora_f, $p['evento_luogo'], $btn_sondaggio, $url_area_personale];
                
                inviaNotificaEmail($p['email'], str_replace($r_find, $r_repl, $oggetto_base), str_replace($r_find, $r_repl, $corpo_base), $conn);
                $count++;
            }
        }
        
        if (function_exists('registra_log_audit')) registra_log_audit($conn, "Invio Massivo Mail Sondaggio", ["Evento ID" => $ev_id, "Email Inviate" => $count]);
        flash_set("✅ Completato! Inviate $count email di invito al sondaggio.");
        admin_redirect("sondaggi.php?p_id=$filtro_p&f_sond_ev=$ev_id$url_suffix");
    }
} // Fine if (!$is_archivio)

// ==============================================================================
// PREPARAZIONE DATI FRONT-END
// ==============================================================================

$tutti_gli_eventi = [];
// FIX ERROR 500: Aggiunto `e.archiviato` alla query SELECT
$res_ev = $conn->query("SELECT id, titolo, archiviato FROM eventi e WHERE e.pagina_id = $filtro_p AND e.archiviato = $is_archivio $sql_filtro_eventi_rbac ORDER BY e.ordine ASC, e.id DESC");
if ($res_ev) {
    while($row = $res_ev->fetch_assoc()) {
        $tutti_gli_eventi[] = $row;
    }
}

$curr_sondaggio = null;
$curr_domande = [];
$statistiche_sondaggio = [];

if ($f_sond_ev > 0) {
    $res_s = $conn->query("SELECT * FROM sondaggi WHERE evento_id = $f_sond_ev LIMIT 1");
    if ($res_s && $res_s->num_rows > 0) {
        $curr_sondaggio = $res_s->fetch_assoc();
        
        $res_d = $conn->query("SELECT * FROM sondaggi_domande WHERE sondaggio_id = {$curr_sondaggio['id']} ORDER BY ordine ASC, id ASC");
        while ($d = $res_d->fetch_assoc()) { $curr_domande[] = $d; }

        $res_risp = $conn->query("SELECT r.*, d.tipo, d.testo_domanda FROM sondaggi_risposte r JOIN sondaggi_domande d ON r.domanda_id = d.id WHERE r.sondaggio_id = {$curr_sondaggio['id']}");
        if ($res_risp) {
            while ($r = $res_risp->fetch_assoc()) {
                $d_id = $r['domanda_id'];
                if (!isset($statistiche_sondaggio[$d_id])) {
                    $statistiche_sondaggio[$d_id] = [
                        'testo' => $r['testo_domanda'],
                        'tipo' => $r['tipo'],
                        'risposte' => [],
                        'totale_voti' => 0,
                        'somma_voti' => 0,
                        'conteggi_opzioni' => [],
                        'conteggi_matrice' => []
                    ];
                }
                
                if ($r['tipo'] === 'rating') {
                    $statistiche_sondaggio[$d_id]['totale_voti']++;
                    $statistiche_sondaggio[$d_id]['somma_voti'] += (int)$r['risposta'];
                } elseif ($r['tipo'] === 'matrice') {
                    $val_json = json_decode($r['risposta'], true);
                    if (is_array($val_json)) {
                        foreach ($val_json as $sub_item => $voto) {
                            if (!isset($statistiche_sondaggio[$d_id]['conteggi_matrice'][$sub_item])) {
                                $statistiche_sondaggio[$d_id]['conteggi_matrice'][$sub_item] = ['somma' => 0, 'tot' => 0];
                            }
                            $statistiche_sondaggio[$d_id]['conteggi_matrice'][$sub_item]['somma'] += (int)$voto;
                            $statistiche_sondaggio[$d_id]['conteggi_matrice'][$sub_item]['tot']++;
                        }
                    }
                    $statistiche_sondaggio[$d_id]['totale_voti']++;
                } elseif (in_array($r['tipo'], ['radio', 'select', 'checkbox', 'checkboxes'])) {
                    $val = trim($r['risposta']);
                    if (!isset($statistiche_sondaggio[$d_id]['conteggi_opzioni'][$val])) $statistiche_sondaggio[$d_id]['conteggi_opzioni'][$val] = 0;
                    $statistiche_sondaggio[$d_id]['conteggi_opzioni'][$val]++;
                    $statistiche_sondaggio[$d_id]['totale_voti']++;
                } else {
                    $statistiche_sondaggio[$d_id]['risposte'][] = $r['risposta'];
                }
            }
        }
    }
}
?>

<!-- FRONT-END DELLA PAGINA -->
<div class="d-flex justify-content-between align-items-center mb-4">
    <h4 class="fw-bold text-dark m-0">
        <?php if ($is_archivio): ?>
            <i class="fa fa-archive text-secondary me-2"></i> Archivio Sondaggi
        <?php else: ?>
            <i class="fa fa-star text-success me-2"></i> Sondaggi & Feedback
        <?php endif; ?>
    </h4>
    <?php if ($is_archivio): ?>
        <a href="archivio.php?p_id=<?php echo $filtro_p; ?>" class="btn btn-secondary btn-sm fw-bold shadow-sm"><i class="fa fa-arrow-left me-1"></i> Torna all'Archivio</a>
    <?php endif; ?>
</div>


<div class="card shadow-sm border-0 p-4 mb-4">
    <h5 class="fw-bold text-success border-bottom pb-2 mb-3">Customer Satisfaction e Questionari <?php echo $is_archivio ? '(Archivio Storico)' : 'Anonimi'; ?></h5>
    
    <div class="row align-items-center mb-4">
        <div class="col-md-8">
            <label class="form-label small fw-bold">Seleziona l'Evento per cui vuoi consultare il Sondaggio:</label>
            <form method="GET" id="formSondaggioEv" class="d-flex align-items-center gap-2 m-0">
                <input type="hidden" name="p_id" value="<?php echo $filtro_p; ?>">
                <?php if ($is_archivio): ?><input type="hidden" name="archivio" value="1"><?php endif; ?>
                <select name="f_sond_ev" class="form-select border-success fw-bold" onchange="document.getElementById('formSondaggioEv').submit();">
                    <option value="0">-- Seleziona un Evento --</option>
                    <?php foreach($tutti_gli_eventi as $e_opt): ?>
                        <option value="<?php echo $e_opt['id']; ?>" <?php echo $f_sond_ev == $e_opt['id'] ? 'selected' : ''; ?>>
                            <?php echo $e_opt['archiviato'] == 1 ? '🗄️ [ARCHIVIATO] ' : '🎯 '; ?><?php echo htmlspecialchars($e_opt['titolo']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </form>
        </div>
    </div>

    <?php if ($f_sond_ev > 0): ?>
        <?php if (!$curr_sondaggio): ?>
            <div class="alert alert-light border shadow-sm text-center p-4">
                <i class="fa fa-info-circle text-muted fs-1 mb-2 d-block"></i>
                <h5 class="fw-bold text-dark">Nessun sondaggio trovato per questo evento.</h5>
                
                <?php if (!$is_archivio): ?>
                    <p class="text-secondary mb-3">Creando un questionario di gradimento, gli studenti potranno valutarlo in forma totalmente anonima.</p>
                    <form method="POST" class="d-flex flex-column align-items-center">
                        <?php csrf_field(); ?>
                        <input type="hidden" name="evento_id" value="<?php echo $f_sond_ev; ?>">
                        <input type="text" name="titolo_sondaggio" class="form-control mb-2" style="max-width: 400px;" placeholder="Es. Valutazione Evento" required>
                        <button type="submit" name="add_sondaggio" class="btn btn-success fw-bold"><i class="fa fa-plus me-1"></i> Crea e Configura Sondaggio</button>
                    </form>
                <?php else: ?>
                    <p class="text-secondary m-0">Non è stato generato alcuno storico di questionari per questa attività passata.</p>
                <?php endif; ?>
            </div>
            
        <?php else: ?>
            <!-- GESTIONE SONDAGGIO ESISTENTE -->
            <div class="border p-4 bg-light rounded shadow-sm mb-4 border-success">
                <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 border-bottom pb-3 mb-3">
                    <div>
                        <h5 class="fw-bold text-dark m-0 mb-1">📋 <?php echo htmlspecialchars($curr_sondaggio['titolo']); ?></h5>
                        <?php if($curr_sondaggio['attivo']): ?>
                            <span class="badge bg-success shadow-sm">ATTIVO - Gli utenti possono votare</span>
                        <?php else: ?>
                            <span class="badge bg-secondary shadow-sm">DISATTIVATO - Le votazioni sono chiuse</span>
                        <?php endif; ?>
                    </div>
                    <div class="d-flex gap-2">
                        
                        <button type="button" class="btn btn-info btn-sm fw-bold text-white shadow-sm" data-bs-toggle="modal" data-bs-target="#modAnteprimaSondaggio">
                            <i class="fa fa-eye"></i> Anteprima
                        </button>
                        
                        <?php if (!$is_archivio): ?>
                            <?php if($curr_sondaggio['attivo']): ?>
                                <a href="?toggle_sondaggio=<?php echo $curr_sondaggio['id']; ?>&val=0&p_id=<?php echo $filtro_p; ?>&ev_id=<?php echo $f_sond_ev; ?>" class="btn btn-outline-secondary btn-sm fw-bold">Sospendi Sondaggio</a>
                            <?php else: ?>
                                <a href="?toggle_sondaggio=<?php echo $curr_sondaggio['id']; ?>&val=1&p_id=<?php echo $filtro_p; ?>&ev_id=<?php echo $f_sond_ev; ?>" class="btn btn-success btn-sm fw-bold shadow-sm">Attiva Sondaggio</a>
                            <?php endif; ?>
                            
                            <a href="?del_sondaggio=<?php echo $curr_sondaggio['id']; ?>&p_id=<?php echo $filtro_p; ?>&ev_id=<?php echo $f_sond_ev; ?>" class="btn btn-danger btn-sm fw-bold shadow-sm" data-confirm="⚠️ ATTENZIONE! Sei sicuro di voler eliminare l\'intero sondaggio?\n\nVerranno rimosse tutte le domande e tutte le RISPOSTE salvate degli utenti. L\'operazione è irreversibile.">
                                <i class="fa fa-trash"></i> Elimina
                            </a>
                        <?php endif; ?>
                    </div>
                </div>

                <?php if (!$is_archivio): ?>
                <!-- FORM AGGIUNGI DOMANDA -->
                <form method="POST" class="row g-2 mb-4 bg-white p-3 border rounded shadow-sm">
                    <?php csrf_field(); ?>
                    <h6 class="fw-bold text-success mb-2 w-100"><i class="fa fa-plus-circle me-1"></i> Aggiungi Domanda al Questionario</h6>
                    <input type="hidden" name="sondaggio_id" value="<?php echo $curr_sondaggio['id']; ?>">
                    <input type="hidden" name="evento_id" value="<?php echo $f_sond_ev; ?>">
                    
                    <div class="col-md-6">
                        <input type="text" name="testo_domanda" class="form-control form-control-sm" placeholder="Es. Valuta l'organizzazione dell'evento" required>
                    </div>
                    
                    <div class="col-md-4">
                        <select name="tipo_domanda" id="tipoDomanda" class="form-select form-select-sm fw-bold text-primary border-primary">
                            <option value="rating">Voto Classico (1 a 5 Stelle)</option>
                            <option value="matrice">Matrice di Valutazione (Più righe da 1 a 5)</option>
                            <option value="radio">Scelta Singola (Radio)</option>
                            <option value="select">Menu a Tendina (Select)</option>
                            <option value="checkboxes">Scelta Multipla (Checkbox)</option>
                            <option value="text">Testo Breve</option>
                            <option value="textarea">Commento Esteso</option>
                        </select>
                    </div>
                    
                    <div class="col-md-2">
                        <button type="submit" name="add_domanda_sondaggio" class="btn btn-primary btn-sm w-100 fw-bold">Aggiungi</button>
                    </div>
                    
                    <div class="col-md-12 mt-2 d-none" id="boxOpzioni">
                        <label class="form-label small fw-bold text-primary mb-1" id="labelOpzioni">Inserisci le opzioni separandole con una virgola:</label>
                        <input type="text" name="opzioni" id="inputOpzioni" class="form-control form-control-sm border-primary" placeholder="Es. Molto, Abbastanza, Poco, Per nulla">
                    </div>
                </form>

                <script>
                    document.getElementById('tipoDomanda').addEventListener('change', function() {
                        var val = this.value;
                        var box = document.getElementById('boxOpzioni');
                        var inp = document.getElementById('inputOpzioni');
                        var lbl = document.getElementById('labelOpzioni');
                        
                        if(val === 'radio' || val === 'select' || val === 'checkboxes' || val === 'matrice') {
                            box.classList.remove('d-none');
                            inp.setAttribute('required', 'required');
                            if (val === 'matrice') {
                                lbl.innerHTML = '<i class="fa fa-list me-1"></i> Inserisci le righe da valutare separandole con una virgola:';
                                inp.placeholder = 'Es. Qualità audio, Preparazione relatore, Aule';
                            } else {
                                lbl.innerHTML = '<i class="fa fa-list me-1"></i> Inserisci le opzioni a scelta separandole con una virgola:';
                                inp.placeholder = 'Es. Molto, Abbastanza, Poco, Per nulla';
                            }
                        } else {
                            box.classList.add('d-none');
                            inp.removeAttribute('required');
                        }
                    });
                </script>
                <?php endif; ?>

                <!-- LISTA DOMANDE (Sola Lettura in Archivio) -->
                <h6 class="fw-bold text-dark mt-4 mb-3"><i class="fa fa-list me-1"></i> Struttura Questionario:</h6>
                <?php if (empty($curr_domande)): ?>
                    <div class="text-muted small">Nessuna domanda inserita.</div>
                <?php else: ?>
                    <ul class="list-group shadow-sm">
                        <?php foreach ($curr_domande as $index => $d): ?>
                            <li class="list-group-item d-flex justify-content-between align-items-center bg-white">
                                <div>
                                    <strong class="me-2"><?php echo ($index + 1); ?>.</strong> <?php echo htmlspecialchars($d['testo_domanda']); ?> 
                                    <span class="badge bg-secondary ms-2"><?php echo $d['tipo']; ?></span>
                                    
                                    <?php if(!empty($d['opzioni'])): ?>
                                        <div class="small text-muted mt-1"><i class="fa fa-list-ul me-1"></i> 
                                            <?php echo ($d['tipo'] === 'matrice') ? 'Righe matrice: ' : 'Opzioni: '; ?> 
                                            <em><?php echo htmlspecialchars($d['opzioni']); ?></em>
                                        </div>
                                    <?php endif; ?>
                                </div>
                                <?php if (!$is_archivio): ?>
                                    <a href="?del_domanda_sondaggio=<?php echo $d['id']; ?>&p_id=<?php echo $filtro_p; ?>&ev_id=<?php echo $f_sond_ev; ?>" class="btn btn-outline-danger btn-sm px-2 py-0" data-confirm="Eliminare questa domanda?"><i class="fa fa-trash"></i></a>
                                <?php endif; ?>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>

                <!-- RISULTATI SONDAGGIO -->
                <hr class="my-5">
                <div class="d-flex justify-content-between align-items-center mb-3 border-bottom pb-2">
                    <h5 class="fw-bold text-primary m-0"><i class="fa fa-chart-line me-1"></i> Risultati e Statistiche del Questionario</h5>
                    <form method="POST" class="m-0">
                        <?php csrf_field(); ?>
                        <input type="hidden" name="sondaggio_id_export" value="<?php echo $curr_sondaggio['id']; ?>">
                        <button type="submit" name="export_sondaggio_xls" class="btn btn-success fw-bold shadow-sm">
                            <i class="fa fa-file-excel me-1"></i> Esporta in Excel
                        </button>
                    </form>
                </div>
                
                <?php if (!empty($statistiche_sondaggio)): ?>
                    <div class="row g-3">
                        <?php foreach($statistiche_sondaggio as $d_id => $stat): ?>
                            <div class="col-md-6">
                                <div class="card border-0 shadow-sm h-100 bg-white">
                                    <div class="card-header bg-white fw-bold text-dark border-bottom-0 pt-3 pb-1" style="font-size: 0.9rem;">
                                        <?php echo htmlspecialchars($stat['testo']); ?>
                                    </div>
                                    <div class="card-body pt-2">
                                        <?php if($stat['tipo'] === 'rating'): ?>
                                            <?php $media = $stat['totale_voti'] > 0 ? round($stat['somma_voti'] / $stat['totale_voti'], 1) : 0; ?>
                                            <div class="text-center py-3">
                                                <h2 class="fw-black text-warning m-0" style="font-size: 3.5rem;"><?php echo number_format($media, 1, ',', '.'); ?> <small class="text-muted fs-6">/ 5</small></h2>
                                                <div class="mt-1 mb-2">
                                                    <?php for($i=1; $i<=5; $i++): ?>
                                                        <i class="fa fa-star <?php echo $i <= round($media) ? 'text-warning' : 'text-light'; ?> fs-3"></i>
                                                    <?php endfor; ?>
                                                </div>
                                                <p class="small text-muted m-0 fw-bold">Basato su <?php echo $stat['totale_voti']; ?> voti</p>
                                            </div>

                                        <?php elseif($stat['tipo'] === 'matrice'): ?>
                                            <div class="mt-2">
                                                <?php if(!empty($stat['conteggi_matrice'])): ?>
                                                    <?php foreach($stat['conteggi_matrice'] as $sub_item => $dati): ?>
                                                        <?php $media = $dati['tot'] > 0 ? round($dati['somma'] / $dati['tot'], 1) : 0; ?>
                                                        <div class="mb-3">
                                                            <div class="d-flex justify-content-between align-items-center mb-1 small fw-bold text-secondary">
                                                                <span><?php echo htmlspecialchars($sub_item); ?></span>
                                                                <span class="text-warning"><i class="fa fa-star"></i> <?php echo number_format($media, 1, ',', '.'); ?> / 5</span>
                                                            </div>
                                                            <div class="progress" style="height: 6px;">
                                                                <div class="progress-bar bg-warning" role="progressbar" style="width: <?php echo ($media / 5) * 100; ?>%;"></div>
                                                            </div>
                                                        </div>
                                                    <?php endforeach; ?>
                                                    <p class="small text-muted m-0 mt-2 fw-bold text-end">Basato su <?php echo $stat['totale_voti']; ?> schede compilate</p>
                                                <?php else: ?>
                                                    <p class="small text-muted">Dati matrice non ancora disponibili.</p>
                                                <?php endif; ?>
                                            </div>

                                        <?php elseif(in_array($stat['tipo'], ['radio', 'select', 'checkbox', 'checkboxes'])): ?>
                                            <div class="mt-2">
                                                <?php foreach($stat['conteggi_opzioni'] as $opz => $count): ?>
                                                    <?php $perc = $stat['totale_voti'] > 0 ? round(($count / $stat['totale_voti']) * 100) : 0; ?>
                                                    <div class="d-flex justify-content-between align-items-center mb-1 small fw-bold text-secondary">
                                                        <span><?php echo htmlspecialchars($opz); ?></span>
                                                        <span><?php echo $perc; ?>% (<?php echo $count; ?>)</span>
                                                    </div>
                                                    <div class="progress mb-3" style="height: 8px;">
                                                        <div class="progress-bar bg-primary" role="progressbar" style="width: <?php echo $perc; ?>%;"></div>
                                                    </div>
                                                <?php endforeach; ?>
                                                <p class="small text-muted m-0 mt-2 fw-bold text-end">Voti totali: <?php echo $stat['totale_voti']; ?></p>
                                            </div>

                                        <?php else: ?>
                                            <div style="max-height: 200px; overflow-y: auto;" class="border rounded p-3 bg-light">
                                                <?php foreach(array_reverse($stat['risposte']) as $risp): ?>
                                                    <div class="small border-bottom border-white py-2 text-dark">
                                                        <i class="fa fa-comment-dots text-secondary me-2"></i> <?php echo nl2br(htmlspecialchars($risp)); ?>
                                                    </div>
                                                <?php endforeach; ?>
                                                <?php if(empty($stat['risposte'])) echo "<span class='small text-muted'>Nessun commento testuale inserito.</span>"; ?>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <div class="alert alert-secondary text-center p-4 border shadow-sm mt-3">
                        <i class="fa fa-inbox mb-2 fs-2 d-block text-muted"></i> 
                        Nessun utente ha ancora compilato questo questionario.
                    </div>
                <?php endif; ?>
                
                <!-- BOTTONE MANUALE INVIO MAIL SONDAGGIO A TUTTI GLI ISCRITTI DELL'EVENTO -->
                <?php if(!$is_archivio && $curr_sondaggio['attivo']): ?>
                <form method="POST" class="mt-4 border-top pt-4 text-end" onsubmit="return confirm('Vuoi inviare una mail con l\'invito al sondaggio a TUTTI gli iscritti confermati di questo evento?');">
                    <?php csrf_field(); ?>
                    <input type="hidden" name="evento_id" value="<?php echo $f_sond_ev; ?>">
                    <button type="submit" name="invia_mail_sondaggi" class="btn btn-warning btn-lg fw-bold text-dark shadow-sm">
                        <i class="fa fa-paper-plane me-1"></i> Invia Invito al Sondaggio a Tutti
                    </button>
                    <p class="small text-muted mt-2 mb-0"><i class="fa fa-info-circle me-1"></i> L'email verrà inviata usando il template impostato in "Sistema Email".</p>
                </form>
                <?php endif; ?>

            </div>
        <?php endif; ?>
    <?php else: ?>
        <div class="alert alert-secondary text-center p-4">Seleziona un evento dal menu a tendina qui sopra per iniziare.</div>
    <?php endif; ?>
</div>

<!-- MODALE ANTEPRIMA SONDAGGIO (VISUALE PUBBLICA) -->
<?php if ($curr_sondaggio): ?>
<div class="modal fade" id="modAnteprimaSondaggio" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content shadow-lg border-0" style="border-radius: 12px; border-top: 5px solid #17a2b8 !important;">
            <div class="modal-header py-3 bg-light border-bottom">
                <h5 class="modal-title fw-bold text-dark"><i class="fa fa-eye text-info me-2"></i> Anteprima: <?php echo htmlspecialchars($curr_sondaggio['titolo']); ?></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-4 bg-light text-start">
                
                <div class="alert alert-warning text-center fw-bold shadow-sm mb-4">
                    <i class="fa fa-exclamation-triangle me-1"></i> Questa è solo un'anteprima visiva. I campi sono disabilitati.
                </div>
                
                <?php if (empty($curr_domande)): ?>
                    <div class="text-center text-muted py-4">Nessuna domanda inserita. Aggiungine una per vederla in anteprima.</div>
                <?php else: ?>
                    <?php foreach ($curr_domande as $index => $d): ?>
                        <?php $ast = $d['obbligatorio'] ? '<span class="text-danger">*</span>' : ''; ?>
                        <div class="bg-white p-4 rounded shadow-sm border mb-4">
                            <h6 class="fw-bold mb-3 text-dark fs-6">
                                <span class="badge bg-danger me-2"><?php echo ($index + 1); ?></span> 
                                <?php echo htmlspecialchars($d['testo_domanda']) . $ast; ?>
                            </h6>
                            
                            <div class="mt-3">
                                <?php if ($d['tipo'] === 'rating'): ?>
                                    <div class="d-flex flex-wrap gap-2">
                                        <?php for($i=1; $i<=5; $i++): ?>
                                            <div class="btn btn-outline-warning text-dark fw-bold px-3 py-1 disabled" style="border-color: #dee2e6; opacity: 1;">
                                                <i class="fa fa-star text-warning d-block mb-1 fs-5"></i> <?php echo $i; ?>
                                            </div>
                                        <?php endfor; ?>
                                    </div>
                                    
                                <?php elseif ($d['tipo'] === 'matrice' && !empty($d['opzioni'])): ?>
                                    <div class="table-responsive">
                                        <table class="table table-bordered align-middle mb-0">
                                            <thead class="table-light">
                                                <tr>
                                                    <th style="width: 40%;">Aspetto</th>
                                                    <th class="text-center">1</th><th class="text-center">2</th><th class="text-center">3</th><th class="text-center">4</th><th class="text-center">5</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach (explode(',', $d['opzioni']) as $riga): $riga = trim($riga); if (empty($riga)) continue; ?>
                                                    <tr>
                                                        <td class="fw-bold text-secondary"><?php echo htmlspecialchars($riga); ?></td>
                                                        <?php for($v=1; $v<=5; $v++): ?>
                                                            <td class="text-center"><input class="form-check-input" type="radio" disabled style="width:18px; height:18px;"></td>
                                                        <?php endfor; ?>
                                                    </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                    
                                <?php elseif ($d['tipo'] === 'textarea'): ?>
                                    <textarea class="form-control" rows="3" placeholder="Scrivi qui il tuo feedback..." disabled></textarea>
                                    
                                <?php elseif ($d['tipo'] === 'select' && !empty($d['opzioni'])): ?>
                                    <select class="form-select border-primary" disabled>
                                        <option>-- Seleziona un'opzione --</option>
                                        <?php foreach (explode(',', $d['opzioni']) as $opt): ?>
                                            <option><?php echo htmlspecialchars(trim($opt)); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                    
                                <?php elseif ($d['tipo'] === 'radio' && !empty($d['opzioni'])): ?>
                                    <div class="d-flex flex-column gap-2">
                                        <?php foreach (explode(',', $d['opzioni']) as $opt): ?>
                                            <div class="form-check p-2 border rounded bg-light m-0">
                                                <input class="form-check-input ms-1" type="radio" disabled>
                                                <label class="form-check-label ms-2 fw-bold text-dark"><?php echo htmlspecialchars(trim($opt)); ?></label>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                    
                                <?php elseif ($d['tipo'] === 'checkboxes' && !empty($d['opzioni'])): ?>
                                    <div class="d-flex flex-column gap-2">
                                        <?php foreach (explode(',', $d['opzioni']) as $opt): ?>
                                            <div class="form-check p-2 border rounded bg-light m-0">
                                                <input class="form-check-input ms-1" type="checkbox" disabled>
                                                <label class="form-check-label ms-2 fw-bold text-dark"><?php echo htmlspecialchars(trim($opt)); ?></label>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                    
                                <?php else: ?>
                                    <input type="text" class="form-control" placeholder="Tua risposta" disabled>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                    
                    <div class="text-center mt-4">
                        <button class="btn btn-danger btn-lg fw-bold px-5 disabled" style="background-color: #990000; opacity: 0.6;">
                            <i class="fa fa-paper-plane me-2"></i> Invia Valutazione
                        </button>
                    </div>
                <?php endif; ?>
                
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<?php require_once 'admin_footer.php'; ?>
