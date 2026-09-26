<?php
// genera_ics.php - File .ics (Outlook / Apple / Google Calendar) per un turno
require_once 'config.php';
require_once 'functions.php';

$t_id = isset($_GET['t_id']) ? (int)$_GET['t_id'] : 0;
if ($t_id <= 0) { http_response_code(400); die("Turno non valido."); }

$t = get_turno_con_evento($conn, $t_id);
if (!$t) { http_response_code(404); die("Turno non trovato."); }

// Un turno senza data non può stare in un calendario
if (empty($t['data_turno'])) {
    http_response_code(404);
    die("Questo turno non ha una data fissa: non può essere aggiunto al calendario.");
}

// Testo per i campi .ics (RFC 5545): niente HTML, backslash/virgole/punti e virgola codificati, a capo come \n
function ics_testo(string $s): string {
    $s = html_entity_decode(strip_tags($s), ENT_QUOTES, 'UTF-8');
    $s = str_replace(["\\", ";", ",", "\r\n", "\n", "\r"], ["\\\\", "\\;", "\\,", "\\n", "\\n", ""], trim($s));
    return $s;
}
// Righe .ics piegate a 75 byte, come richiesto dal formato (le righe lunghe vengono spezzate da alcuni client)
function ics_riga(string $riga): string {
    $out = '';
    while (strlen($riga) > 75) {
        $taglio = 75;
        while ($taglio > 0 && (ord($riga[$taglio]) & 0xC0) === 0x80) $taglio--; // non spezzare un carattere UTF-8
        $out .= substr($riga, 0, $taglio) . "\r\n ";
        $riga = substr($riga, $taglio);
    }
    return $out . $riga . "\r\n";
}

$giorno = date('Ymd', strtotime($t['data_turno']));
if (!empty($t['orario_inizio'])) {
    // Orari locali (fuso di Roma)
    $inizio = strtotime($t['data_turno'] . ' ' . $t['orario_inizio']);
    $fine   = !empty($t['orario_fine']) ? strtotime($t['data_turno'] . ' ' . $t['orario_fine']) : $inizio + 3600; // senza fine: 1 ora
    if ($fine <= $inizio) $fine = $inizio + 3600;
    $dtstart = 'DTSTART;TZID=Europe/Rome:' . date('Ymd\THis', $inizio);
    $dtend   = 'DTEND;TZID=Europe/Rome:' . date('Ymd\THis', $fine);
} else {
    // Senza orario: evento di un'intera giornata
    $dtstart = 'DTSTART;VALUE=DATE:' . $giorno;
    $dtend   = 'DTEND;VALUE=DATE:' . date('Ymd', strtotime($t['data_turno'] . ' +1 day'));
}

$titolo = $t['evento_titolo'] . (!empty($t['nome_turno']) ? ' - ' . $t['nome_turno'] : '');
$luogo  = $t['luogo'] ?? '';
$host   = parse_url(url_base_sito(), PHP_URL_HOST) ?: 'eventi.unical.it';

header('Content-Type: text/calendar; charset=utf-8');
header('Content-Disposition: attachment; filename="evento_' . $t_id . '.ics"');

echo "BEGIN:VCALENDAR\r\n";
echo "VERSION:2.0\r\n";
echo "PRODID:-//DiBEST Unical//Eventi v1.0//IT\r\n";
echo "CALSCALE:GREGORIAN\r\n";
echo "METHOD:PUBLISH\r\n";
echo "BEGIN:VEVENT\r\n";
echo "UID:turno-" . $t_id . "@" . $host . "\r\n";
echo "DTSTAMP:" . gmdate('Ymd\THis\Z') . "\r\n";
echo $dtstart . "\r\n";
echo $dtend . "\r\n";
echo ics_riga("SUMMARY:" . ics_testo($titolo));
if (!empty($t['descrizione'])) echo ics_riga("DESCRIPTION:" . ics_testo((string)$t['descrizione']));
if ($luogo !== '') echo ics_riga("LOCATION:" . ics_testo($luogo));
echo "END:VEVENT\r\n";
echo "END:VCALENDAR\r\n";
exit;
