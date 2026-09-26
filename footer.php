</main>
<!-- FINE WRAPPER PRINCIPALE CONTENUTI -->

<?php
// RECUPERO SICURO DATI FOOTER
if (!isset($conn) || !($conn instanceof mysqli)) {
    if (file_exists(__DIR__ . '/config.php')) { require_once __DIR__ . '/config.php'; }
}
// Fase 3: serve per get_configurazione_portale(); se footer.php viene incluso dopo
// header.php è già caricato, altrimenti lo includiamo qui in sicurezza.
if (!function_exists('get_configurazione_portale') && file_exists(__DIR__ . '/functions.php')) {
    require_once __DIR__ . '/functions.php';
}

$footer_titolo = 'EventiDiBEST';
$footer_sottotitolo = 'Portale Eventi e Laboratori Dipartimentali';
$footer_nome_dipartimento = 'DiBEST - Dipartimento di Biologia, Ecologia e Scienze della Terra (UNICAL)';
$footer_indirizzo = 'Via Pietro Bucci, 87036 Rende (CS)';
$footer_contatti = 'Email: dipartimento@unical.it';
$footer_realizzato_da = 'Realizzato per il Dipartimento da Emanuele Dodaro';
$footer_assistenza = 'emanuele.dodaro@unical.it';
$footer_copyright = 'Università della Calabria - DiBEST';

if (isset($conn) && $conn instanceof mysqli) {
    // Fase 3: usa la cache locale (stessa fonte dati di header.php) invece di una
    // seconda query diretta al DB. Fallback sulla query se functions.php manca.
    if (function_exists('get_configurazione_portale')) {
        $row_foot = get_configurazione_portale($conn);
    } else {
        $res_foot = @$conn->query("SELECT nome_portale, sottotitolo_portale, footer_nome_dipartimento, footer_indirizzo, footer_contatti, footer_realizzato_da, footer_assistenza, footer_copyright FROM configurazione_portale WHERE id = 1");
        $row_foot = ($res_foot && $res_foot->num_rows > 0) ? $res_foot->fetch_assoc() : null;
    }
    if (!empty($row_foot)) {
        $footer_titolo = !empty($row_foot['nome_portale']) ? $row_foot['nome_portale'] : $footer_titolo;
        $footer_sottotitolo = !empty($row_foot['sottotitolo_portale']) ? $row_foot['sottotitolo_portale'] : $footer_sottotitolo;
        
        $footer_nome_dipartimento = !empty($row_foot['footer_nome_dipartimento']) ? $row_foot['footer_nome_dipartimento'] : $footer_nome_dipartimento;
        $footer_indirizzo = !empty($row_foot['footer_indirizzo']) ? $row_foot['footer_indirizzo'] : $footer_indirizzo;
        $footer_contatti = !empty($row_foot['footer_contatti']) ? $row_foot['footer_contatti'] : $footer_contatti;
        
        $footer_realizzato_da = !empty($row_foot['footer_realizzato_da']) ? $row_foot['footer_realizzato_da'] : $footer_realizzato_da;
        $footer_assistenza = !empty($row_foot['footer_assistenza']) ? $row_foot['footer_assistenza'] : $footer_assistenza;
        $footer_copyright = !empty($row_foot['footer_copyright']) ? $row_foot['footer_copyright'] : $footer_copyright;
    }
}
?>

<!-- FOOTER CUSTOM -->
<footer style="background-color: #990000; margin-top: auto;">
    <div class="py-4">
        <div class="container">
            <div class="row align-items-center text-center text-md-start">
                
                <!-- COLONNA SINISTRA: Titoli, Dipartimento, Indirizzo e Mail -->
                <div class="col-md-6 mb-4 mb-md-0 border-md-end" style="border-color: rgba(255,255,255,0.2) !important;">
                    <h3 class="fw-bold text-white mb-1"><?php echo htmlspecialchars($footer_titolo); ?></h3>
                    <p class="text-white opacity-75 small mb-3"><?php echo htmlspecialchars($footer_sottotitolo); ?></p>
                    
                    <p class="text-white fw-bold small mb-1"><?php echo htmlspecialchars($footer_nome_dipartimento); ?></p>
                    <p class="text-white opacity-75 small mb-1"><i class="fa fa-map-marker-alt me-1"></i> <?php echo htmlspecialchars($footer_indirizzo); ?></p>
                    <p class="text-white opacity-75 small mb-0"><i class="fa fa-envelope me-1"></i> <?php echo htmlspecialchars($footer_contatti); ?></p>
                </div>

                <!-- COLONNA DESTRA: Link Utili, Crediti e Assistenza -->
                <div class="col-md-6 ps-md-4">
                    <div class="d-flex flex-wrap justify-content-center justify-content-md-start gap-3 mb-2">
                        <a href="area_personale.php" class="text-white text-decoration-none small"><i class="fa fa-user-circle me-1"></i> Area Personale</a>
                        <a href="admin/index.php" class="text-white text-decoration-none small"><i class="fa fa-cogs me-1"></i> Pannello Gestori</a>
                        <a href="https://www.unical.it" target="_blank" class="text-white text-decoration-none small"><i class="fa fa-university me-1"></i> Portale Unical</a>
                    </div>
                    <div class="text-white small opacity-75 mt-3 pt-2 border-top" style="border-color: rgba(255,255,255,0.2) !important;">
                        Sistema di Prenotazione e Gestione Eventi<br>
                        <strong class="text-white"><?php echo htmlspecialchars($footer_realizzato_da); ?></strong><br>
                        <span class="mt-1 d-inline-block">Per assistenza: <span class="text-white fw-bold"><?php echo htmlspecialchars($footer_assistenza); ?></span></span>
                    </div>
                </div>

            </div>
        </div>
    </div>
    
    <!-- BARRA INFERIORE: PRIVACY, COOKIE E COPYRIGHT -->
    <div class="py-3" style="background-color: #333333;">
        <div class="container d-flex flex-wrap justify-content-between align-items-center small text-white opacity-75">
            <ul class="list-inline mb-0 d-flex flex-wrap gap-4">
                <li class="list-inline-item"><a class="text-white text-decoration-none" href="https://www.unical.it/privacy/" target="_blank">Privacy Policy</a></li>
                <li class="list-inline-item"><a class="text-white text-decoration-none" href="https://www.unical.it/privacy/cookie/" target="_blank">Cookie Policy</a></li>
                <li class="list-inline-item"><a class="text-white text-decoration-none" href="https://www.unical.it/note-legali/" target="_blank">Note Legali</a></li>
                <li class="list-inline-item"><a class="text-white text-decoration-none" href="https://www.unical.it/accessibilita/" target="_blank">Accessibilità</a></li>
            </ul>
            <div class="mt-2 mt-md-0 fw-bold">
                © <?php echo date('Y'); ?> <?php echo htmlspecialchars($footer_copyright); ?>
            </div>
        </div>
    </div>
</footer>

<script src="https://cdn.jsdelivr.net/npm/bootstrap-italia@2.8.3/dist/js/bootstrap-italia.bundle.min.js"></script>

<!-- Fase 4: Registrazione Service Worker PWA (Stale-While-Revalidate + Cache-First + fallback offline) -->
<script>
if ('serviceWorker' in navigator) {
    window.addEventListener('load', function () {
        navigator.serviceWorker.register('/eventi/sw.js', { scope: '/eventi/' })
            .then(function (reg) {
                // Controlla aggiornamenti ogni volta che l'utente naviga
                reg.update();

                // Se c'è un nuovo SW in attesa, ricarica la pagina per attivarlo subito
                reg.addEventListener('updatefound', function () {
                    var newWorker = reg.installing;
                    if (!newWorker) return;
                    newWorker.addEventListener('statechange', function () {
                        if (newWorker.state === 'installed' && navigator.serviceWorker.controller) {
                            // SW aggiornato: ricarica silenziosamente per applicarlo
                            newWorker.postMessage({ type: 'SKIP_WAITING' });
                            navigator.serviceWorker.addEventListener('controllerchange', function () {
                                window.location.reload();
                            });
                        }
                    });
                });
            })
            .catch(function (err) {
                // SW non disponibile: app funziona normalmente senza cache offline
                console.warn('[SW] Registrazione non riuscita:', err);
            });
    });
}
</script>

<!-- BANNER COOKIE NATIVO -->
<div id="cookieBanner" role="region" aria-label="Informativa cookie" style="display: none; position: fixed; bottom: 0; left: 0; width: 100%; background: #1e293b; color: white; padding: 15px 20px; z-index: 9999; box-shadow: 0 -4px 15px rgba(0,0,0,0.2);">
    <div class="container d-flex flex-column flex-md-row justify-content-between align-items-center gap-3">
        <div class="small" style="line-height: 1.4;">
            <i class="fa fa-cookie-bite text-warning me-2 fs-4 align-middle"></i>
            <strong>Informativa:</strong> Questo portale utilizza esclusivamente cookie tecnici necessari per il corretto funzionamento del sistema (come il mantenimento della sessione di login tramite SSO). Non utilizziamo cookie di profilazione o tracciamento a fini pubblicitari.
        </div>
        <div class="flex-shrink-0 text-center text-md-end">
            <button id="acceptCookies" class="btn btn-light btn-sm fw-bold px-4 py-2 text-dark shadow-sm rounded-pill">Ho capito</button>
        </div>
    </div>
</div>

<script>
document.addEventListener("DOMContentLoaded", function() {
    if (!localStorage.getItem("cookie_dibest_accepted")) {
        document.getElementById("cookieBanner").style.display = "block";
    }

    document.getElementById("acceptCookies").addEventListener("click", function() {
        localStorage.setItem("cookie_dibest_accepted", "true");
        document.getElementById("cookieBanner").style.opacity = "0";
        setTimeout(() => { document.getElementById("cookieBanner").style.display = "none"; }, 300);
    });
});
</script>

</body>
</html>