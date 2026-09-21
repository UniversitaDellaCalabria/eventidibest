<?php
ini_set('display_errors', 0);
ini_set('log_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/middleware.php';

$page_cfg['titolo'] = 'Il mio profilo';

$ruoli_label = [1 => 'Amministratore', 2 => 'Gestore', 3 => 'Studente', 4 => 'Dipendente', 5 => 'Esterno'];

$flash_ok  = '';
$flash_err = '';

// Azione: aggiornamento email
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['aggiorna_email'])) {
    csrf_verify($_POST['csrf_token'] ?? '');
    $nuova_email = strtolower(trim($_POST['nuova_email'] ?? ''));

    if (empty($nuova_email) || !filter_var($nuova_email, FILTER_VALIDATE_EMAIL)) {
        $flash_err = 'Inserisci un indirizzo email valido.';
    } else {
        // Verifica che non sia già usata da un altro utente
        $stmt_chk = $conn->prepare("SELECT id FROM utenti WHERE LOWER(email) = ? AND id != ? LIMIT 1");
        $stmt_chk->bind_param("si", $nuova_email, $u_id);
        $stmt_chk->execute();
        if ($stmt_chk->get_result()->num_rows > 0) {
            $flash_err = 'Questa email è già associata a un altro account.';
        } else {
            $stmt_upd = $conn->prepare("UPDATE utenti SET email = ?, email_personalizzata = 1 WHERE id = ?");
            $stmt_upd->bind_param("si", $nuova_email, $u_id);
            if ($stmt_upd->execute()) {
                $_SESSION['utente_email'] = $nuova_email;
                $user_info['email']       = $nuova_email;
                $flash_ok = 'Email aggiornata correttamente.';
            } else {
                $flash_err = 'Errore durante il salvataggio. Riprova.';
            }
        }
    }
}

require_once 'header.php';
?>

<div class="container py-4" style="max-width: 720px;">

    <nav aria-label="breadcrumb" class="mb-3">
        <ol class="breadcrumb">
            <li class="breadcrumb-item"><a href="index.php">Home</a></li>
            <li class="breadcrumb-item active" aria-current="page">Il mio profilo</li>
        </ol>
    </nav>

    <h2 class="fw-bold mb-4" style="color: #B30000;">
        <i class="fa fa-user-circle me-2" aria-hidden="true"></i> Il mio profilo
    </h2>

    <?php if ($flash_ok): ?>
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            <i class="fa fa-check-circle me-1"></i> <?php echo htmlspecialchars($flash_ok); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Chiudi"></button>
        </div>
    <?php endif; ?>
    <?php if ($flash_err): ?>
        <div class="alert alert-danger alert-dismissible fade show" role="alert">
            <i class="fa fa-exclamation-circle me-1"></i> <?php echo htmlspecialchars($flash_err); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Chiudi"></button>
        </div>
    <?php endif; ?>

    <!-- Dati anagrafici -->
    <div class="card shadow-sm border-0 mb-4">
        <div class="card-header fw-bold"><i class="fa fa-id-card me-2 text-secondary"></i>Dati personali</div>
        <div class="card-body">
            <dl class="row mb-0">
                <dt class="col-sm-4 text-muted">Nome e cognome</dt>
                <dd class="col-sm-8"><?php echo htmlspecialchars(trim(($user_info['nome'] ?? '') . ' ' . ($user_info['cognome'] ?? ''))); ?></dd>

                <dt class="col-sm-4 text-muted">Codice fiscale</dt>
                <dd class="col-sm-8 font-monospace"><?php echo htmlspecialchars($user_info['codice_fiscale'] ?? '—'); ?></dd>

                <dt class="col-sm-4 text-muted">Ruolo</dt>
                <dd class="col-sm-8">
                    <?php
                    $label_ruolo = $ruoli_label[$u_ruolo] ?? 'Sconosciuto';
                    $badge_color = ['Amministratore' => 'danger', 'Gestore' => 'warning text-dark', 'Studente' => 'primary', 'Dipendente' => 'success', 'Esterno' => 'secondary'];
                    $bc = $badge_color[$label_ruolo] ?? 'secondary';
                    echo '<span class="badge bg-' . $bc . '">' . htmlspecialchars($label_ruolo) . '</span>';
                    if (!empty($user_info['ruoli_secondari'])) {
                        foreach (explode(',', $user_info['ruoli_secondari']) as $rs) {
                            $rs = trim($rs);
                            if ($rs !== '' && isset($ruoli_label[(int)$rs])) {
                                $lrs = $ruoli_label[(int)$rs];
                                $brs = $badge_color[$lrs] ?? 'secondary';
                                echo ' <span class="badge bg-' . $brs . ' opacity-75">' . htmlspecialchars($lrs) . '</span>';
                            }
                        }
                    }
                    ?>
                </dd>

                <?php if (!empty($user_info['matricola_studente'])): ?>
                <dt class="col-sm-4 text-muted">Matricola studente</dt>
                <dd class="col-sm-8 font-monospace"><?php echo htmlspecialchars($user_info['matricola_studente']); ?></dd>
                <?php endif; ?>

                <?php if (!empty($user_info['matricola_dipendente'])): ?>
                <dt class="col-sm-4 text-muted">Matricola dipendente</dt>
                <dd class="col-sm-8 font-monospace"><?php echo htmlspecialchars($user_info['matricola_dipendente']); ?></dd>
                <?php endif; ?>
            </dl>
        </div>
    </div>

    <!-- Email -->
    <div class="card shadow-sm border-0 mb-4">
        <div class="card-header fw-bold"><i class="fa fa-envelope me-2 text-secondary"></i>Indirizzo email</div>
        <div class="card-body">
            <p class="mb-3">
                Email attuale:
                <?php if (!empty($user_info['email'])): ?>
                    <strong><?php echo htmlspecialchars($user_info['email']); ?></strong>
                <?php else: ?>
                    <span class="text-muted fst-italic">non impostata</span>
                <?php endif; ?>
            </p>
            <p class="text-muted small mb-3">
                <i class="fa fa-info-circle me-1"></i>
                Questa email viene usata per le notifiche di conferma, promemoria e comunicazioni relative alle tue prenotazioni.
                L'email fornita dall'Università al momento del login viene salvata automaticamente; puoi sovrascriverla qui se preferisci usarne un'altra.
            </p>

            <form method="POST" novalidate>
                <?php csrf_field(); ?>
                <input type="hidden" name="aggiorna_email" value="1">
                <div class="input-group">
                    <input type="email" name="nuova_email" class="form-control"
                           placeholder="nuova@email.it"
                           value="<?php echo htmlspecialchars($user_info['email'] ?? ''); ?>"
                           required aria-label="Nuovo indirizzo email">
                    <button type="submit" class="btn btn-primary">
                        <i class="fa fa-save me-1"></i> Salva
                    </button>
                </div>
            </form>
        </div>
    </div>

    <div class="d-flex gap-2">
        <a href="area_personale.php" class="btn btn-outline-secondary">
            <i class="fa fa-calendar-check me-1"></i> Le mie prenotazioni
        </a>
        <a href="esci.php" class="btn btn-outline-danger">
            <i class="fa fa-sign-out-alt me-1"></i> Esci
        </a>
    </div>

</div>

<?php require_once 'footer.php'; ?>
