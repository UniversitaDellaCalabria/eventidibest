<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
// Rimosso require_once 'config.php' per evitare i redirect automatici di sicurezza
?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sessione Chiusa - EventiDiBEST</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body {
            background-color: #f1f5f9;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0;
        }
        .logout-card {
            max-width: 520px;
            width: 100%;
            background: #ffffff;
            border-radius: 12px;
            border-top: 5px solid #990000;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.08);
            padding: 2.5rem;
            text-align: center;
        }
        .security-box {
            background-color: #fffde7;
            border-left: 4px solid #d97706;
            padding: 1rem 1.25rem;
            border-radius: 4px;
            text-align: left;
            margin: 1.5rem 0;
            color: #78350f;
        }
    </style>
</head>
<body>

<div class="logout-card">
    <!-- LOGO PORTALE -->
    <div class="mb-4">
        <img src="uploads/logo_attestato_1788960266.png" alt="Logo Unical DiBEST" style="max-height: 70px; object-fit: contain;">
    </div>

    <!-- TITOLO DISCONNESSIONE -->
    <h3 class="fw-bold text-dark mb-2">Sessione Chiusa</h3>
    <p class="text-secondary mb-4">Sei uscito correttamente dal portale <strong>EventiDiBEST</strong>.</p>

    <!-- BOX AVVISO DI SICUREZZA SSO -->
    <div class="security-box">
        <div class="fw-bold mb-1 text-uppercase small" style="color: #92400e;">
            <i class="fa fa-shield-alt me-1"></i> Avviso di Sicurezza
        </div>
        <div class="small" style="line-height: 1.5;">
            Per completare il logout dal sistema di Ateneo (SAML) e proteggere i tuoi dati, è necessario <strong>chiudere tutte le finestre del browser</strong>.
        </div>
    </div>

    <!-- BOTTONE PER TORNARE ALLA HOME -->
    <div class="mt-4">
        <a href="index.php" class="btn btn-outline-danger fw-bold px-4 py-2">Torna alla Home</a>
    </div>

    <div class="mt-4 pt-3 border-top">
        <small class="text-muted">Università della Calabria - DiBEST</small>
    </div>
</div>

</body>
</html>
