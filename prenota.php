<?php
// RECUPERO STATO UTENTE E RUOLO
$utente_logged = !empty($_SESSION['utente_id']);
$utente_ruolo_id = (int)($_SESSION['utente_ruolo_id'] ?? 5);

// RUOLO RICHIESTO DALL'EVENTO (0 = Tutti, 1 = Admin, 2 = Gestore, 3 = Studenti, 4 = Dipendenti, 5 = Esterni)
$ruolo_richiesto = (int)($evento['ruolo_accesso_id'] ?? 0);

// NOMI GRUPPI PER AVVISI
$nomi_ruoli = [
    1 => 'Amministratore',
    2 => 'Gestore Prenotazioni',
    3 => 'Studenti',
    4 => 'Dipendenti',
    5 => 'Esterni'
];
?>

<!-- BLOCCO DINETTO SOTTO L'EVENTO NELLA PAGINA PUBBLICA -->
<div class="mt-3">
    <?php if ($ruolo_richiesto > 0 && !$utente_logged): ?>
        
        <!-- CASO 1: EVENTO RISERVATO + UTENTE NON LOGGATO -->
        <a href="saml_login.php" class="btn btn-danger fw-bold shadow-sm" style="background-color: #990000; border: none;">
            <i class="fa fa-key me-1"></i> Accedi con SSO Unical per Prenotare
        </a>
        <small class="d-block text-muted mt-1">
            <i class="fa fa-info-circle me-1"></i> Evento riservato al gruppo: <strong><?php echo htmlspecialchars($nomi_ruoli[$ruolo_richiesto] ?? 'Riservato'); ?></strong>.
        </small>

    <?php elseif ($ruolo_richiesto > 0 && $utente_logged && $utente_ruolo_id !== $ruolo_richiesto && $utente_ruolo_id !== 1): ?>

        <!-- CASO 2: UTENTE LOGGATO MA IN UN GRUPPO DIVERSO DA QUELLO RICHIESTO -->
        <button class="btn btn-secondary fw-bold" disabled>
            <i class="fa fa-lock me-1"></i> Prenotazione Non Consentita
        </button>
        <small class="d-block text-danger fw-bold mt-1">
            <i class="fa fa-exclamation-triangle me-1"></i> Questo evento è riservato esclusivamente al gruppo <u><?php echo htmlspecialchars($nomi_ruoli[$ruolo_richiesto] ?? ''); ?></u>. Il tuo profilo attuale è: <strong><?php echo htmlspecialchars($nomi_ruoli[$utente_ruolo_id] ?? 'Esterno'); ?></strong>.
        </small>

    <?php else: ?>

        <!-- CASO 3: UTENTE ABILITATO O EVENTO APERTO A TUTTI -->
        <button type="button" class="btn btn-success fw-bold shadow-sm" data-bs-toggle="modal" data-bs-target="#modalPrenota<?php echo $evento['id']; ?>">
            <i class="fa fa-calendar-check me-1"></i> Prenota Ora
        </button>

        <!-- MODALE FORM DI PRENOTAZIONE CON AUTOCOMPILAZIONE -->
        <div class="modal fade" id="modalPrenota<?php echo $evento['id']; ?>" tabindex="-1">
            <div class="modal-dialog">
                <div class="modal-content">
                    <form method="POST" action="salva_prenotazione.php">
                        <input type="hidden" name="evento_id" value="<?php echo $evento['id']; ?>">
                        
                        <div class="modal-header bg-light py-2">
                            <h5 class="modal-title fw-bold text-danger" style="color: #990000;">Prenotazione: <?php echo htmlspecialchars($evento['titolo']); ?></h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                        </div>
                        
                        <div class="modal-body text-start">
                            <div class="mb-3">
                                <label class="form-label small fw-bold">Seleziona Turno / Orario</label>
                                <select name="turno_id" class="form-select" required>
                                    <?php foreach ($evento['turni'] as $t): ?>
                                        <?php 
                                            $posti_occ = getPostiOccupati($conn, $t['id']);
                                            $disponibili = $t['max_posti'] - $posti_occ;
                                        ?>
                                        <option value="<?php echo $t['id']; ?>" <?php echo ($disponibili <= 0) ? 'disabled' : ''; ?>>
                                            📅 <?php echo date('d/m/Y', strtotime($t['data_turno'])); ?> - 🕒 <?php echo substr($t['orario_inizio'], 0, 5); ?> (Disponibili: <?php echo $disponibili; ?>/<?php echo $t['max_posti']; ?>)
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <!-- CAMPI ANAGRAFICI (AUTOCOMPILATI SE LOGGATO) -->
                            <div class="row g-2 mb-3">
                                <div class="col-md-6">
                                    <label class="form-label small fw-bold">Nome</label>
                                    <input type="text" name="nome" class="form-control" value="<?php echo htmlspecialchars($_SESSION['utente_nome'] ?? ''); ?>" <?php echo $utente_logged ? 'readonly' : 'required'; ?>>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label small fw-bold">Email</label>
                                    <input type="email" name="email" class="form-control" value="<?php echo htmlspecialchars($_SESSION['utente_email'] ?? ''); ?>" <?php echo $utente_logged ? 'readonly' : 'required'; ?>>
                                </div>
                            </div>

                            <!-- DIMOSTRAZIONE CAMPI CUSTOM (FORM BUILDER) -->
                            <?php 
                                $res_cf = $conn->query("SELECT * FROM campi_form WHERE evento_id = {$evento['id']} ORDER BY id ASC");
                                if ($res_cf && $res_cf->num_rows > 0):
                                    while ($cf = $res_cf->fetch_assoc()):
                            ?>
                                <div class="mb-2">
                                    <label class="form-label small fw-bold"><?php echo htmlspecialchars($cf['etichetta']); ?></label>
                                    <?php if ($cf['tipo_campo'] == 'select'): ?>
                                        <select name="custom_<?php echo $cf['nome_campo']; ?>" class="form-select" <?php echo $cf['obbligatorio'] ? 'required' : ''; ?>>
                                            <?php foreach (explode(',', $cf['opzioni_select']) as $opt): ?>
                                                <option value="<?php echo trim($opt); ?>"><?php echo trim($opt); ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    <?php else: ?>
                                        <input type="<?php echo $cf['tipo_campo']; ?>" name="custom_<?php echo $cf['nome_campo']; ?>" class="form-control" <?php echo $cf['obbligatorio'] ? 'required' : ''; ?>>
                                    <?php endif; ?>
                                </div>
                            <?php 
                                    endwhile;
                                endif; 
                            ?>
                        </div>

                        <div class="modal-footer py-2">
                            <button type="submit" class="btn btn-danger fw-bold w-100" style="background-color: #990000; border: none;"> Conferma Prenotazione</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

    <?php endif; ?>
</div>
