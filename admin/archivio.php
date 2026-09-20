<?php
// archivio.php - Gestione Storico Eventi Archiviati e Clonazione
require_once 'admin_header.php';

if (!$can_manage_eventi) {
    echo "<div class='alert alert-danger fw-bold shadow-sm m-4'><i class='fa fa-ban me-2'></i> Accesso negato. Non hai i permessi per gestire l'archivio.</div>";
    require_once 'admin_footer.php';
    exit;
}

function admin_redirect($url) { echo "<script>window.location.replace('$url');</script>"; exit; }

// 1. AZIONE: RIPRISTINA EVENTO
if (isset($_GET['ripristina_ev'])) {
    $ev_id = (int)$_GET['ripristina_ev'];
    // blocca_auto_archivio = 1: evita che l'auto-archiviazione silenziosa in config.php
    // rimetta subito l'evento in archivio, dato che i suoi turni sono ancora nel passato.
    $conn->query("UPDATE eventi SET archiviato = 0, blocca_auto_archivio = 1 WHERE id = $ev_id");
    if (function_exists('registra_log_audit')) registra_log_audit($conn, "Ripristino Evento da Archivio", ["Evento ID" => $ev_id]);
    flash_set("Evento ripristinato con successo! È tornato tra gli eventi attivi. Ricorda di aggiungere nuovi turni con date future, altrimenti resterà visibile ma senza date prenotabili.");
    admin_redirect("archivio.php?p_id=$filtro_p");
}

// 2. AZIONE: ELIMINA DEFINITIVAMENTE
if (isset($_GET['del_ev'])) { 
    $ev_id = (int)$_GET['del_ev'];
    $res_t_del = $conn->query("SELECT id FROM turni WHERE evento_id = $ev_id");
    while($t_del = $res_t_del->fetch_assoc()){ $conn->query("DELETE FROM prenotazioni WHERE turno_id = {$t_del['id']}"); }
    $conn->query("DELETE FROM turni WHERE evento_id = $ev_id");
    $conn->query("DELETE FROM campi_form WHERE evento_id = $ev_id");
    $conn->query("DELETE FROM sondaggi WHERE evento_id = $ev_id"); 
    $conn->query("DELETE FROM eventi WHERE id = $ev_id"); 
    if (function_exists('registra_log_audit')) registra_log_audit($conn, "Eliminazione Evento Archiviato", ["Evento ID" => $ev_id]);
    flash_set("Evento eliminato definitivamente dal database.");
    admin_redirect("archivio.php?p_id=$filtro_p");
}

// 3. AZIONE: DUPLICA EVENTO
if (isset($_GET['duplica_ev'])) {
    $ev_id = (int)$_GET['duplica_ev'];
    
    $res_ev = $conn->query("SELECT * FROM eventi WHERE id = $ev_id");
    if ($res_ev && $ev_old = $res_ev->fetch_assoc()) {
        $titolo_nuovo = $conn->real_escape_string($ev_old['titolo'] . " (Copia)");
        $desc = $conn->real_escape_string($ev_old['descrizione']);
        $loc = $conn->real_escape_string($ev_old['locandina_path']);
        $pdf = $conn->real_escape_string($ev_old['allegato_pdf']);
        $info = $conn->real_escape_string($ev_old['info_aggiuntive']);
        $luogo = $conn->real_escape_string($ev_old['luogo']);
        $cat = (int)$ev_old['sottocategoria_id'];
        $ord = (int)$ev_old['ordine'];
        $gest = $conn->real_escape_string($ev_old['gestori_utenti_ids']);
        $perm = $conn->real_escape_string($ev_old['permessi_gestori_json']);
        
        $conn->query("INSERT INTO eventi (pagina_id, sottocategoria_id, titolo, descrizione, luogo, locandina_path, allegato_pdf, info_aggiuntive, ordine, gestori_utenti_ids, permessi_gestori_json, archiviato) 
                      VALUES ($filtro_p, $cat, '$titolo_nuovo', '$desc', '$luogo', '$loc', '$pdf', '$info', $ord, '$gest', '$perm', 0)");
        
        $new_ev_id = $conn->insert_id;
        
        $res_cf = $conn->query("SELECT * FROM campi_form WHERE evento_id = $ev_id");
        if ($res_cf) {
            while ($cf = $res_cf->fetch_assoc()) {
                $nc = $conn->real_escape_string($cf['nome_campo']);
                $et = $conn->real_escape_string($cf['etichetta']);
                $tp = $conn->real_escape_string($cf['tipo']);
                $op = $conn->real_escape_string($cf['opzioni']);
                $ob = (int)$cf['obbligatorio'];
                $or = (int)$cf['ordine'];
                $conn->query("INSERT INTO campi_form (pagina_id, evento_id, nome_campo, etichetta, tipo, opzioni, obbligatorio, ordine) 
                              VALUES ($filtro_p, $new_ev_id, '$nc', '$et', '$tp', '$op', $ob, $or)");
            }
        }
        
        if (function_exists('registra_log_audit')) registra_log_audit($conn, "Clonazione Evento da Archivio", ["Da ID" => $ev_id, "Nuovo ID" => $new_ev_id]);
        flash_set("Evento duplicato! Trovi la copia pronta nella sezione 'Eventi e Turni' per inserire le nuove date.");
        
        admin_redirect("eventi.php?p_id=$filtro_p");
    }
}

// 4. AZIONE: ESPORTA ISCRITTI
if (isset($_POST['export_csv_archivio'])) {
    $ev_id = (int)$_POST['evento_id'];
    $ev_titolo = preg_replace('/[^a-zA-Z0-9_]/', '_', strtolower($_POST['evento_titolo']));
    
    $custom_cols = [];
    $res_cf = $conn->query("SELECT nome_campo, etichetta FROM campi_form WHERE evento_id = $ev_id ORDER BY id ASC");
    if ($res_cf) while ($cf = $res_cf->fetch_assoc()) $custom_cols[$cf['nome_campo']] = $cf['etichetta'];

    $sql_export = "SELECT pr.codice_prenotazione, pr.presente, pr.stato, pr.num_posti, pr.nome, pr.cognome, pr.email, t.data_turno, pr.dati_custom_json 
                   FROM prenotazioni pr JOIN turni t ON pr.turno_id = t.id 
                   WHERE t.evento_id = $ev_id ORDER BY t.data_turno ASC, pr.cognome ASC";
    $res_export = $conn->query($sql_export);

    ob_end_clean(); 
    header('Content-Type: text/csv; charset=utf-8');
    header("Content-Disposition: attachment; filename=rendicontazione_{$ev_titolo}.csv");
    $output = fopen('php://output', 'w');
    
    $headers = ['Stato', 'Presente', 'Nome', 'Cognome', 'Email', 'Data Turno', 'Posti'];
    foreach ($custom_cols as $key => $label) $headers[] = $label;
    fputcsv($output, $headers);
    
    if ($res_export) {
        while($row = $res_export->fetch_assoc()) { 
            $json = json_decode($row['dati_custom_json'] ?? '', true) ?: [];
            $line = [
                $row['stato'], 
                ($row['presente'] == 1 ? 'SI' : 'NO'), 
                $row['nome'], 
                $row['cognome'], 
                $row['email'], 
                $row['data_turno'], 
                $row['num_posti']
            ];
            foreach ($custom_cols as $key => $label) {
                $line[] = $json[$key] ?? '';
            }
            fputcsv($output, $line); 
        }
    }
    fclose($output); exit;
}

// RECUPERO EVENTI ARCHIVIATI
$eventi_archiviati = [];
$sql_arch = "SELECT e.*, sc.nome as nome_sottocategoria, 
            (SELECT COUNT(*) FROM turni WHERE evento_id = e.id) as tot_turni,
            (SELECT id FROM turni WHERE evento_id = e.id ORDER BY data_turno ASC, orario_inizio ASC LIMIT 1) as primo_turno_id,
            (SELECT COUNT(pr.id) FROM prenotazioni pr JOIN turni t ON pr.turno_id = t.id WHERE t.evento_id = e.id) as tot_iscritti
            FROM eventi e 
            LEFT JOIN sottocategorie sc ON e.sottocategoria_id = sc.id 
            WHERE e.pagina_id = $filtro_p AND e.archiviato = 1 $sql_filtro_eventi_rbac 
            ORDER BY e.id DESC";
            
$res_arch = $conn->query($sql_arch);
if ($res_arch) while($row = $res_arch->fetch_assoc()) $eventi_archiviati[] = $row;
?>

<h4 class="fw-bold text-dark mb-4"><i class="fa fa-archive text-secondary me-2"></i> Archivio Storico Eventi</h4>

<div class="card shadow-sm border-0 bg-white">
    <div class="card-header bg-secondary text-white py-3 d-flex justify-content-between align-items-center">
        <h6 class="fw-bold m-0"><i class="fa fa-history me-2"></i> Eventi Conclusi in: <?php echo htmlspecialchars($page_cfg['titolo'] ?? ''); ?></h6>
        <span class="badge bg-light text-dark shadow-sm"><i class="fa fa-info-circle me-1"></i> Usa la barra "Cerca" a destra per filtrare i testi</span>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover align-middle m-0" id="tabellaArchivio">
                <thead class="table-light">
                    <tr>
                        <th>Titolo Evento</th>
                        <th>Sezione / Luogo</th>
                        <th class="text-center">Turni</th>
                        <th class="text-center">Totale Iscritti Storici</th>
                        <th class="text-end">Azioni</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach($eventi_archiviati as $ev): ?>
                        <tr>
                            <td class="fw-bold text-dark">
                                <span class="text-secondary">🎯 <?php echo htmlspecialchars($ev['titolo']); ?></span>
                            </td>
                            <td>
                                <small class="text-muted d-block">📁 <?php echo $ev['nome_sottocategoria'] ?: 'Nessuna Sezione'; ?></small>
                                <small class="text-muted d-block">📍 <?php echo htmlspecialchars($ev['luogo'] ?: 'N/D'); ?></small>
                            </td>
                            <td class="text-center"><span class="badge bg-light text-dark border"><?php echo $ev['tot_turni']; ?> Turni</span></td>
                            <td class="text-center">
                                <?php if ($ev['primo_turno_id']): ?>
                                    <a href="iscritti.php?p_id=<?php echo $filtro_p; ?>&f_turno=<?php echo $ev['primo_turno_id']; ?>&archivio=1" class="badge bg-primary text-decoration-none shadow-sm" title="Vedi lista iscritti archiviata">
                                        <i class="fa fa-users me-1"></i> <?php echo $ev['tot_iscritti']; ?> Iscritti
                                    </a>
                                <?php else: ?>
                                    <span class="badge bg-secondary"><?php echo $ev['tot_iscritti']; ?> Iscritti</span>
                                <?php endif; ?>
                            </td>
                            <td class="text-end">
                                <div class="d-flex justify-content-end gap-2">
                                    <form method="POST" class="m-0">
                                        <input type="hidden" name="evento_id" value="<?php echo $ev['id']; ?>">
                                        <input type="hidden" name="evento_titolo" value="<?php echo htmlspecialchars($ev['titolo']); ?>">
                                        <button type="submit" name="export_csv_archivio" class="btn btn-success btn-sm fw-bold shadow-sm" title="Esporta CSV Rendicontazione"><i class="fa fa-file-csv"></i> Report</button>
                                    </form>
                                    
                                    <a href="sondaggi.php?p_id=<?php echo $filtro_p; ?>&f_sond_ev=<?php echo $ev['id']; ?>&archivio=1" class="btn btn-info btn-sm fw-bold text-white shadow-sm" title="Vedi Risultati Sondaggio Archiviato"><i class="fa fa-poll"></i> Sondaggi</a>
                                    
                                    <a href="?duplica_ev=<?php echo $ev['id']; ?>&p_id=<?php echo $filtro_p; ?>" class="btn btn-warning btn-sm fw-bold text-dark shadow-sm" data-confirm="Vuoi creare una copia esatta di questo evento (svuotata da iscritti e vecchie date) per riproporla quest\'anno?"><i class="fa fa-copy"></i> Duplica</a>
                                    
                                    <a href="?ripristina_ev=<?php echo $ev['id']; ?>&p_id=<?php echo $filtro_p; ?>" class="btn btn-outline-primary btn-sm fw-bold" data-confirm="Ripristinare questo evento rendendolo di nuovo visibile al pubblico assieme a tutti i suoi vecchi iscritti?"><i class="fa fa-undo"></i> Ripristina</a>
                                    
                                    <?php if ($can_manage_settings): ?>
                                        <a href="?del_ev=<?php echo $ev['id']; ?>&p_id=<?php echo $filtro_p; ?>" class="btn btn-outline-danger btn-sm" data-confirm="Sei assolutamente sicuro di voler eliminare questo evento e tutto il suo storico iscritti?"><i class="fa fa-trash"></i></a>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    if(typeof jQuery !== 'undefined' && $.fn.DataTable) {
        $('#tabellaArchivio').DataTable({
            pageLength: 25,
            language: { url: '//cdn.datatables.net/plug-ins/1.13.6/i18n/it-IT.json' },
            order: [[0, "asc"]],
            columnDefs: [ { orderable: false, targets: 4 } ]
        });
    }
});
</script>

<?php require_once 'admin_footer.php'; ?>
