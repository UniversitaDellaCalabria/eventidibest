<?php
require_once 'config.php';
require_once 'functions.php';

$t_id = isset($_GET['t_id']) ? (int)$_GET['t_id'] : 0;
if ($t_id <= 0) { die("Turno non valido."); }

$t = get_turno_con_evento($conn, $t_id);
if (!$t) { die("Turno non trovato."); }

$dt_start = date('Ymd\THis', strtotime($t['data_turno'] . ' ' . $t['orario_inizio']));
$dt_end   = date('Ymd\THis', strtotime($t['data_turno'] . ' ' . $t['orario_fine']));

$titolo = strip_tags($t['evento_titolo']);
$desc   = strip_tags($t['descrizione'] ?? '');
$luogo  = strip_tags($t['luogo'] ?? 'Unical - DiBEST');

header('Content-Type: text/calendar; charset=utf-8');
header('Content-Disposition: attachment; filename="evento_' . $t_id . '.ics"');

echo "BEGIN:VCALENDAR\r\n";
echo "VERSION:2.0\r\n";
echo "PRODID:-//DiBEST Unical//Eventi v1.0//IT\r\n";
echo "CALSCALE:GREGORIAN\r\n";
echo "METHOD:PUBLISH\r\n";
echo "BEGIN:VEVENT\r\n";
echo "UID:" . md5($t_id . $t['data_turno']) . "@eventi.unical.it\r\n";
echo "DTSTAMP:" . date('Ymd\THis\Z') . "\r\n";
echo "DTSTART:" . $dt_start . "\r\n";
echo "DTEND:" . $dt_end . "\r\n";
echo "SUMMARY:" . addcslashes($titolo, ",;") . "\r\n";
echo "DESCRIPTION:" . addcslashes($desc, ",;") . "\r\n";
echo "LOCATION:" . addcslashes($luogo, ",;") . "\r\n";
echo "END:VEVENT\r\n";
echo "END:VCALENDAR\r\n";
exit;
