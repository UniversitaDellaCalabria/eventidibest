<?php
// utenti.php - Gestione Anagrafiche, Sicurezza e Assegnazione Ruoli
require_once 'admin_header.php';

// Controllo Permessi RBAC (Solo Full Admin)
if (!$is_full_admin) {
    echo "<div class='alert alert-danger fw-bold shadow-sm m-4'><i class='fa fa-ban me-2'></i> Accesso negato. Questa sezione è riservata agli amministratori globali del sistema.</div>";
    require_once 'admin_footer.php';
    exit;
}

function admin_redirect($url) {
    echo "<script>window.location.replace('$url');</script>";
    exit;
}

// ==============================================================================
// BLOCCO ELABORAZIONE AZIONI BACKEND (GET / POST)
// ==============================================================================

// 1. CREAZIONE NUOVO GRUPPO / RUOLO
if (isset($_POST['add_nuovo_ruolo'])) {
    csrf_verify($_POST['csrf_token'] ?? '');
    $nome_ruolo = $conn->real_escape_string(trim($_POST['nome_ruolo'] ?? ''));
    if (!empty($nome_ruolo)) {
        $conn->query("INSERT INTO ruoli (nome) VALUES ('$nome_ruolo')");
        
        // --> AUDIT LOG
        registra_log_audit($conn, "Creazione Nuovo Gruppo Utenti", ["Nome Gruppo" => $nome_ruolo]);
        
        flash_set("Nuovo gruppo/ruolo creato con successo!");
    }
    admin_redirect("utenti.php?p_id=$filtro_p");
}

// 2. MODIFICA ANAGRAFICA E PERMESSI UTENTE
if (isset($_POST['change_user_role'])) {
    csrf_verify($_POST['csrf_token'] ?? '');
    $u_id_mod = (int)$_POST['utente_id'];
    $r_id_mod = (int)$_POST['ruolo_id'];
    $sec_roles = isset($_POST['ruoli_secondari']) && is_array($_POST['ruoli_secondari']) ? implode(',', array_map('intval', $_POST['ruoli_secondari'])) : '';
    
    $nome_mod = $conn->real_escape_string(trim($_POST['nome'] ?? ''));
    $cognome_mod = $conn->real_escape_string(trim($_POST['cognome'] ?? ''));
    
    $upd = !empty($nome_mod) ? ", nome='$nome_mod', cognome='$cognome_mod'" : "";
    
    $conn->query("UPDATE utenti SET ruolo_id = $r_id_mod, ruoli_secondari = '$sec_roles' $upd WHERE id = $u_id_mod");
    
    // --> AUDIT LOG
    registra_log_audit($conn, "Modifica Permessi/Anagrafica Utente", ["Utente Modificato ID" => $u_id_mod, "Nuovo Ruolo ID" => $r_id_mod, "Ruoli Secondari" => $sec_roles]);
    
    flash_set("Permessi e anagrafica utente aggiornati!");
    admin_redirect("utenti.php?p_id=$filtro_p");
}

// 3. ELIMINAZIONE UTENTE
if (isset($_POST['del_user'])) {
    csrf_verify($_POST['csrf_token'] ?? '');
    $u_del_id = (int)$_POST['del_user'];
    
    // Sicurezza: Impeidsci all'utente di auto-eliminarsi
    if ($u_del_id !== (int)$_SESSION['utente_id']) {
        $conn->query("DELETE FROM prenotazioni WHERE utente_id = $u_del_id");
        $conn->query("DELETE FROM utenti WHERE id = $u_del_id");
        
        // --> AUDIT LOG
        registra_log_audit($conn, "Eliminazione Utente di Sistema", ["Utente Eliminato ID" => $u_del_id]);
        
        flash_set("Utente eliminato dal sistema!");
    } else {
        flash_set("❌ Non puoi eliminare il tuo stesso account!", 'danger');
    }
    admin_redirect("utenti.php?p_id=$filtro_p");
}

// ==============================================================================
// PREPARAZIONE DATI FRONT-END
// ==============================================================================

// Recupero Ruoli
$ruoli = []; $ruoli_map = [];
$res_ru = $conn->query("SELECT * FROM ruoli ORDER BY id ASC"); 
if ($res_ru) { 
    while($r = $res_ru->fetch_assoc()) { 
        $ruoli[] = $r; 
        $ruoli_map[(int)$r['id']] = $r['nome']; 
    } 
}

// Recupero Utenti Completo
$utenti = [];
$res_ut = $conn->query("SELECT * FROM utenti ORDER BY id DESC");
if ($res_ut) {
    while ($r = $res_ut->fetch_assoc()) {
        $r_id = (int)($r['ruolo_id'] ?? 5);
        $r['ruolo_nome'] = $ruoli_map[$r_id] ?? 'Esterni / Ospiti';
        $utenti[] = $r;
    }
}
?>

<!-- FRONT-END DELLA PAGINA -->
<h4 class="fw-bold text-dark mb-4"><i class="fa fa-user-shield text-danger me-2"></i> Sicurezza: Utenti e Gruppi</h4>

<div class="card shadow-sm border-0 p-4 mb-4" style="border-left: 4px solid #990000 !important;">
    <h6 class="fw-bold text-danger mb-3"><i class="fa fa-users-cog me-1"></i> Aggiungi Nuovo Gruppo / Ruolo Utente</h6>
    <form method="POST" class="row g-2 align-items-end">
        <?php csrf_field(); ?>
        <div class="col-md-8">
            <label class="form-label small fw-bold">Nome del Nuovo Gruppo / Ruolo</label>
            <input type="text" name="nome_ruolo" class="form-control form-control-sm" placeholder="Es. Tutors, Docenti, Personale Esterno" required>
        </div>
        <div class="col-md-4">
            <button type="submit" name="add_nuovo_ruolo" class="btn btn-danger btn-sm w-100 fw-bold" style="background-color: #990000; border: none;">
                <i class="fa fa-plus me-1"></i> Crea Gruppo
            </button>
        </div>
    </form>
</div>

<div class="card shadow-sm border-0 p-4">
    <h5 class="fw-bold text-primary border-bottom pb-2 mb-3"><i class="fa fa-users me-1"></i> Anagrafica Utenti, Assegnazione Ruolo Principale e Ruoli Secondari</h5>
    
    <div class="table-responsive">
        <table id="tabellaUtenti" class="table table-hover align-middle w-100">
            <thead class="table-dark">
                <tr>
                    <th>Codice Fiscale</th>
                    <th>Matricola Studente</th>
                    <th>Matricola Dipendente</th>
                    <th>Nominativo</th>
                    <th>Email</th>
                    <th>Ruolo Principale & Secondari</th>
                    <th>Ultimo Accesso</th>
                    <th class="text-end">Azione</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($utenti)): ?>
                    <tr><td colspan="8" class="text-center p-4 text-muted">Nessun utente registrato nel sistema.</td></tr>
                <?php else: ?>
                    <?php foreach ($utenti as $u): ?>
                        <?php $u_sec_arr = array_filter(explode(',', $u['ruoli_secondari'] ?? '')); ?>
                        <tr>
                            <td><code><?php echo htmlspecialchars($u['codice_fiscale'] ?? ''); ?></code></td>
                            <td><?php echo !empty($u['matricola_studente']) ? "<span class='badge bg-info text-dark font-monospace'>{$u['matricola_studente']}</span>" : "<span class='badge bg-light text-muted border'>N/D</span>"; ?></td>
                            <td><?php echo !empty($u['matricola_dipendente']) ? "<span class='badge bg-secondary font-monospace'>{$u['matricola_dipendente']}</span>" : "<span class='badge bg-light text-muted border'>N/D</span>"; ?></td>
                            
                            <!-- MODIFICA NOMINATIVO -->
                            <td>
                                <strong><?php echo htmlspecialchars(($u['nome'] ?? '') . ' ' . ($u['cognome'] ?? '')); ?></strong>
                                <button type="button" class="btn btn-link btn-sm p-0 ms-1" data-bs-toggle="modal" data-bs-target="#modNameUser<?php echo $u['id']; ?>" title="Modifica Nome">
                                    <i class="fa fa-edit text-muted"></i>
                                </button>

                                <div class="modal fade" id="modNameUser<?php echo $u['id']; ?>" tabindex="-1">
                                    <div class="modal-dialog modal-dialog-centered modal-sm">
                                        <div class="modal-content">
                                            <form method="POST">
                                                <?php csrf_field(); ?>
                                                <input type="hidden" name="utente_id" value="<?php echo $u['id']; ?>">
                                                <input type="hidden" name="ruolo_id" value="<?php echo $u['ruolo_id']; ?>">
                                                <input type="hidden" name="change_user_role" value="1">
                                                <input type="hidden" name="ruoli_secondari[]" value="<?php echo implode(',', $u_sec_arr); ?>">
                                                
                                                <div class="modal-header py-2 bg-light">
                                                    <h6 class="modal-title fw-bold">Modifica Nominativo</h6>
                                                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                                </div>
                                                <div class="modal-body text-start">
                                                    <div class="mb-2">
                                                        <label class="form-label small fw-bold">Nome</label>
                                                        <input type="text" name="nome" class="form-control form-control-sm" value="<?php echo htmlspecialchars($u['nome'] ?? ''); ?>" required>
                                                    </div>
                                                    <div class="mb-2">
                                                        <label class="form-label small fw-bold">Cognome</label>
                                                        <input type="text" name="cognome" class="form-control form-control-sm" value="<?php echo htmlspecialchars($u['cognome'] ?? ''); ?>">
                                                    </div>
                                                </div>
                                                <div class="modal-footer py-2">
                                                    <button type="submit" class="btn btn-primary btn-sm fw-bold w-100">Salva Nome</button>
                                                </div>
                                            </form>
                                        </div>
                                    </div>
                                </div>
                            </td>

                            <td><small><?php echo htmlspecialchars($u['email'] ?? ''); ?></small></td>
                            
                            <!-- GESTIONE RUOLI -->
                            <td style="min-width: 260px;">
                                <form method="POST" class="m-0">
                                    <?php csrf_field(); ?>
                                    <input type="hidden" name="utente_id" value="<?php echo $u['id']; ?>">
                                    <input type="hidden" name="change_user_role" value="1">
                                    
                                    <div class="mb-1">
                                        <small class="text-muted d-block fw-bold" style="font-size:0.7rem;">RUOLO PRINCIPALE:</small>
                                        <select name="ruolo_id" class="form-select form-select-sm border-primary fw-bold text-primary">
                                            <?php foreach ($ruoli as $r): ?>
                                                <option value="<?php echo $r['id']; ?>" <?php echo ($u['ruolo_id'] == $r['id']) ? 'selected' : ''; ?>>
                                                    <?php echo htmlspecialchars($r['nome']); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>

                                    <div class="dropdown">
                                        <button class="btn btn-outline-secondary btn-sm dropdown-toggle w-100 py-0" type="button" data-bs-toggle="dropdown" style="font-size:0.75rem;">
                                            + Altri Gruppi / Permessi
                                        </button>
                                        <div class="dropdown-menu p-2 shadow" style="min-width: 200px;">
                                            <small class="fw-bold text-muted d-block mb-1 border-bottom pb-1">Seleziona Ruoli Aggiuntivi:</small>
                                            <?php foreach ($ruoli as $r): ?>
                                                <div class="form-check">
                                                    <input class="form-check-input" type="checkbox" name="ruoli_secondari[]" value="<?php echo $r['id']; ?>" id="secRole_<?php echo $u['id']; ?>_<?php echo $r['id']; ?>" <?php echo in_array((string)$r['id'], $u_sec_arr) ? 'checked' : ''; ?>>
                                                    <label class="form-check-label small" for="secRole_<?php echo $u['id']; ?>_<?php echo $r['id']; ?>">
                                                        <?php echo htmlspecialchars($r['nome']); ?>
                                                    </label>
                                                </div>
                                            <?php endforeach; ?>
                                            <button type="submit" class="btn btn-primary btn-sm w-100 mt-2 fw-bold" style="font-size:0.75rem;">Aggiorna Permessi</button>
                                        </div>
                                    </div>

                                    <!-- VISUALIZZAZIONE BADGE RUOLI -->
                                    <div class="mt-1 d-flex flex-wrap gap-1">
                                        <span class="badge bg-primary"><?php echo htmlspecialchars($u['ruolo_nome']); ?></span>
                                        <?php foreach ($u_sec_arr as $r_sec_id): ?>
                                            <?php foreach ($ruoli as $r_info) { if ($r_info['id'] == $r_sec_id) echo "<span class='badge bg-secondary'>+ {$r_info['nome']}</span>"; } ?>
                                        <?php endforeach; ?>
                                    </div>
                                </form>
                            </td>

                            <td><small class="text-secondary"><?php echo !empty($u['ultimo_accesso']) ? date('d/m/Y H:i', strtotime($u['ultimo_accesso'])) : 'Mai'; ?></small></td>
                            <td class="text-end">
                                <?php if ($u['id'] !== (int)$_SESSION['utente_id']): ?>
                                    <form method="POST" class="d-inline">
                                        <?php csrf_field(); ?>
                                        <input type="hidden" name="del_user" value="<?php echo $u['id']; ?>">
                                        <input type="hidden" name="p_id" value="<?php echo $filtro_p; ?>">
                                        <button type="submit" class="btn btn-outline-danger btn-sm shadow-sm" data-confirm="Sicuro di voler eliminare definitivamente questo utente dal database? Verranno eliminate anche tutte le sue prenotazioni.">
                                            <i class="fa fa-trash"></i> Elimina
                                        </button>
                                    </form>
                                <?php else: ?>
                                    <span class="badge bg-light text-muted border">Tu</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    if(typeof jQuery !== 'undefined' && $.fn.DataTable) {
        $('#tabellaUtenti').DataTable({
            pageLength: 50,
            language: { url: '//cdn.datatables.net/plug-ins/1.13.6/i18n/it-IT.json' }
        });
    }
});
</script>

<?php require_once 'admin_footer.php'; ?>
