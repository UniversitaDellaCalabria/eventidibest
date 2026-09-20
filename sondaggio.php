<?php
// sondaggio.php - Pagina pubblica per la compilazione anonima del questionario
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('display_startup_errors', 0);
error_reporting(E_ALL);

if (session_status() === PHP_SESSION_NONE) { session_start(); }
require_once 'config.php';
require_once 'functions.php';

$token = $conn->real_escape_string(trim($_GET['token'] ?? ''));

// 1. VERIFICHE DI SICUREZZA E VALIDITÀ DEL TOKEN
if (empty($token)) {
    $errore_msg = "Token di accesso mancante. Impossibile caricare il sondaggio.";
} else {
    $res_pren = $conn->query("SELECT pr.*, t.evento_id, e.titolo as evento_titolo FROM prenotazioni pr JOIN turni t ON pr.turno_id = t.id JOIN eventi e ON t.evento_id = e.id WHERE pr.token_sondaggio = '$token' LIMIT 1");
    
    if (!$res_pren || $res_pren->num_rows === 0) {
        $errore_msg = "Link non valido o scaduto.";
    } else {
        $prenotazione = $res_pren->fetch_assoc();
        
        if ((int)$prenotazione['sondaggio_completato'] === 1) {
            $errore_msg = "Hai già compilato questo questionario. Grazie per il tuo feedback!";
        } else {
            // Cerca il sondaggio attivo per questo evento
            $ev_id = (int)$prenotazione['evento_id'];
            $res_sond = $conn->query("SELECT * FROM sondaggi WHERE evento_id = $ev_id AND attivo = 1 LIMIT 1");
            
            if (!$res_sond || $res_sond->num_rows === 0) {
                $errore_msg = "Al momento non ci sono sondaggi attivi per questo evento.";
            } else {
                $sondaggio = $res_sond->fetch_assoc();
                $sond_id = (int)$sondaggio['id'];
                
                // Recupera le domande
                $domande = [];
                $res_dom = $conn->query("SELECT * FROM sondaggi_domande WHERE sondaggio_id = $sond_id ORDER BY ordine ASC, id ASC");
                if ($res_dom) { while($d = $res_dom->fetch_assoc()) { $domande[] = $d; } }
            }
        }
    }
}

// 2. ELABORAZIONE DEL SALVATAGGIO (POST)
$successo = false;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_sondaggio']) && !isset($errore_msg)) {
    $s_id_post = (int)$_POST['sondaggio_id'];
    
    // Sicurezza extra: verifica che si stia compilando il sondaggio corretto
    if ($s_id_post === $sond_id) {
        if (isset($_POST['risposta']) && is_array($_POST['risposta'])) {
            foreach ($_POST['risposta'] as $d_id => $valore) {
                $d_id_clean = (int)$d_id;
                
                // Gestione dei dati multi-scelta e della matrice JSON
                if (is_array($valore)) {
                    // Controlliamo se ci arriva un array di valori (Checkbox o Matrice)
                    $valore_clean = $conn->real_escape_string(json_encode($valore, JSON_UNESCAPED_UNICODE));
                } else {
                    $valore_clean = $conn->real_escape_string(trim($valore));
                }
                
                if ($valore_clean !== '' && $valore_clean !== '[]') {
                    $conn->query("INSERT INTO sondaggi_risposte (sondaggio_id, domanda_id, risposta) VALUES ($s_id_post, $d_id_clean, '$valore_clean')");
                }
            }
        }
        // Disattiva il token per questo utente
        $pr_id = (int)$prenotazione['id'];
        $conn->query("UPDATE prenotazioni SET sondaggio_completato = 1 WHERE id = $pr_id");
        $successo = true;
    }
}

$page_cfg['titolo'] = "Questionario di Gradimento";
require_once 'header.php';
?>

<div class="container my-5" style="max-width: 800px;">
    
    <?php if ($successo): ?>
        <div class="card shadow-lg border-0 text-center p-5" style="border-radius: 12px; border-top: 5px solid #198754 !important;">
            <i class="fa fa-check-circle text-success mb-3" style="font-size: 4rem;"></i>
            <h2 class="fw-bold text-dark">Sondaggio Completato!</h2>
            <p class="text-secondary mt-2 fs-5">Il tuo feedback è stato registrato in forma totalmente anonima.<br>Grazie per averci aiutato a migliorare i nostri servizi.</p>
            <div class="mt-4">
                <a href="index.php" class="btn btn-outline-success fw-bold px-4 py-2"><i class="fa fa-home me-1"></i> Torna alla Home</a>
            </div>
        </div>
    
    <?php elseif (isset($errore_msg)): ?>
        <div class="card shadow-sm border-0 text-center p-5" style="border-radius: 12px; border-top: 5px solid #dc3545 !important;">
            <i class="fa fa-info-circle text-danger mb-3" style="font-size: 4rem;"></i>
            <h3 class="fw-bold text-dark">Attenzione</h3>
            <p class="text-secondary mt-2 fs-5"><?php echo htmlspecialchars($errore_msg); ?></p>
            <div class="mt-4">
                <a href="index.php" class="btn btn-secondary fw-bold px-4 py-2">Torna al sito</a>
            </div>
        </div>

    <?php else: ?>
        <div class="card shadow-lg border-0" style="border-radius: 12px; border-top: 5px solid #990000 !important; background: #ffffff;">
            <div class="card-header bg-white border-bottom p-4 text-center">
                <span class="badge bg-success mb-2 px-3 py-2"><i class="fa fa-user-secret me-1"></i> Questionario 100% Anonimo</span>
                <h2 class="fw-black m-0 mt-2 text-dark" style="color: #990000 !important;"><?php echo htmlspecialchars($sondaggio['titolo']); ?></h2>
                <h6 class="text-secondary mt-2 fw-bold">Evento: <?php echo htmlspecialchars($prenotazione['evento_titolo']); ?></h6>
            </div>
            
            <div class="card-body p-4 p-md-5 bg-light">
                <form method="POST">
                    <input type="hidden" name="sondaggio_id" value="<?php echo $sond_id; ?>">
                    
                    <?php foreach ($domande as $index => $d): ?>
                        <?php 
                            $req = $d['obbligatorio'] ? 'required' : ''; 
                            $ast = $d['obbligatorio'] ? '<span class="text-danger">*</span>' : ''; 
                            $n_risp = 'risposta[' . $d['id'] . ']';
                        ?>
                        <div class="bg-white p-4 rounded shadow-sm border mb-4">
                            <h5 class="fw-bold mb-3 text-dark fs-6">
                                <span class="badge bg-danger me-2"><?php echo ($index + 1); ?></span> 
                                <?php echo htmlspecialchars($d['testo_domanda']) . $ast; ?>
                            </h5>
                            
                            <div class="mt-3">
                                <!-- VOTO CLASSICO (RATING 1-5) -->
                                <?php if ($d['tipo'] === 'rating'): ?>
                                    <div class="d-flex flex-wrap gap-3">
                                        <?php for($i=1; $i<=5; $i++): ?>
                                            <div class="form-check form-check-inline m-0 p-0 text-center">
                                                <input class="btn-check" type="radio" name="<?php echo $n_risp; ?>" id="r_<?php echo $d['id'].'_'.$i; ?>" value="<?php echo $i; ?>" <?php echo $req; ?>>
                                                <label class="btn btn-outline-warning text-dark fw-bold px-3 py-2" for="r_<?php echo $d['id'].'_'.$i; ?>" style="border-color: #dee2e6;">
                                                    <i class="fa fa-star text-warning d-block mb-1 fs-5"></i> <?php echo $i; ?>
                                                </label>
                                            </div>
                                        <?php endfor; ?>
                                    </div>
                                    <div class="d-flex justify-content-between mt-2 px-1 text-muted small" style="max-width: 320px;">
                                        <span>Per nulla</span>
                                        <span>Moltissimo</span>
                                    </div>

                                <!-- MATRICE DI VALUTAZIONE -->
                                <?php elseif ($d['tipo'] === 'matrice' && !empty($d['opzioni'])): ?>
                                    <div class="table-responsive">
                                        <table class="table table-bordered align-middle">
                                            <thead class="table-light">
                                                <tr>
                                                    <th style="width: 40%;">Aspetto</th>
                                                    <th class="text-center">1 (Scarso)</th>
                                                    <th class="text-center">2</th>
                                                    <th class="text-center">3</th>
                                                    <th class="text-center">4</th>
                                                    <th class="text-center">5 (Ottimo)</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php 
                                                    $righe = explode(',', $d['opzioni']);
                                                    foreach ($righe as $r_idx => $riga): 
                                                        $riga = trim($riga);
                                                        if (empty($riga)) continue;
                                                ?>
                                                    <tr>
                                                        <td class="fw-bold text-secondary"><?php echo htmlspecialchars($riga); ?></td>
                                                        <?php for($v=1; $v<=5; $v++): ?>
                                                            <td class="text-center">
                                                                <input class="form-check-input" type="radio" name="<?php echo $n_risp . '[' . htmlspecialchars($riga) . ']'; ?>" value="<?php echo $v; ?>" <?php echo $req; ?> style="width: 20px; height: 20px;">
                                                            </td>
                                                        <?php endfor; ?>
                                                    </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>

                                <!-- TESTO ESTESO -->
                                <?php elseif ($d['tipo'] === 'textarea'): ?>
                                    <textarea name="<?php echo $n_risp; ?>" class="form-control" rows="3" placeholder="Scrivi qui il tuo feedback..." <?php echo $req; ?>></textarea>

                                <!-- MENU A TENDINA (SELECT) -->
                                <?php elseif ($d['tipo'] === 'select' && !empty($d['opzioni'])): ?>
                                    <select name="<?php echo $n_risp; ?>" class="form-select border-primary" <?php echo $req; ?>>
                                        <option value="">-- Seleziona un'opzione --</option>
                                        <?php 
                                            $opts = explode(',', $d['opzioni']);
                                            foreach ($opts as $opt): 
                                                $opt = trim($opt);
                                        ?>
                                            <option value="<?php echo htmlspecialchars($opt); ?>"><?php echo htmlspecialchars($opt); ?></option>
                                        <?php endforeach; ?>
                                    </select>

                                <!-- SCELTA SINGOLA (RADIO) -->
                                <?php elseif ($d['tipo'] === 'radio' && !empty($d['opzioni'])): ?>
                                    <div class="d-flex flex-column gap-2">
                                        <?php 
                                            $opts = explode(',', $d['opzioni']);
                                            foreach ($opts as $o_idx => $opt): 
                                                $opt = trim($opt);
                                        ?>
                                            <div class="form-check p-3 border rounded" style="background: #f8f9fa;">
                                                <input class="form-check-input ms-1" type="radio" name="<?php echo $n_risp; ?>" id="ro_<?php echo $d['id'].'_'.$o_idx; ?>" value="<?php echo htmlspecialchars($opt); ?>" <?php echo $req; ?>>
                                                <label class="form-check-label ms-2 fw-bold text-dark w-100 cursor-pointer" for="ro_<?php echo $d['id'].'_'.$o_idx; ?>">
                                                    <?php echo htmlspecialchars($opt); ?>
                                                </label>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>

                                <!-- SCELTA MULTIPLA (CHECKBOXES) -->
                                <?php elseif ($d['tipo'] === 'checkboxes' && !empty($d['opzioni'])): ?>
                                    <div class="d-flex flex-column gap-2">
                                        <?php 
                                            $opts = explode(',', $d['opzioni']);
                                            foreach ($opts as $o_idx => $opt): 
                                                $opt = trim($opt);
                                        ?>
                                            <div class="form-check p-3 border rounded" style="background: #f8f9fa;">
                                                <!-- NOTA: Array name per multi-selezione -->
                                                <input class="form-check-input ms-1" type="checkbox" name="<?php echo $n_risp; ?>[]" id="co_<?php echo $d['id'].'_'.$o_idx; ?>" value="<?php echo htmlspecialchars($opt); ?>">
                                                <label class="form-check-label ms-2 fw-bold text-dark w-100 cursor-pointer" for="co_<?php echo $d['id'].'_'.$o_idx; ?>">
                                                    <?php echo htmlspecialchars($opt); ?>
                                                </label>
                                            </div>
                                        <?php endforeach; ?>
                                        <?php if($req): ?>
                                            <small class="text-danger mt-1"><i class="fa fa-asterisk"></i> Seleziona almeno un'opzione</small>
                                        <?php endif; ?>
                                    </div>

                                <!-- TESTO BREVE -->
                                <?php else: ?>
                                    <input type="text" name="<?php echo $n_risp; ?>" class="form-control" placeholder="Tua risposta" <?php echo $req; ?>>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>

                    <div class="text-center mt-5">
                        <button type="submit" name="submit_sondaggio" class="btn btn-danger btn-lg fw-bold px-5 py-3 shadow" style="background-color: #990000;">
                            <i class="fa fa-paper-plane me-2"></i> Invia Valutazione
                        </button>
                        <p class="text-muted small mt-3"><i class="fa fa-lock me-1"></i> I tuoi dati personali non verranno salvati né associati alle risposte inviate.</p>
                    </div>
                </form>
            </div>
        </div>
    <?php endif; ?>

</div>

<!-- SCRIPT PER VALIDARE I CHECKBOX OBBLIGATORI -->
<script>
document.addEventListener("DOMContentLoaded", function() {
    const form = document.querySelector("form");
    if (form) {
        form.addEventListener("submit", function(event) {
            let isValid = true;
            // Cerca tutti i blocchi che contengono checkboxes
            const checkboxGroups = document.querySelectorAll('input[type="checkbox"][name*="[]"]');
            
            if(checkboxGroups.length > 0) {
                // Raggruppa i checkbox per "name"
                const groups = {};
                checkboxGroups.forEach(cb => {
                    if (!groups[cb.name]) groups[cb.name] = [];
                    groups[cb.name].push(cb);
                });

                // Per ogni gruppo, se era richiesto (vediamo il testo rosso associato), almeno uno deve essere flaggato
                for (const groupName in groups) {
                    const checkboxes = groups[groupName];
                    const container = checkboxes[0].closest('.mt-3');
                    const isRequired = container.querySelector('.text-danger') !== null;
                    
                    if (isRequired) {
                        const isChecked = checkboxes.some(cb => cb.checked);
                        if (!isChecked) {
                            isValid = false;
                            alert("Seleziona almeno un'opzione per le domande obbligatorie a scelta multipla.");
                            event.preventDefault();
                            break;
                        }
                    }
                }
            }
        });
    }
});
</script>

<?php require_once 'footer.php'; ?>
