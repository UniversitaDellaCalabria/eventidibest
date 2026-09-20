<?php
require_once 'config.php';

function inviaNotificaEmail($destinatario_email, $destinatario_nome, $chiave_template, $rimpiazzi = []) {
    global $conn;

    // Recupera configurazione SMTP
    $res_cfg = $conn->query("SELECT * FROM impostazioni_sistema WHERE id = 1 LIMIT 1");
    if (!$res_cfg || $res_cfg->num_rows === 0) return false;
    $smtp = $res_cfg->fetch_assoc();

    if (empty($smtp['smtp_host']) || empty($smtp['smtp_from_email'])) return false;

    // Recupera Template Email
    $res_tpl = $conn->query("SELECT * FROM template_email WHERE chiave = '$chiave_template' LIMIT 1");
    if (!$res_tpl || $res_tpl->num_rows === 0) return false;
    $tpl = $res_tpl->fetch_assoc();

    $oggetto = $tpl['oggetto'];
    $corpo = $tpl['corpo_html'];

    // Sostituisci i placeholder dinamici (es: {NOME}, {CODICE})
    foreach ($rimpiazzi as $placeholder => $valore) {
        $oggetto = str_replace('{' . $placeholder . '}', $valore, $oggetto);
        $corpo = str_replace('{' . $placeholder . '}', $valore, $corpo);
    }

    // Se inclusa la libreria PHPMailer via vendor/autoload
    if (file_exists('../vendor/autoload.php')) {
        require_once '../vendor/autoload.php';
        $mail = new PHPMailer\PHPMailer\PHPMailer(true);
        try {
            $mail->isSMTP();
            $mail->Host       = $smtp['smtp_host'];
            $mail->SMTPAuth   = !empty($smtp['smtp_username']);
            $mail->Username   = $smtp['smtp_username'];
            $mail->Password   = $smtp['smtp_password'];
            $mail->SMTPSecure = $smtp['smtp_secure'];
            $mail->Port       = (int)$smtp['smtp_port'];
            $mail->CharSet    = 'UTF-8';

            $mail->setFrom($smtp['smtp_from_email'], $smtp['smtp_from_name']);
            $mail->addAddress($destinatario_email, $destinatario_nome);

            $mail->isHTML(true);
            $mail->Subject = $oggetto;
            $mail->Body    = $corpo;

            $mail->send();
            return true;
        } catch (Exception $e) {
            error_log("Errore Invio Email SMTP: " . $e->getMessage());
            return false;
        }
    }
    
    // Fallback mail() nativa PHP se PHPMailer non presente
    $headers = "MIME-Version: 1.0" . "\r\n";
    $headers .= "Content-type:text/html;charset=UTF-8" . "\r\n";
    $headers .= 'From: ' . $smtp['smtp_from_name'] . ' <' . $smtp['smtp_from_email'] . '>' . "\r\n";
    return mail($destinatario_email, $oggetto, $corpo, $headers);
}
?>
