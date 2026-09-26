<?php
// progetti.php - Progetti (es. Formazione Scuola Lavoro): scheda completa del progetto e iscrizione della scuola.
// Un progetto è un evento con tipo = 'progetto', una scheda in progetti_dettagli e un solo turno di iscrizione
// da 1 posto con lista d'attesa: la prima scuola è confermata, le altre attendono in ordine di arrivo.
// Iscritti, messaggi, archivio, form builder e notifiche funzionano come per gli altri eventi.
require_once 'admin_header.php';

if (!$can_manage_eventi) {
    echo "<div class='alert alert-danger fw-bold shadow-sm'><i class='fa fa-ban me-2'></i> Accesso negato. Non hai i permessi per gestire i progetti in quest'area.</div>";
    require_once 'admin_footer.php';
    exit;
}

function admin_redirect($url) { echo "<script>window.location.replace(" . json_encode($url) . ");</script>"; exit; }

set_exception_handler(function (Throwable $e) {
    error_log('[admin/progetti.php] ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    echo "<div class='alert alert-danger fw-bold m-4'><i class='fa fa-bug me-2'></i>Errore durante il salvataggio: " . h($e->getMessage()) . "</div>";
    exit;
});

$puo_creare = $is_full_admin || $is_area_manager;

// Progetto dell'area corrente, visibile al gestore
function progetto_autorizzato($conn, int $ev_id, int $p_id, string $rbac): bool {
    $r = $conn->query("SELECT 1 FROM eventi e WHERE e.id = $ev_id AND e.pagina_id = $p_id AND e.tipo = 'progetto' $rbac LIMIT 1");
    return $r && $r->num_rows > 0;
}

// Valori del form: stringa vuota -> null
function post_testo(string $k, int $max = 255): ?string {
    $v = trim((string)($_POST[$k] ?? ''));
    return $v === '' ? null : mb_substr($v, 0, $max);
}
function post_intero(string $k): ?int {
    $v = trim((string)($_POST[$k] ?? ''));
    return ($v === '' || !preg_match('/^\d+$/', $v)) ? null : (int)$v;
}
function post_data(string $k): ?string {
    $v = trim((string)($_POST[$k] ?? ''));
    $d = DateTime::createFromFormat('!Y-m-d', $v);
    return ($d && $d->format('Y-m-d') === $v) ? $v : null;
}
function post_data_ora(string $k): ?string {
    $v = trim((string)($_POST[$k] ?? ''));
    if ($v === '') return null;
    $d = DateTime::createFromFormat('Y-m-d\TH:i', $v) ?: DateTime::createFromFormat('Y-m-d H:i:s', $v);
    return $d ? $d->format('Y-m-d H:i:s') : null;
}

// =====================================================================
// SALVATAGGIO (nuovo o modifica)
// =====================================================================
if (isset($_POST['salva_progetto'])) {
    csrf_verify($_POST['csrf_token'] ?? '');
    $ev_id = (int)($_POST['evento_id'] ?? 0);
    if ($ev_id > 0 && !progetto_autorizzato($conn, $ev_id, $filtro_p, $sql_filtro_eventi_rbac)) nega_accesso();
    if ($ev_id === 0 && !$puo_creare) nega_accesso();
    $url_form = "progetti.php?p_id=$filtro_p&" . ($ev_id ? "id=$ev_id" : "azione=nuovo");

    $titolo = post_testo('titolo');
    if ($titolo === null) { flash_set("Il titolo del progetto è obbligatorio.", 'danger'); admin_redirect($url_form); }
    $luogo  = post_testo('luogo') ?? '';
    $desc   = (string)($_POST['descrizione'] ?? '');
    $ord    = (int)($_POST['ordine'] ?? 0);
    $evid   = isset($_POST['is_evidenza']) ? 1 : 0;

    $d = [
        'struttura'         => post_testo('struttura') ?? '',
        'data_inizio'       => post_data('data_inizio'),
        'data_fine'         => post_data('data_fine'),
        'periodo_note'      => post_testo('periodo_note') ?? '',
        'destinatari'       => post_testo('destinatari') ?? '',
        'modalita'          => post_testo('modalita', 100) ?? '',
        'ore_totali'        => post_intero('ore_totali'),
        'incontri_previsti' => post_intero('incontri_previsti'),
        'min_studenti'      => post_intero('min_studenti'),
        'max_studenti'      => post_intero('max_studenti'),
    ];
    $apertura = post_data_ora('data_apertura');
    $chiusura = post_data_ora('data_chiusura');

    $errori = [];
    if ($d['data_inizio'] && $d['data_fine'] && $d['data_fine'] < $d['data_inizio']) $errori[] = "la fine del progetto è precedente all'inizio";
    if ($apertura && $chiusura && $chiusura <= $apertura) $errori[] = "la chiusura delle iscrizioni è precedente all'apertura";
    if ($d['min_studenti'] !== null && $d['max_studenti'] !== null && $d['min_studenti'] > $d['max_studenti']) $errori[] = "il numero minimo di studenti supera il massimo";
    if ($errori) { flash_set("Progetto non salvato: " . implode('; ', $errori) . ".", 'danger'); admin_redirect($url_form); }

    // Referenti e tutor: righe con almeno nome o email; email non valide scartate e segnalate
    $referenti = []; $email_scartate = [];
    foreach ((array)($_POST['ref_nome'] ?? []) as $i => $nome_r) {
        $r = [
            'ruolo'    => mb_substr(trim((string)($_POST['ref_ruolo'][$i] ?? '')), 0, 60),
            'nome'     => mb_substr(trim((string)$nome_r), 0, 120),
            'email'    => mb_substr(strtolower(trim((string)($_POST['ref_email'][$i] ?? ''))), 0, 150),
            'telefono' => mb_substr(trim((string)($_POST['ref_tel'][$i] ?? '')), 0, 40),
            'link'     => mb_substr(trim((string)($_POST['ref_link'][$i] ?? '')), 0, 300),
            // Riceve il riepilogo di ogni iscrizione e disdetta (come i gestori)
            'notifiche' => (($_POST['ref_notifiche'][$i] ?? '0') === '1') ? 1 : 0,
        ];
        if ($r['email'] !== '' && !filter_var($r['email'], FILTER_VALIDATE_EMAIL)) { $email_scartate[] = $r['email']; $r['email'] = ''; }
        if ($r['telefono'] !== '' && !preg_match('/^[0-9 +().\/-]{5,40}$/', $r['telefono'])) $r['telefono'] = '';
        // Pagina personale: solo indirizzi http(s), es. https://www.unical.it/... ("www.…" senza schema diventa https://)
        if ($r['link'] !== '' && !preg_match('#^https?://#i', $r['link'])) $r['link'] = 'https://' . $r['link'];
        if ($r['link'] !== '' && !filter_var($r['link'], FILTER_VALIDATE_URL)) $r['link'] = '';
        if ($r['email'] === '') $r['notifiche'] = 0;
        if ($r['nome'] === '' && $r['email'] === '') continue;
        $referenti[] = $r;
        if (count($referenti) >= 10) break;
    }
    // Informazioni aggiuntive: coppie etichetta / valore
    $info_extra = [];
    foreach ((array)($_POST['info_etichetta'] ?? []) as $i => $et) {
        $et = mb_substr(trim((string)$et), 0, 80);
        $va = mb_substr(trim((string)($_POST['info_valore'][$i] ?? '')), 0, 500);
        if ($et === '' || $va === '') continue;
        $info_extra[] = ['etichetta' => $et, 'valore' => $va];
        if (count($info_extra) >= 20) break;
    }
    // Articolazione del percorso: moduli / fasi / incontri (serve almeno il titolo)
    $moduli = []; $somma_ore = 0;
    foreach ((array)($_POST['mod_titolo'] ?? []) as $i => $tit) {
        $m = [
            'titolo'      => mb_substr(trim((string)$tit), 0, 200),
            'ore'         => preg_match('/^\d{1,3}$/', trim((string)($_POST['mod_ore'][$i] ?? ''))) ? (int)$_POST['mod_ore'][$i] : null,
            'modalita'    => mb_substr(trim((string)($_POST['mod_modalita'][$i] ?? '')), 0, 60),
            'sede'        => mb_substr(trim((string)($_POST['mod_sede'][$i] ?? '')), 0, 200),
            'quando'      => mb_substr(trim((string)($_POST['mod_quando'][$i] ?? '')), 0, 100),
            'descrizione' => mb_substr(trim((string)($_POST['mod_desc'][$i] ?? '')), 0, 2000),
        ];
        if ($m['titolo'] === '') continue;
        $moduli[] = $m; $somma_ore += (int)$m['ore'];
        if (count($moduli) >= 30) break;
    }
    // Ore totali non indicate: somma delle ore dei moduli
    if ($d['ore_totali'] === null && $somma_ore > 0) $d['ore_totali'] = $somma_ore;
    // Obiettivi / conoscenze / competenze: testo con editor (come la descrizione)
    $obiettivi  = trim((string)($_POST['obiettivi'] ?? '')) ?: null;
    $conoscenze = trim((string)($_POST['conoscenze'] ?? '')) ?: null;
    $competenze = trim((string)($_POST['competenze'] ?? '')) ?: null;

    // Edizioni (repliche): ogni riga è un turno da 1 scuola. ed_id = turno esistente (0 = nuova)
    $edizioni = [];
    foreach ((array)($_POST['ed_nome'] ?? []) as $i => $nome_ed) {
        $edizioni[] = ['id' => (int)($_POST['ed_id'][$i] ?? 0), 'nome' => mb_substr(trim((string)$nome_ed), 0, 150)];
        if (count($edizioni) >= 20) break;
    }
    if (!$edizioni) $edizioni[] = ['id' => 0, 'nome' => ''];

    $upload_dir = dirname(__DIR__) . '/uploads/';
    $new_locandina = null; $new_pdf = null;
    if (!empty($_FILES['locandina_file']['name'])) {
        $fn = secure_upload($_FILES['locandina_file'], $upload_dir, ['jpg','jpeg','png','gif','webp'], ['image/jpeg','image/png','image/gif','image/webp']);
        if ($fn) $new_locandina = "uploads/$fn";
    }
    if (!empty($_FILES['allegato_pdf']['name'])) {
        $fn = secure_upload($_FILES['allegato_pdf'], $upload_dir, ['pdf'], ['application/pdf']);
        if ($fn) $new_pdf = "uploads/$fn";
    }

    $notif_extra = normalizza_lista_email($_POST['email_notifiche_extra'] ?? '', 10, $notif_scartati);
    $notif_csv = $notif_extra ? implode(',', $notif_extra) : null;
    $ref_json  = $referenti ? json_encode($referenti, JSON_UNESCAPED_UNICODE) : null;
    $info_json = $info_extra ? json_encode($info_extra, JSON_UNESCAPED_UNICODE) : null;
    $mod_json  = $moduli ? json_encode($moduli, JSON_UNESCAPED_UNICODE) : null;
    $edizioni_non_tolte = [];

    $conn->begin_transaction();
    try {
        if ($ev_id === 0) {
            // Iscrizione solo con accesso (SSO Unical, SPID, CIE): ruolo_accesso_id = -1
            $stmt = $conn->prepare("INSERT INTO eventi (pagina_id, sottocategoria_id, titolo, luogo, descrizione, locandina_path, allegato_pdf, is_evidenza, richiede_prenotazione, abilita_presenze, ruolo_accesso_id, ordine, gestori_utenti_ids, tipo, email_notifiche_extra)
                                    VALUES (?, NULL, ?, ?, ?, ?, ?, ?, 1, 0, -1, ?, '', 'progetto', ?)");
            $loc = $new_locandina ?? '';
            $stmt->bind_param("isssssiis", $filtro_p, $titolo, $luogo, $desc, $loc, $new_pdf, $evid, $ord, $notif_csv);
            if (!$stmt->execute()) throw new RuntimeException($conn->error);
            $ev_id = (int)$conn->insert_id;
        } else {
            $sql = "UPDATE eventi SET titolo=?, luogo=?, descrizione=?, is_evidenza=?, ordine=?, email_notifiche_extra=?, richiede_prenotazione=1, ruolo_accesso_id=-1";
            $types = "sssiis"; $params = [$titolo, $luogo, $desc, $evid, $ord, $notif_csv];
            if (isset($_POST['elimina_locandina'])) $sql .= ", locandina_path=''";
            if (isset($_POST['elimina_pdf']))       $sql .= ", allegato_pdf=NULL";
            if ($new_locandina !== null) { $sql .= ", locandina_path=?"; $types .= "s"; $params[] = $new_locandina; }
            if ($new_pdf !== null)       { $sql .= ", allegato_pdf=?";   $types .= "s"; $params[] = $new_pdf; }
            $sql .= " WHERE id=?"; $types .= "i"; $params[] = $ev_id;
            $stmt = $conn->prepare($sql);
            $stmt->bind_param($types, ...$params);
            if (!$stmt->execute()) throw new RuntimeException($conn->error);
        }

        $stmt_d = $conn->prepare("INSERT INTO progetti_dettagli (evento_id, struttura, data_inizio, data_fine, periodo_note, destinatari, modalita, ore_totali, incontri_previsti, min_studenti, max_studenti, referenti_json, info_extra_json, moduli_json, obiettivi, conoscenze, competenze, updated_at)
                                  VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
                                  ON DUPLICATE KEY UPDATE struttura=VALUES(struttura), data_inizio=VALUES(data_inizio), data_fine=VALUES(data_fine), periodo_note=VALUES(periodo_note),
                                      destinatari=VALUES(destinatari), modalita=VALUES(modalita), ore_totali=VALUES(ore_totali), incontri_previsti=VALUES(incontri_previsti),
                                      min_studenti=VALUES(min_studenti), max_studenti=VALUES(max_studenti),
                                      referenti_json=VALUES(referenti_json), info_extra_json=VALUES(info_extra_json), moduli_json=VALUES(moduli_json),
                                      obiettivi=VALUES(obiettivi), conoscenze=VALUES(conoscenze), competenze=VALUES(competenze), updated_at=NOW()");
        $stmt_d->bind_param("issssssiiiissssss", $ev_id, $d['struttura'], $d['data_inizio'], $d['data_fine'], $d['periodo_note'], $d['destinatari'], $d['modalita'],
                            $d['ore_totali'], $d['incontri_previsti'], $d['min_studenti'], $d['max_studenti'], $ref_json, $info_json, $mod_json, $obiettivi, $conoscenze, $competenze);
        if (!$stmt_d->execute()) throw new RuntimeException($conn->error);

        // Edizioni = turni di iscrizione: 1 scuola ciascuno, lista d'attesa attiva, niente approvazione (ordine di arrivo).
        // Apertura e chiusura delle iscrizioni sono le stesse per tutte le edizioni.
        $turni_esistenti = [];
        $r_t = $conn->query("SELECT id FROM turni WHERE evento_id = $ev_id ORDER BY id ASC");
        while ($r_t && $rt = $r_t->fetch_assoc()) $turni_esistenti[] = (int)$rt['id'];
        $tenuti = [];
        $stmt_up = $conn->prepare("UPDATE turni SET nome_turno = ?, max_posti = 1, data_apertura = ?, data_chiusura = ?, abilita_lista_attesa = 1, abilita_multi_posto = 0, richiede_approvazione = 0 WHERE id = ?");
        $stmt_in = $conn->prepare("INSERT INTO turni (evento_id, nome_turno, data_turno, orario_inizio, orario_fine, max_posti, data_apertura, data_chiusura, abilita_lista_attesa, abilita_multi_posto, richiede_approvazione)
                                   VALUES (?, ?, NULL, NULL, NULL, 1, ?, ?, 1, 0, 0)");
        $n_ed = count($edizioni);
        foreach ($edizioni as $k => $ed) {
            // Nome dell'edizione: quello scritto, altrimenti "Edizione N" (o "Iscrizione scuole" se è l'unica)
            $nome_ed = $ed['nome'] !== '' ? $ed['nome'] : ($n_ed > 1 ? 'Edizione ' . ($k + 1) : 'Iscrizione scuole');
            if ($ed['id'] > 0 && in_array($ed['id'], $turni_esistenti, true)) {
                $stmt_up->bind_param("sssi", $nome_ed, $apertura, $chiusura, $ed['id']);
                if (!$stmt_up->execute()) throw new RuntimeException($conn->error);
                $tenuti[] = $ed['id'];
            } else {
                $stmt_in->bind_param("isss", $ev_id, $nome_ed, $apertura, $chiusura);
                if (!$stmt_in->execute()) throw new RuntimeException($conn->error);
                $tenuti[] = (int)$conn->insert_id;
            }
        }
        // Edizioni tolte dalla maschera: eliminate solo se nessuna scuola è iscritta o in attesa
        foreach (array_diff($turni_esistenti, $tenuti) as $t_via) {
            $r_n = $conn->query("SELECT COUNT(*) AS n FROM prenotazioni WHERE turno_id = $t_via AND IFNULL(stato, 'confermata') NOT IN ('annullata', 'rifiutata', 'scaduta')");
            if ($r_n && (int)$r_n->fetch_assoc()['n'] > 0) {
                $stmt_fin = $conn->prepare("UPDATE turni SET data_apertura = ?, data_chiusura = ? WHERE id = ?"); // resta, con la stessa finestra delle altre
                $stmt_fin->bind_param("ssi", $apertura, $chiusura, $t_via);
                $stmt_fin->execute();
                $r_nome = $conn->query("SELECT nome_turno FROM turni WHERE id = $t_via");
                $edizioni_non_tolte[] = (string)($r_nome ? $r_nome->fetch_assoc()['nome_turno'] : $t_via);
                continue;
            }
            elimina_turno($conn, $t_via);
        }

        assicura_campi_progetto($conn, $filtro_p);
        $conn->commit();
    } catch (Throwable $e) {
        $conn->rollback();
        throw $e;
    }

    $avvisi = [];
    if ($notif_scartati) $avvisi[] = "indirizzi per le notifiche non validi ignorati: " . implode(', ', $notif_scartati);
    if ($email_scartate) $avvisi[] = "email dei referenti non valide ignorate: " . implode(', ', $email_scartate);
    if ($edizioni_non_tolte) $avvisi[] = "non ho eliminato le edizioni con scuole iscritte o in attesa (" . implode(', ', $edizioni_non_tolte) . "): annulla prima le loro iscrizioni";
    registra_log_audit($conn, (int)($_POST['evento_id'] ?? 0) ? "Modifica Progetto" : "Creazione Progetto", ["Evento ID" => $ev_id, "Titolo" => $titolo]);
    flash_set("Progetto salvato." . ($avvisi ? " Attenzione: " . implode('; ', $avvisi) . "." : ''), $avvisi ? 'warning' : 'success');
    admin_redirect("progetti.php?p_id=$filtro_p");
}

if (isset($_POST['duplica_progetto'])) {
    csrf_verify($_POST['csrf_token'] ?? '');
    $ev_id = (int)$_POST['duplica_progetto'];
    if (!$puo_creare || !progetto_autorizzato($conn, $ev_id, $filtro_p, $sql_filtro_eventi_rbac)) nega_accesso();
    $copia = duplica_evento($conn, $ev_id, true);
    registra_log_audit($conn, "Duplicazione Progetto", ["Progetto origine" => $ev_id, "Nuovo progetto" => $copia['evento']]);
    flash_set("Progetto duplicato senza iscrizioni: controlla titolo e date della copia.");
    admin_redirect("progetti.php?p_id=$filtro_p&id=" . (int)$copia['evento']);
}
if (isset($_POST['archivia_progetto'])) {
    csrf_verify($_POST['csrf_token'] ?? '');
    $ev_id = (int)$_POST['archivia_progetto'];
    if (!$can_manage_settings || !progetto_autorizzato($conn, $ev_id, $filtro_p, $sql_filtro_eventi_rbac)) nega_accesso();
    $conn->query("UPDATE eventi SET archiviato = 1, blocca_auto_archivio = 0 WHERE id = $ev_id");
    registra_log_audit($conn, "Archiviazione Progetto", ["Evento ID" => $ev_id]);
    flash_set("Progetto archiviato: lo trovi in Archivio Storico.");
    admin_redirect("progetti.php?p_id=$filtro_p");
}
if (isset($_POST['elimina_progetto'])) {
    csrf_verify($_POST['csrf_token'] ?? '');
    $ev_id = (int)$_POST['elimina_progetto'];
    if (!$can_manage_settings || !progetto_autorizzato($conn, $ev_id, $filtro_p, $sql_filtro_eventi_rbac)) nega_accesso();
    $ok = elimina_evento($conn, $ev_id);
    registra_log_audit($conn, "Eliminazione Progetto", ["Evento ID" => $ev_id]);
    flash_set($ok ? "Progetto eliminato." : "Eliminazione non riuscita: riprova.", $ok ? 'success' : 'danger');
    admin_redirect("progetti.php?p_id=$filtro_p");
}

$col_area = colore_valido($page_cfg['colore_primario'] ?? '', '#0056B3');
$txt_area = colore_testo_su($col_area);
$slug_area = $page_cfg['slug'] ?? '';
$layout_progetti = ($page_cfg['layout_template'] ?? '') === 'progetti';

// =====================================================================
// FORM (nuovo o modifica)
// =====================================================================
$id_modifica = (int)($_GET['id'] ?? 0);
$mostra_form = ($_GET['azione'] ?? '') === 'nuovo' || $id_modifica > 0;

if ($mostra_form):
    if ($id_modifica > 0) {
        if (!progetto_autorizzato($conn, $id_modifica, $filtro_p, $sql_filtro_eventi_rbac)) nega_accesso();
        $ev = $conn->query("SELECT * FROM eventi WHERE id = $id_modifica")->fetch_assoc();
        $dp = get_dettagli_progetti($conn, [$id_modifica])[$id_modifica] ?? [];
        $turni_ed = [];
        $r_t = $conn->query("SELECT t.*, (SELECT COUNT(*) FROM prenotazioni p WHERE p.turno_id = t.id AND IFNULL(p.stato, 'confermata') NOT IN ('annullata', 'rifiutata', 'scaduta')) AS n_iscr FROM turni t WHERE t.evento_id = $id_modifica ORDER BY t.id ASC");
        while ($r_t && $rt = $r_t->fetch_assoc()) $turni_ed[] = $rt;
        $tu = $turni_ed[0] ?? [];
    } else {
        if (!$puo_creare) nega_accesso();
        $ev = []; $dp = []; $tu = []; $turni_ed = [];
    }
    if (!$turni_ed) $turni_ed = [['id' => 0, 'nome_turno' => '', 'n_iscr' => 0]];
    $moduli = $dp['moduli'] ?? [];
    if (!$moduli) $moduli = [[]];
    $v  = fn($a, $k) => h((string)($a[$k] ?? ''));
    $dt = fn($x) => !empty($x) ? date('Y-m-d\TH:i', strtotime($x)) : '';
    $referenti  = $dp['referenti'] ?? [];
    $info_extra = $dp['info_extra'] ?? [];
    if (!$referenti) $referenti = [['ruolo' => 'Referente CdL', 'notifiche' => 1], ['ruolo' => 'Responsabile Unical', 'notifiche' => 0]];
?>
<style>
.pj-sez { background:#fff; border:1px solid #e2e8f0; border-radius:12px; padding:1.25rem 1.25rem .75rem; margin-bottom:1rem; box-shadow:0 1px 4px rgba(0,0,0,.04); }
.pj-sez h2 { font-size:.8rem; text-transform:uppercase; letter-spacing:.06em; font-weight:800; color:<?php echo h($col_area); ?>; margin-bottom:1rem; }
.pj-sez .form-text { font-size:.76rem; }
.pj-riga { display:grid; grid-template-columns: 150px 1fr 1fr 150px 38px; gap:.5rem; margin-bottom:.5rem; }
.pj-riga-info { display:grid; grid-template-columns: 220px 1fr 38px; gap:.5rem; margin-bottom:.5rem; }
.pj-ref { padding-bottom:.6rem; margin-bottom:.6rem; border-bottom:1px dashed #e2e8f0; }
.pj-ref .pj-riga { margin-bottom:.4rem; }
.pj-mod { padding-bottom:.6rem; margin-bottom:.6rem; border-bottom:1px dashed #e2e8f0; display:grid; gap:.4rem; }
.pj-mod-riga { display:grid; grid-template-columns: 1fr 80px 170px 38px; gap:.5rem; }
.pj-mod-riga2 { display:grid; grid-template-columns: 1fr 1fr; gap:.5rem; padding-right:46px; }
.pj-ed { display:flex; gap:.5rem; align-items:center; margin-bottom:.5rem; }
.pj-riga-2 { display:grid; grid-template-columns: 1fr auto; gap:.75rem; align-items:center; padding-right:46px; }
@media (max-width: 767.98px) { .pj-riga, .pj-riga-info, .pj-riga-2, .pj-mod-riga, .pj-mod-riga2 { grid-template-columns: 1fr; padding-right:0; } .pj-riga-info { padding-bottom:.5rem; border-bottom:1px dashed #e2e8f0; } }
</style>

<div class="d-flex align-items-center justify-content-between mb-3 flex-wrap gap-2">
    <h4 class="fw-bold text-dark mb-0"><i class="fa fa-diagram-project me-2" style="color:<?php echo h($col_area); ?>" aria-hidden="true"></i><?php echo $id_modifica ? 'Modifica progetto' : 'Nuovo progetto'; ?></h4>
    <a href="progetti.php?p_id=<?php echo $filtro_p; ?>" class="btn btn-outline-secondary btn-sm fw-bold"><i class="fa fa-arrow-left me-1" aria-hidden="true"></i>Torna ai progetti</a>
</div>

<form method="POST" enctype="multipart/form-data" onsubmit="if (window.tinymce) tinymce.triggerSave();">
    <?php csrf_field(); ?>
    <input type="hidden" name="evento_id" value="<?php echo $id_modifica; ?>">
    <input type="hidden" name="p_id" value="<?php echo $filtro_p; ?>">

    <div class="row g-3">
        <div class="col-xl-8">
            <section class="pj-sez">
                <h2><i class="fa fa-circle-info me-1" aria-hidden="true"></i>Dati generali</h2>
                <div class="row g-3">
                    <div class="col-12">
                        <label for="pjTitolo" class="form-label small fw-bold">Titolo del progetto <span class="text-danger">*</span></label>
                        <input type="text" name="titolo" id="pjTitolo" class="form-control" value="<?php echo $v($ev, 'titolo'); ?>" maxlength="255" required>
                    </div>
                    <div class="col-md-7">
                        <label for="pjStruttura" class="form-label small fw-bold">Corso di laurea / Struttura</label>
                        <input type="text" name="struttura" id="pjStruttura" class="form-control form-control-sm" value="<?php echo $v($dp, 'struttura'); ?>" placeholder="es. Corso di laurea in Scienze Geologiche" maxlength="255">
                    </div>
                    <div class="col-md-5">
                        <label for="pjLuogo" class="form-label small fw-bold">Sede</label>
                        <input type="text" name="luogo" id="pjLuogo" class="form-control form-control-sm" value="<?php echo $v($ev, 'luogo'); ?>" placeholder="es. Cubo 4B, Unical" maxlength="255">
                    </div>
                    <div class="col-12">
                        <label for="pjDesc" class="form-label small fw-bold">Descrizione (obiettivi, moduli, calendario, contatti…)</label>
                        <textarea name="descrizione" id="pjDesc" class="form-control editor-html" rows="10"><?php echo h($ev['descrizione'] ?? ''); ?></textarea>
                    </div>
                </div>
            </section>

            <section class="pj-sez">
                <h2><i class="fa fa-list-ul me-1" aria-hidden="true"></i>Dettagli del progetto</h2>
                <div class="row g-3">
                    <div class="col-md-6">
                        <label for="pjDest" class="form-label small fw-bold">Requisiti di accesso (classi ammesse)</label>
                        <input type="text" name="destinatari" id="pjDest" class="form-control form-control-sm" value="<?php echo $v($dp, 'destinatari'); ?>" placeholder="es. Classi 3ª, 4ª e 5ª" maxlength="255">
                    </div>
                    <div class="col-md-6">
                        <label for="pjMod" class="form-label small fw-bold">Modalità</label>
                        <input type="text" name="modalita" id="pjMod" class="form-control form-control-sm" value="<?php echo $v($dp, 'modalita'); ?>" list="pjModalita" placeholder="es. In presenza" maxlength="100">
                        <datalist id="pjModalita"><option value="In presenza"><option value="Online"><option value="Mista (presenza e online)"></datalist>
                    </div>
                    <div class="col-6 col-md-3">
                        <label for="pjOre" class="form-label small fw-bold">Ore totali</label>
                        <input type="number" name="ore_totali" id="pjOre" class="form-control form-control-sm" min="1" value="<?php echo $v($dp, 'ore_totali'); ?>">
                    </div>
                    <div class="col-6 col-md-3">
                        <label for="pjInc" class="form-label small fw-bold">Incontri previsti</label>
                        <input type="number" name="incontri_previsti" id="pjInc" class="form-control form-control-sm" min="1" value="<?php echo $v($dp, 'incontri_previsti'); ?>">
                    </div>
                    <div class="col-6 col-md-3">
                        <label for="pjMin" class="form-label small fw-bold">Studenti per scuola: min</label>
                        <input type="number" name="min_studenti" id="pjMin" class="form-control form-control-sm" min="1" value="<?php echo $v($dp, 'min_studenti'); ?>">
                    </div>
                    <div class="col-6 col-md-3">
                        <label for="pjMax" class="form-label small fw-bold">Studenti per scuola: max</label>
                        <input type="number" name="max_studenti" id="pjMax" class="form-control form-control-sm" min="1" value="<?php echo $v($dp, 'max_studenti'); ?>">
                    </div>
                    <div class="col-12"><div class="form-text mt-0">La scuola indica nel modulo di iscrizione il numero minimo e massimo dei suoi studenti: il portale controlla che stiano in questi limiti.</div></div>
                </div>
            </section>

            <section class="pj-sez">
                <h2><i class="fa fa-route me-1" aria-hidden="true"></i>Articolazione del percorso (moduli, fasi, incontri)</h2>
                <p class="form-text mt-0 mb-2">Una riga per modulo, fase, incontro o attività. Nella scheda diventano le tappe del percorso. Se non compili "Ore totali", il portale somma le ore dei moduli.</p>
                <div id="pjModuli">
                    <?php foreach ($moduli as $m): ?>
                        <div class="pj-mod">
                            <div class="pj-mod-riga">
                                <input type="text" name="mod_titolo[]" class="form-control form-control-sm" value="<?php echo h($m['titolo'] ?? ''); ?>" placeholder="Titolo (es. Incontro 1: Introduzione alla cartografia)" aria-label="Titolo del modulo" maxlength="200">
                                <input type="number" name="mod_ore[]" class="form-control form-control-sm" value="<?php echo h((string)($m['ore'] ?? '')); ?>" placeholder="Ore" aria-label="Ore" min="0" max="999">
                                <input type="text" name="mod_modalita[]" class="form-control form-control-sm" value="<?php echo h($m['modalita'] ?? ''); ?>" list="pjModalitaMod" placeholder="Modalità" aria-label="Modalità" maxlength="60">
                                <button type="button" class="btn btn-sm btn-outline-danger pj-rimuovi" title="Rimuovi" aria-label="Rimuovi modulo"><i class="fa fa-times" aria-hidden="true"></i></button>
                            </div>
                            <div class="pj-mod-riga2">
                                <input type="text" name="mod_sede[]" class="form-control form-control-sm" value="<?php echo h($m['sede'] ?? ''); ?>" placeholder="Sede (es. Laboratorio di Cartografia DiBEST)" aria-label="Sede" maxlength="200">
                                <input type="text" name="mod_quando[]" class="form-control form-control-sm" value="<?php echo h($m['quando'] ?? ''); ?>" placeholder="Quando (es. 26/10/2026, ottobre 2026, da definire)" aria-label="Quando" maxlength="100">
                            </div>
                            <textarea name="mod_desc[]" class="form-control form-control-sm" rows="2" placeholder="Breve descrizione (facoltativa)" aria-label="Descrizione del modulo" maxlength="2000"><?php echo h($m['descrizione'] ?? ''); ?></textarea>
                        </div>
                    <?php endforeach; ?>
                </div>
                <datalist id="pjModalitaMod"><option value="Online"><option value="In presenza"><option value="Laboratorio"><option value="Online / presenza"><option value="Uscita sul campo"><option value="A scuola"></datalist>
                <button type="button" class="btn btn-sm btn-outline-secondary fw-bold mb-2" data-pj-aggiungi="pjModuli"><i class="fa fa-plus me-1" aria-hidden="true"></i>Aggiungi modulo</button>
            </section>

            <section class="pj-sez">
                <h2><i class="fa fa-bullseye me-1" aria-hidden="true"></i>Obiettivi, conoscenze e competenze</h2>
                <p class="form-text mt-0 mb-2">Facoltativi: se compilati compaiono come riquadri separati nella scheda.</p>
                <label for="pjObi" class="form-label small fw-bold">Obiettivi formativi</label>
                <textarea name="obiettivi" id="pjObi" class="form-control editor-html mb-3" rows="4"><?php echo h($dp['obiettivi'] ?? ''); ?></textarea>
                <label for="pjCon" class="form-label small fw-bold mt-3">Conoscenze</label>
                <textarea name="conoscenze" id="pjCon" class="form-control editor-html mb-3" rows="4"><?php echo h($dp['conoscenze'] ?? ''); ?></textarea>
                <label for="pjComp" class="form-label small fw-bold mt-3">Competenze attese</label>
                <textarea name="competenze" id="pjComp" class="form-control editor-html" rows="4"><?php echo h($dp['competenze'] ?? ''); ?></textarea>
            </section>

            <section class="pj-sez">
                <h2><i class="fa fa-address-book me-1" aria-hidden="true"></i>Referenti, responsabili e relatori</h2>
                <p class="form-text mt-0 mb-2">Il link alla pagina personale rende cliccabile il nome nella scheda. Chi ha <strong>"Riceve le iscrizioni"</strong> attivo riceve per email il riepilogo di ogni iscrizione e disdetta della scuola (serve l'email).</p>
                <div class="d-none d-md-grid pj-riga small fw-bold text-secondary mb-1"><span>Ruolo</span><span>Nome e cognome</span><span>Email</span><span>Telefono</span><span></span></div>
                <div id="pjReferenti">
                    <?php foreach ($referenti as $r): $notif_r = !empty($r['notifiche']); ?>
                        <div class="pj-ref">
                            <div class="pj-riga">
                                <input type="text" name="ref_ruolo[]" class="form-control form-control-sm" value="<?php echo h($r['ruolo'] ?? ''); ?>" list="pjRuoli" placeholder="Ruolo" aria-label="Ruolo">
                                <input type="text" name="ref_nome[]" class="form-control form-control-sm" value="<?php echo h($r['nome'] ?? ''); ?>" placeholder="Nome e cognome" aria-label="Nome e cognome">
                                <input type="email" name="ref_email[]" class="form-control form-control-sm" value="<?php echo h($r['email'] ?? ''); ?>" placeholder="nome@unical.it" aria-label="Email">
                                <input type="tel" name="ref_tel[]" class="form-control form-control-sm" value="<?php echo h($r['telefono'] ?? ''); ?>" placeholder="Telefono (facoltativo)" aria-label="Telefono">
                                <button type="button" class="btn btn-sm btn-outline-danger pj-rimuovi" title="Rimuovi" aria-label="Rimuovi persona"><i class="fa fa-times" aria-hidden="true"></i></button>
                            </div>
                            <div class="pj-riga-2">
                                <input type="url" name="ref_link[]" class="form-control form-control-sm" value="<?php echo h($r['link'] ?? ''); ?>" placeholder="Link alla pagina personale (facoltativo), es. https://www.unical.it/..." aria-label="Link alla pagina personale">
                                <input type="hidden" name="ref_notifiche[]" value="<?php echo $notif_r ? '1' : '0'; ?>">
                                <label class="form-check form-switch m-0 small fw-bold text-nowrap"><input class="form-check-input pj-notif" type="checkbox" <?php echo $notif_r ? 'checked' : ''; ?>> Riceve le iscrizioni</label>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
                <datalist id="pjRuoli"><option value="Referente CdL"><option value="Responsabile Unical"><option value="Relatore"><option value="Tutor"><option value="Referente"><option value="Segreteria"></datalist>
                <button type="button" class="btn btn-sm btn-outline-secondary fw-bold mb-2" data-pj-aggiungi="pjReferenti"><i class="fa fa-plus me-1" aria-hidden="true"></i>Aggiungi persona</button>
            </section>

            <section class="pj-sez">
                <h2><i class="fa fa-table-list me-1" aria-hidden="true"></i>Informazioni aggiuntive</h2>
                <p class="form-text mt-0 mb-2">Voci libere mostrate nella scheda del progetto (es. "Attestato: rilasciato a fine percorso", "Materiale: a cura del dipartimento").</p>
                <div id="pjInfo">
                    <?php foreach (($info_extra ?: [['etichetta' => '', 'valore' => '']]) as $ie): ?>
                        <div class="pj-riga-info">
                            <input type="text" name="info_etichetta[]" class="form-control form-control-sm" value="<?php echo h($ie['etichetta'] ?? ''); ?>" placeholder="Voce" aria-label="Voce" maxlength="80">
                            <input type="text" name="info_valore[]" class="form-control form-control-sm" value="<?php echo h($ie['valore'] ?? ''); ?>" placeholder="Valore" aria-label="Valore" maxlength="500">
                            <button type="button" class="btn btn-sm btn-outline-danger pj-rimuovi" title="Rimuovi" aria-label="Rimuovi riga"><i class="fa fa-times" aria-hidden="true"></i></button>
                        </div>
                    <?php endforeach; ?>
                </div>
                <button type="button" class="btn btn-sm btn-outline-secondary fw-bold mb-2" data-pj-aggiungi="pjInfo"><i class="fa fa-plus me-1" aria-hidden="true"></i>Aggiungi voce</button>
            </section>
        </div>

        <div class="col-xl-4">
            <section class="pj-sez">
                <h2><i class="fa fa-calendar-days me-1" aria-hidden="true"></i>Durata del progetto</h2>
                <div class="row g-2">
                    <div class="col-6"><label for="pjIni" class="form-label small fw-bold">Dal</label><input type="date" name="data_inizio" id="pjIni" class="form-control form-control-sm" value="<?php echo $v($dp, 'data_inizio'); ?>"></div>
                    <div class="col-6"><label for="pjFin" class="form-label small fw-bold">Al</label><input type="date" name="data_fine" id="pjFin" class="form-control form-control-sm" value="<?php echo $v($dp, 'data_fine'); ?>"></div>
                    <div class="col-12">
                        <label for="pjNote" class="form-label small fw-bold">Note sul periodo</label>
                        <input type="text" name="periodo_note" id="pjNote" class="form-control form-control-sm" value="<?php echo $v($dp, 'periodo_note'); ?>" placeholder="es. Calendario da concordare con la scuola" maxlength="255">
                        <div class="form-text">Senza date il progetto compare come <strong>"Date da definire"</strong>.</div>
                    </div>
                </div>
            </section>

            <section class="pj-sez">
                <h2><i class="fa fa-school me-1" aria-hidden="true"></i>Edizioni e iscrizione della scuola</h2>
                <p class="form-text mt-0 mb-2">Una riga per edizione (le "repliche"): ogni edizione accoglie <strong>una scuola</strong>, le altre vanno in lista d'attesa. Con più edizioni la scuola sceglie quella che preferisce.</p>
                <div id="pjEdizioni">
                    <?php foreach ($turni_ed as $k => $te): ?>
                        <div class="pj-ed">
                            <input type="hidden" name="ed_id[]" value="<?php echo (int)$te['id']; ?>">
                            <input type="text" name="ed_nome[]" class="form-control form-control-sm" value="<?php echo h($te['nome_turno'] ?? ''); ?>" placeholder="es. Edizione 1 – ottobre 2026" aria-label="Nome dell'edizione" maxlength="150">
                            <?php if ((int)($te['n_iscr'] ?? 0) > 0): ?><span class="badge bg-success-subtle text-success-emphasis" title="Scuole iscritte o in attesa"><i class="fa fa-school" aria-hidden="true"></i> <?php echo (int)$te['n_iscr']; ?></span><?php endif; ?>
                            <button type="button" class="btn btn-sm btn-outline-danger pj-rimuovi" title="Rimuovi edizione" aria-label="Rimuovi edizione"><i class="fa fa-times" aria-hidden="true"></i></button>
                        </div>
                    <?php endforeach; ?>
                </div>
                <button type="button" class="btn btn-sm btn-outline-secondary fw-bold mb-3" data-pj-aggiungi="pjEdizioni"><i class="fa fa-plus me-1" aria-hidden="true"></i>Aggiungi edizione</button>
                <div class="row g-2">
                    <div class="col-12"><label for="pjAp" class="form-label small fw-bold">Apertura iscrizioni</label><input type="datetime-local" name="data_apertura" id="pjAp" class="form-control form-control-sm" value="<?php echo $dt($tu['data_apertura'] ?? ''); ?>"></div>
                    <div class="col-12"><label for="pjCh" class="form-label small fw-bold">Chiusura iscrizioni</label><input type="datetime-local" name="data_chiusura" id="pjCh" class="form-control form-control-sm" value="<?php echo $dt($tu['data_chiusura'] ?? ''); ?>"></div>
                </div>
                <div class="alert alert-light border small mt-3 mb-2">
                    <i class="fa fa-circle-info me-1" aria-hidden="true"></i>
                    Le iscrizioni vanno in <strong>ordine di arrivo</strong>; una scuola può iscriversi a <strong>una sola edizione</strong> dello stesso progetto.
                    L'iscrizione la fa il docente referente con <strong>SPID, CIE o credenziali Unical</strong>.
                    Le domande del modulo (scuola, classe, contatti…) si impostano dal <a href="form_builder.php?p_id=<?php echo $filtro_p; ?>">Form Builder</a>.
                </div>
            </section>

            <section class="pj-sez">
                <h2><i class="fa fa-paperclip me-1" aria-hidden="true"></i>Immagine e allegati</h2>
                <label for="pjLoc" class="form-label small fw-bold">Immagine (JPG, PNG, WEBP)</label>
                <input type="file" name="locandina_file" id="pjLoc" class="form-control form-control-sm" accept="image/png,image/jpeg,image/gif,image/webp">
                <?php if (!empty($ev['locandina_path'])): ?>
                    <div class="d-flex align-items-center gap-2 mt-2">
                        <img src="../<?php echo h($ev['locandina_path']); ?>" alt="" style="height:48px;border-radius:6px;object-fit:cover;">
                        <div class="form-check m-0"><input class="form-check-input" type="checkbox" name="elimina_locandina" id="pjDelLoc" value="1"><label class="form-check-label small text-danger fw-bold" for="pjDelLoc">Rimuovi</label></div>
                    </div>
                <?php endif; ?>
                <label for="pjPdf" class="form-label small fw-bold mt-3">Programma / calendario (PDF)</label>
                <input type="file" name="allegato_pdf" id="pjPdf" class="form-control form-control-sm" accept="application/pdf">
                <?php if (!empty($ev['allegato_pdf'])): ?>
                    <div class="d-flex align-items-center gap-2 mt-2">
                        <a href="../<?php echo h($ev['allegato_pdf']); ?>" target="_blank" rel="noopener" class="btn btn-sm btn-outline-secondary py-0"><i class="fa fa-eye me-1" aria-hidden="true"></i>Vedi</a>
                        <div class="form-check m-0"><input class="form-check-input" type="checkbox" name="elimina_pdf" id="pjDelPdf" value="1"><label class="form-check-label small text-danger fw-bold" for="pjDelPdf">Rimuovi</label></div>
                    </div>
                <?php endif; ?>
            </section>

            <section class="pj-sez">
                <h2><i class="fa fa-sliders me-1" aria-hidden="true"></i>Pubblicazione e notifiche</h2>
                <div class="row g-2 align-items-end">
                    <div class="col-5"><label for="pjOrd" class="form-label small fw-bold">Ordine</label><input type="number" name="ordine" id="pjOrd" class="form-control form-control-sm" value="<?php echo (int)($ev['ordine'] ?? 0); ?>"></div>
                    <div class="col-7 pb-1"><div class="form-check form-switch"><input class="form-check-input" type="checkbox" name="is_evidenza" id="pjEvid" value="1" <?php echo !empty($ev['is_evidenza']) ? 'checked' : ''; ?>><label class="form-check-label small fw-bold" for="pjEvid">In evidenza</label></div></div>
                    <div class="col-12 mt-3">
                        <label for="pjNotif" class="form-label small fw-bold">Invia copia delle iscrizioni a</label>
                        <input type="text" name="email_notifiche_extra" id="pjNotif" class="form-control form-control-sm" value="<?php echo h(implode(', ', normalizza_lista_email($ev['email_notifiche_extra'] ?? ''))); ?>" placeholder="es. segreteria@unical.it">
                        <div class="form-text">Oltre ai gestori. Separa gli indirizzi con una virgola, massimo 10.</div>
                    </div>
                </div>
            </section>

            <div class="d-grid gap-2 mb-4">
                <button type="submit" name="salva_progetto" value="1" class="btn fw-bold py-2" style="background:<?php echo h($col_area); ?>;color:<?php echo $txt_area; ?>;"><i class="fa fa-save me-1" aria-hidden="true"></i>Salva progetto</button>
                <?php if ($id_modifica && $slug_area): ?>
                    <a href="../<?php echo h($slug_area); ?>.php?progetto=<?php echo $id_modifica; ?>" target="_blank" rel="noopener" class="btn btn-outline-secondary fw-bold"><i class="fa fa-up-right-from-square me-1" aria-hidden="true"></i>Vedi la scheda pubblica</a>
                <?php endif; ?>
            </div>
        </div>
    </div>
</form>

<script>
document.addEventListener('click', function (e) {
    var add = e.target.closest('[data-pj-aggiungi]');
    if (add) {
        var box = document.getElementById(add.dataset.pjAggiungi);
        var modello = box.lastElementChild;
        if (!modello) return;
        var nuova = modello.cloneNode(true);
        svuota(nuova);
        box.appendChild(nuova);
        nuova.querySelector('input').focus();
        return;
    }
    var rim = e.target.closest('.pj-rimuovi');
    if (rim) {
        var riga = rim.closest('.pj-ref, .pj-riga-info, .pj-mod, .pj-ed'), box2 = riga.parentElement;
        if (box2.children.length > 1) riga.remove();
        else svuota(riga);
    }
});
// Riga vuota: testi cancellati, "Riceve le iscrizioni" spento
function svuota(riga) {
    riga.querySelectorAll('.badge').forEach(function (b) { b.remove(); });
    riga.querySelectorAll('textarea').forEach(function (t) { t.value = ''; });
    riga.querySelectorAll('input').forEach(function (i) {
        if (i.type === 'checkbox') i.checked = false;
        else if (i.type === 'hidden') i.value = '0';
        else i.value = '';
    });
}
// L'interruttore aggiorna il campo nascosto della stessa riga (le checkbox non spuntate non vengono inviate)
document.addEventListener('change', function (e) {
    if (!e.target.classList.contains('pj-notif')) return;
    e.target.closest('.pj-riga-2').querySelector('input[name="ref_notifiche[]"]').value = e.target.checked ? '1' : '0';
});
</script>

<?php
    require_once 'admin_footer.php';
    exit;
endif;

// =====================================================================
// ELENCO DEI PROGETTI
// =====================================================================
$progetti = [];
$res = $conn->query("SELECT e.* FROM eventi e WHERE e.pagina_id = $filtro_p AND e.archiviato = 0 AND e.tipo = 'progetto' $sql_filtro_eventi_rbac ORDER BY e.ordine ASC, e.id DESC");
if ($res) while ($r = $res->fetch_assoc()) $progetti[(int)$r['id']] = $r;
$dettagli = get_dettagli_progetti($conn, array_keys($progetti));

foreach ($progetti as $id => &$p) {
    $turni_p = [];
    $r_t = $conn->query("SELECT * FROM turni WHERE evento_id = $id ORDER BY id ASC");
    while ($r_t && $rt = $r_t->fetch_assoc()) $turni_p[] = $rt;
    $p['turno'] = $turni_p[0] ?? null; // finestra di iscrizione (uguale per tutte le edizioni)
    $p['dett']  = $dettagli[$id] ?? [];
    $p['ied']   = info_edizioni_progetto($conn, $p['dett'], $turni_p);
    $p['stato'] = $p['ied']['stato'];
}
unset($p);

$n_eventi_normali = (int)($conn->query("SELECT COUNT(*) AS n FROM eventi WHERE pagina_id = $filtro_p AND archiviato = 0 AND IFNULL(tipo, 'evento') <> 'progetto'")->fetch_assoc()['n'] ?? 0);
?>
<style>
.pj-card { background:#fff; border:1px solid #e2e8f0; border-radius:12px; box-shadow:0 2px 8px rgba(0,0,0,.05); overflow:hidden; }
.pj-card + .pj-card { margin-top:.75rem; }
.pj-stato { display:inline-block; font-size:.72rem; font-weight:700; padding:.25rem .6rem; border-radius:999px; }
.pj-meta { font-size:.8rem; color:#475569; }
.pj-meta i { color:#94a3b8; width:14px; }
</style>

<div class="d-flex align-items-center justify-content-between mb-3 flex-wrap gap-2">
    <h4 class="fw-bold text-dark mb-0"><i class="fa fa-diagram-project me-2" style="color:<?php echo h($col_area); ?>" aria-hidden="true"></i>Progetti</h4>
    <div class="d-flex gap-2 flex-wrap">
        <a href="form_builder.php?p_id=<?php echo $filtro_p; ?>" class="btn btn-outline-secondary btn-sm fw-bold"><i class="fa fa-list-check me-1" aria-hidden="true"></i>Modulo di iscrizione</a>
        <?php if ($puo_creare): ?>
            <a href="progetti.php?p_id=<?php echo $filtro_p; ?>&azione=nuovo" class="btn btn-sm fw-bold px-3" style="background:<?php echo h($col_area); ?>;color:<?php echo $txt_area; ?>;"><i class="fa fa-plus-circle me-1" aria-hidden="true"></i>Nuovo progetto</a>
        <?php endif; ?>
    </div>
</div>

<?php if (!$layout_progetti && $can_manage_settings): ?>
    <div class="alert alert-info small py-2"><i class="fa fa-lightbulb me-1" aria-hidden="true"></i>
        Per mostrare i progetti come elenco con scheda di dettaglio, scegli il layout <strong>Progetti</strong> in <a href="impostazioni_area.php?p_id=<?php echo $filtro_p; ?>" class="alert-link">Impostazioni area</a>.
    </div>
<?php endif; ?>
<?php if ($n_eventi_normali > 0): ?>
    <div class="alert alert-light border small py-2"><i class="fa fa-calendar-alt me-1" aria-hidden="true"></i>In quest'area ci sono anche <?php echo $n_eventi_normali; ?> eventi normali: li trovi in <a href="eventi.php?p_id=<?php echo $filtro_p; ?>">Eventi e Turni</a>.</div>
<?php endif; ?>

<?php if (!$progetti): ?>
    <div class="pj-card p-5 text-center text-muted">
        <i class="fa fa-diagram-project fs-1 mb-3 d-block" style="opacity:.3;" aria-hidden="true"></i>
        <div class="fw-semibold">Nessun progetto in quest'area.</div>
        <?php if ($puo_creare): ?><a href="progetti.php?p_id=<?php echo $filtro_p; ?>&azione=nuovo" class="btn btn-sm btn-outline-secondary fw-bold mt-3">Crea il primo progetto</a><?php endif; ?>
    </div>
<?php endif; ?>

<?php foreach ($progetti as $id => $p):
    $d = $p['dett']; $t = $p['turno']; $st = $p['stato'];
?>
    <div class="pj-card d-flex">
        <div style="width:5px;flex-shrink:0;background:<?php echo h($col_area); ?>;"></div>
        <div class="flex-grow-1 p-3">
            <div class="d-flex justify-content-between align-items-start gap-2 flex-wrap">
                <div style="min-width:0;">
                    <span class="pj-stato" style="background:<?php echo $st['bg']; ?>;color:<?php echo $st['fg']; ?>;"><?php echo h($st['etichetta']); ?></span>
                    <?php if (!empty($p['is_evidenza'])): ?><span class="badge bg-warning text-dark ms-1" style="font-size:.65rem;">⭐ EVIDENZA</span><?php endif; ?>
                    <div class="fw-bold fs-6 text-dark mt-1"><?php echo h($p['titolo']); ?></div>
                    <div class="pj-meta d-flex flex-wrap gap-3 mt-1">
                        <span><i class="fa fa-calendar-days" aria-hidden="true"></i> <?php echo h(periodo_progetto($d)); ?></span>
                        <?php if (!empty($d['struttura'])): ?><span><i class="fa fa-building-columns" aria-hidden="true"></i> <?php echo h($d['struttura']); ?></span><?php endif; ?>
                        <?php if ($t): ?><span><i class="fa fa-door-open" aria-hidden="true"></i> Iscrizioni: <?php
                            echo !empty($t['data_apertura']) ? 'dal ' . date('d/m/Y H:i', strtotime($t['data_apertura'])) : 'già aperte';
                            echo !empty($t['data_chiusura']) ? ' al ' . date('d/m/Y H:i', strtotime($t['data_chiusura'])) : '';
                        ?></span><?php endif; ?>
                        <?php if (!empty($d['min_studenti']) || !empty($d['max_studenti'])): ?><span><i class="fa fa-users" aria-hidden="true"></i> <?php echo (int)($d['min_studenti'] ?? 0) ?: 1; ?>–<?php echo !empty($d['max_studenti']) ? (int)$d['max_studenti'] : '…'; ?> studenti</span><?php endif; ?>
                    </div>
                </div>
                <div class="d-flex gap-1 flex-shrink-0">
                    <a href="progetti.php?p_id=<?php echo $filtro_p; ?>&id=<?php echo $id; ?>" class="btn btn-sm btn-outline-primary" title="Modifica" aria-label="Modifica progetto"><i class="fa fa-edit" aria-hidden="true"></i></a>
                    <?php if ($slug_area): ?><a href="../<?php echo h($slug_area); ?>.php?progetto=<?php echo $id; ?>" target="_blank" rel="noopener" class="btn btn-sm btn-outline-secondary" title="Scheda pubblica" aria-label="Scheda pubblica"><i class="fa fa-eye" aria-hidden="true"></i></a><?php endif; ?>
                    <?php if ($puo_creare): ?>
                        <form method="POST" class="m-0"><?php csrf_field(); ?><input type="hidden" name="duplica_progetto" value="<?php echo $id; ?>">
                            <button type="submit" class="btn btn-sm btn-outline-secondary" title="Duplica" aria-label="Duplica progetto" data-confirm="Duplicare il progetto? Le iscrizioni non vengono copiate."><i class="fa fa-copy" aria-hidden="true"></i></button></form>
                    <?php endif; ?>
                    <?php if ($can_manage_settings): ?>
                        <form method="POST" class="m-0"><?php csrf_field(); ?><input type="hidden" name="archivia_progetto" value="<?php echo $id; ?>">
                            <button type="submit" class="btn btn-sm btn-outline-warning text-dark" title="Archivia" aria-label="Archivia progetto" data-confirm="Archiviare questo progetto?"><i class="fa fa-archive" aria-hidden="true"></i></button></form>
                        <form method="POST" class="m-0"><?php csrf_field(); ?><input type="hidden" name="elimina_progetto" value="<?php echo $id; ?>">
                            <button type="submit" class="btn btn-sm btn-outline-danger" title="Elimina" aria-label="Elimina progetto" data-confirm="Eliminare il progetto con tutte le iscrizioni? L'operazione non si può annullare."><i class="fa fa-trash" aria-hidden="true"></i></button></form>
                    <?php endif; ?>
                </div>
            </div>

            <div class="mt-3 pt-2 border-top small d-flex flex-column gap-1">
                <?php foreach ($p['ied']['edizioni'] as $ed): $as = $ed['assegnata']; ?>
                    <div class="d-flex flex-wrap align-items-center gap-2">
                        <?php if (count($p['ied']['edizioni']) > 1): ?><span class="fw-bold text-dark" style="min-width:150px;"><?php echo h($ed['etichetta']); ?></span><?php endif; ?>
                        <?php if ($as):
                            $scuola = nome_scuola_prenotazione($as);
                            $lbl_st = ['confermata' => 'Assegnato', 'richiesta_conferma' => 'Posto offerto, in attesa di conferma', 'da_approvare' => 'Da approvare'][$as['stato'] ?? 'confermata'] ?? 'Assegnato';
                        ?>
                            <span class="badge bg-success"><i class="fa fa-school me-1" aria-hidden="true"></i><?php echo h($lbl_st); ?></span>
                            <span class="text-dark fw-semibold"><?php echo h($scuola !== '' ? $scuola : trim($as['nome'] . ' ' . $as['cognome'])); ?></span>
                            <?php if ($scuola !== ''): ?><span class="text-muted">· <?php echo h(trim($as['nome'] . ' ' . $as['cognome'])); ?></span><?php endif; ?>
                            <a href="mailto:<?php echo h($as['email']); ?>" class="text-muted"><?php echo h($as['email']); ?></a>
                        <?php else: ?>
                            <span class="badge bg-light text-secondary border"><i class="fa fa-school me-1" aria-hidden="true"></i>Nessuna scuola iscritta</span>
                        <?php endif; ?>
                        <?php if ($ed['attesa'] > 0): ?><span class="badge" style="background:#fef3c7;color:#92400e;"><?php echo $ed['attesa']; ?> in lista d'attesa</span><?php endif; ?>
                        <?php if ($can_manage_iscritti): ?>
                            <a href="iscritti.php?p_id=<?php echo $filtro_p; ?>&f_turno=<?php echo (int)$ed['t']['id']; ?>" class="btn btn-sm btn-outline-dark py-0 ms-auto fw-bold"><i class="fa fa-users me-1" aria-hidden="true"></i>Iscrizioni</a>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
<?php endforeach; ?>

<?php require_once 'admin_footer.php'; ?>
