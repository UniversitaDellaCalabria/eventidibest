<?php
// sondaggio.php - Pagina pubblica per la compilazione anonima del questionario
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('display_startup_errors', 0);
error_reporting(E_ALL);

if (session_status() === PHP_SESSION_NONE) { session_start(); }
require_once 'config.php';
require_once 'functions.php';

$token = trim($_GET['token'] ?? '');

if (empty($token)) {
    $errore_msg = "Token di accesso mancante. Impossibile caricare il sondaggio.";
} else {
    $prenotazione = get_prenotazione_by_token_sondaggio($conn, $token);
    if (!$prenotazione) {
        $errore_msg = "Link non valido o scaduto.";
    } elseif ((int)$prenotazione['sondaggio_completato'] === 1) {
        $errore_msg = "Hai già compilato questo questionario. Grazie per il tuo feedback!";
    } else {
        $sondaggio = get_sondaggio_attivo($conn, (int)$prenotazione['evento_id']);
        if (!$sondaggio) {
            $errore_msg = "Al momento non ci sono sondaggi attivi per questo evento.";
        } else {
            $sond_id = (int)$sondaggio['id'];
            $domande = get_domande_sondaggio($conn, $sond_id);
        }
    }
}

$successo = false;
$errore_invio = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_sondaggio']) && !isset($errore_msg)) {
    $s_id_post = (int)$_POST['sondaggio_id'];
    if ($s_id_post === $sond_id) {
        $risposte = isset($_POST['risposta']) && is_array($_POST['risposta']) ? $_POST['risposta'] : [];
        $successo = salva_risposte_sondaggio($conn, $sond_id, $risposte, (int)$prenotazione['id'], $errore_invio);
        // Doppio invio: il primo è andato a buon fine, mostra la schermata "già compilato"
        if (!$successo && $errore_invio !== null && str_starts_with($errore_invio, 'Hai già compilato')) {
            $errore_msg = $errore_invio;
            $errore_invio = null;
        }
    }
}

$page_cfg['titolo'] = "Questionario di Gradimento";
require_once 'header.php';
?>

<style>
.nps-btn { min-width: 42px; font-size: .95rem; }
.nps-btn input[type="radio"] { display:none; }
.nps-btn label { display:block; padding:8px 4px; border-radius:6px; cursor:pointer; border:2px solid #dee2e6; font-weight:700; text-align:center; transition:.15s; }
.nps-btn input:checked + label { border-color:currentColor; background:currentColor; color:#fff !important; }
.nps-det label { border-color:#dc3545; color:#dc3545; }
.nps-pas label { border-color:#ffc107; color:#856404; }
.nps-pro label { border-color:#198754; color:#198754; }
.nps-det input:checked + label { background:#dc3545 !important; color:#fff !important; }
.nps-pas input:checked + label { background:#ffc107 !important; color:#212529 !important; }
.nps-pro input:checked + label { background:#198754 !important; color:#fff !important; }
</style>

<div class="container my-5" style="max-width: 800px;">

    <?php if ($successo): ?>
        <div class="card shadow-lg border-0 text-center p-5" style="border-radius:12px; border-top:5px solid #198754 !important;">
            <i class="fa fa-check-circle text-success mb-3" style="font-size:4rem;"></i>
            <h2 class="fw-bold text-dark">Sondaggio Completato!</h2>
            <p class="text-secondary mt-2 fs-5">Il tuo feedback è stato registrato in forma totalmente anonima.<br>Grazie per averci aiutato a migliorare i nostri servizi.</p>
            <div class="mt-4"><a href="index.php" class="btn btn-outline-success fw-bold px-4 py-2"><i class="fa fa-home me-1"></i> Torna alla Home</a></div>
        </div>

    <?php elseif (isset($errore_msg)): ?>
        <div class="card shadow-sm border-0 text-center p-5" style="border-radius:12px; border-top:5px solid #dc3545 !important;">
            <i class="fa fa-info-circle text-danger mb-3" style="font-size:4rem;"></i>
            <h3 class="fw-bold text-dark">Attenzione</h3>
            <p class="text-secondary mt-2 fs-5"><?= htmlspecialchars($errore_msg) ?></p>
            <div class="mt-4"><a href="index.php" class="btn btn-secondary fw-bold px-4 py-2">Torna al sito</a></div>
        </div>

    <?php else: ?>
        <div class="card shadow-lg border-0" style="border-radius:12px; border-top:5px solid #990000 !important; background:#ffffff;">
            <div class="card-header bg-white border-bottom p-4 text-center">
                <span class="badge bg-success mb-2 px-3 py-2"><i class="fa fa-user-secret me-1"></i> Questionario 100% Anonimo</span>
                <h2 class="fw-black m-0 mt-2" style="color:#990000;"><?= htmlspecialchars($sondaggio['titolo']) ?></h2>
                <h6 class="text-secondary mt-2 fw-bold">Evento: <?= htmlspecialchars($prenotazione['evento_titolo']) ?></h6>
            </div>

            <div class="card-body p-4 p-md-5 bg-light">
                <?php if ($errore_invio): ?>
                    <div class="alert alert-danger fw-bold"><i class="fa fa-exclamation-triangle me-2"></i><?= htmlspecialchars($errore_invio) ?></div>
                <?php endif; ?>
                <form method="POST" id="formSondaggio">
                    <input type="hidden" name="sondaggio_id" value="<?= $sond_id ?>">

                    <?php
                    // Build id→domanda map for conditional resolution
                    $dom_map = [];
                    foreach ($domande as $d) $dom_map[$d['id']] = $d;

                    $q_num = 0;
                    foreach ($domande as $d):
                        $tipo      = $d['tipo'];
                        $n_risp    = 'risposta[' . $d['id'] . ']';
                        $cond_data = !empty($d['condizione_json']) ? json_decode($d['condizione_json'], true) : null;
                        $is_cond   = ($cond_data && isset($cond_data['se_id']) && isset($cond_data['se_val']));
                        $req_attr  = (!$is_cond && $d['obbligatorio']) ? 'required' : '';
                        $orig_req  = $d['obbligatorio'] ? 'data-orig-req="1"' : '';
                        $ast       = $d['obbligatorio'] ? '<span class="text-danger">*</span>' : '';

                        if ($tipo === 'separator'):
                    ?>
                        <div class="my-4 border-top border-2 pt-1"
                            <?= $is_cond ? 'id="domWrap_' . $d['id'] . '" data-cond-id="' . $cond_data['se_id'] . '" data-cond-val="' . htmlspecialchars($cond_data['se_val']) . '" style="display:none;"' : '' ?>>
                            <span class="small fw-bold text-muted text-uppercase"><?= !empty($d['testo_domanda']) ? htmlspecialchars($d['testo_domanda']) : '' ?></span>
                        </div>
                    <?php continue; endif;

                        $q_num++;
                    ?>
                        <div class="bg-white p-4 rounded shadow-sm border mb-4"
                            <?= $is_cond ? 'id="domWrap_' . $d['id'] . '" data-cond-id="' . $cond_data['se_id'] . '" data-cond-val="' . htmlspecialchars($cond_data['se_val']) . '" style="display:none;"' : 'id="domWrap_' . $d['id'] . '"' ?>>

                            <h5 class="fw-bold mb-3 text-dark fs-6">
                                <span class="badge bg-danger me-2"><?= $q_num ?></span>
                                <?= htmlspecialchars($d['testo_domanda']) ?><?= $ast ?>
                            </h5>

                            <div class="mt-3">
                            <?php if ($tipo === 'rating'): ?>
                                <div class="d-flex flex-wrap gap-3">
                                    <?php for($i=1; $i<=5; $i++): ?>
                                        <div class="form-check form-check-inline m-0 p-0 text-center">
                                            <input class="btn-check" type="radio" name="<?= $n_risp ?>" id="r_<?= $d['id'].'_'.$i ?>" value="<?= $i ?>" <?= $req_attr ?> <?= $orig_req ?>>
                                            <label class="btn btn-outline-warning text-dark fw-bold px-3 py-2" for="r_<?= $d['id'].'_'.$i ?>" style="border-color:#dee2e6;">
                                                <i class="fa fa-star text-warning d-block mb-1 fs-5"></i> <?= $i ?>
                                            </label>
                                        </div>
                                    <?php endfor; ?>
                                </div>
                                <div class="d-flex justify-content-between mt-2 px-1 text-muted small" style="max-width:320px;"><span>Per nulla</span><span>Moltissimo</span></div>

                            <?php elseif ($tipo === 'nps'): ?>
                                <div class="d-flex flex-wrap gap-1 mb-2">
                                    <?php for($i=0; $i<=10; $i++):
                                        $cls = $i >= 9 ? 'nps-pro' : ($i >= 7 ? 'nps-pas' : 'nps-det');
                                    ?>
                                        <div class="nps-btn <?= $cls ?>">
                                            <input type="radio" name="<?= $n_risp ?>" id="nps_<?= $d['id'].'_'.$i ?>" value="<?= $i ?>" <?= $req_attr ?> <?= $orig_req ?>>
                                            <label for="nps_<?= $d['id'].'_'.$i ?>"><?= $i ?></label>
                                        </div>
                                    <?php endfor; ?>
                                </div>
                                <div class="d-flex justify-content-between small text-muted"><span>Per nulla d'accordo</span><span>Totalmente d'accordo</span></div>

                            <?php elseif ($tipo === 'matrice' && !empty($d['opzioni'])): ?>
                                <div class="table-responsive">
                                    <table class="table table-bordered align-middle">
                                        <thead class="table-light">
                                            <tr><th style="width:40%;">Aspetto</th><th class="text-center">1 (Scarso)</th><th class="text-center">2</th><th class="text-center">3</th><th class="text-center">4</th><th class="text-center">5 (Ottimo)</th></tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach (explode(',', $d['opzioni']) as $riga):
                                                $riga = trim($riga); if (empty($riga)) continue; ?>
                                                <tr>
                                                    <td class="fw-bold text-secondary"><?= htmlspecialchars($riga) ?></td>
                                                    <?php for($v=1; $v<=5; $v++): ?>
                                                        <td class="text-center"><input class="form-check-input" type="radio" name="<?= $n_risp . '[' . htmlspecialchars($riga) . ']' ?>" value="<?= $v ?>" <?= $req_attr ?> <?= $orig_req ?> style="width:20px;height:20px;"></td>
                                                    <?php endfor; ?>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>

                            <?php elseif ($tipo === 'textarea'): ?>
                                <textarea name="<?= $n_risp ?>" class="form-control" rows="3" placeholder="Scrivi qui il tuo feedback..." <?= $req_attr ?> <?= $orig_req ?>></textarea>

                            <?php elseif ($tipo === 'select' && !empty($d['opzioni'])): ?>
                                <select name="<?= $n_risp ?>" class="form-select border-primary" <?= $req_attr ?> <?= $orig_req ?>>
                                    <option value="">-- Seleziona un'opzione --</option>
                                    <?php foreach (explode(',', $d['opzioni']) as $opt): $opt=trim($opt); ?>
                                        <option value="<?= htmlspecialchars($opt) ?>"><?= htmlspecialchars($opt) ?></option>
                                    <?php endforeach; ?>
                                </select>

                            <?php elseif ($tipo === 'radio' && !empty($d['opzioni'])): ?>
                                <div class="d-flex flex-column gap-2">
                                    <?php foreach (explode(',', $d['opzioni']) as $o_idx => $opt): $opt=trim($opt); ?>
                                        <div class="form-check p-3 border rounded" style="background:#f8f9fa;">
                                            <input class="form-check-input ms-1" type="radio" name="<?= $n_risp ?>" id="ro_<?= $d['id'].'_'.$o_idx ?>" value="<?= htmlspecialchars($opt) ?>" <?= $req_attr ?> <?= $orig_req ?>>
                                            <label class="form-check-label ms-2 fw-bold text-dark w-100" for="ro_<?= $d['id'].'_'.$o_idx ?>"><?= htmlspecialchars($opt) ?></label>
                                        </div>
                                    <?php endforeach; ?>
                                </div>

                            <?php elseif ($tipo === 'checkboxes' && !empty($d['opzioni'])): ?>
                                <div class="d-flex flex-column gap-2">
                                    <?php foreach (explode(',', $d['opzioni']) as $o_idx => $opt): $opt=trim($opt); ?>
                                        <div class="form-check p-3 border rounded" style="background:#f8f9fa;">
                                            <input class="form-check-input ms-1" type="checkbox" name="<?= $n_risp ?>[]" id="co_<?= $d['id'].'_'.$o_idx ?>" value="<?= htmlspecialchars($opt) ?>" <?= $orig_req ?>>
                                            <label class="form-check-label ms-2 fw-bold text-dark w-100" for="co_<?= $d['id'].'_'.$o_idx ?>"><?= htmlspecialchars($opt) ?></label>
                                        </div>
                                    <?php endforeach; ?>
                                    <?php if($d['obbligatorio'] && !$is_cond): ?><small class="text-danger mt-1"><i class="fa fa-asterisk"></i> Seleziona almeno un'opzione</small><?php endif; ?>
                                </div>

                            <?php elseif ($tipo === 'number'): ?>
                                <input type="number" name="<?= $n_risp ?>" class="form-control" placeholder="0" <?= $req_attr ?> <?= $orig_req ?>>

                            <?php elseif ($tipo === 'date'): ?>
                                <input type="date" name="<?= $n_risp ?>" class="form-control" <?= $req_attr ?> <?= $orig_req ?>>

                            <?php elseif ($tipo === 'email'): ?>
                                <input type="email" name="<?= $n_risp ?>" class="form-control" placeholder="esempio@email.com" <?= $req_attr ?> <?= $orig_req ?>>

                            <?php elseif ($tipo === 'tel'): ?>
                                <input type="tel" name="<?= $n_risp ?>" class="form-control" placeholder="+39 000 0000000" <?= $req_attr ?> <?= $orig_req ?>>

                            <?php elseif ($tipo === 'url'): ?>
                                <input type="url" name="<?= $n_risp ?>" class="form-control" placeholder="https://..." <?= $req_attr ?> <?= $orig_req ?>>

                            <?php elseif ($tipo === 'time'): ?>
                                <input type="time" name="<?= $n_risp ?>" class="form-control" <?= $req_attr ?> <?= $orig_req ?>>

                            <?php else: ?>
                                <input type="text" name="<?= $n_risp ?>" class="form-control" placeholder="Tua risposta" <?= $req_attr ?> <?= $orig_req ?>>
                            <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>

                    <div class="text-center mt-5">
                        <button type="submit" name="submit_sondaggio" class="btn btn-danger btn-lg fw-bold px-5 py-3 shadow" style="background-color:#990000;">
                            <i class="fa fa-paper-plane me-2"></i> Invia Valutazione
                        </button>
                        <p class="text-muted small mt-3"><i class="fa fa-lock me-1"></i> I tuoi dati personali non verranno salvati né associati alle risposte.</p>
                    </div>
                </form>
            </div>
        </div>
    <?php endif; ?>

</div>

<script>
(function() {
    var form = document.getElementById('formSondaggio');
    if (!form) return;

    // Conditional logic
    function getFieldVal(domId) {
        var radios = form.querySelectorAll('input[name="risposta[' + domId + ']"]:checked');
        if (radios.length > 0) return radios[0].value.toLowerCase();
        var sel = form.querySelector('select[name="risposta[' + domId + ']"]');
        if (sel) return sel.value.toLowerCase();
        var inp = form.querySelector('input[name="risposta[' + domId + ']"]');
        if (inp && inp.type !== 'radio' && inp.type !== 'checkbox') return inp.value.toLowerCase();
        return '';
    }

    function evalConditions() {
        document.querySelectorAll('[data-cond-id]').forEach(function(wrap) {
            var condId  = wrap.dataset.condId;
            var condVal = wrap.dataset.condVal.toLowerCase();
            var show    = (getFieldVal(condId) === condVal);
            wrap.style.display = show ? '' : 'none';
            wrap.querySelectorAll('input, select, textarea').forEach(function(inp) {
                if (show && inp.dataset.origReq === '1') {
                    inp.required = true;
                } else {
                    inp.required = false;
                    if (!show) {
                        if (inp.type === 'checkbox' || inp.type === 'radio') inp.checked = false;
                        else inp.value = '';
                    }
                }
            });
        });
    }

    form.addEventListener('change', evalConditions);
    form.addEventListener('input', evalConditions);
    evalConditions();

    // Validation: checkboxes obbligatorie visibili
    form.addEventListener('submit', function(event) {
        var groups = {};
        form.querySelectorAll('input[type="checkbox"][name*="[]"]').forEach(function(cb) {
            var wrap = cb.closest('[id^="domWrap_"]');
            if (wrap && wrap.style.display === 'none') return;
            if (!groups[cb.name]) groups[cb.name] = [];
            groups[cb.name].push(cb);
        });
        for (var name in groups) {
            var cbs = groups[name];
            var container = cbs[0].closest('.mt-3');
            var required  = container && container.querySelector('.text-danger') !== null;
            if (required && !cbs.some(function(c) { return c.checked; })) {
                event.preventDefault();
                alert("Seleziona almeno un'opzione per le domande obbligatorie a scelta multipla.");
                return;
            }
        }
    });
})();
</script>

<?php require_once 'footer.php'; ?>
