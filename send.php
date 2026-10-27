<?php
// Приём заявок с квиза и отправка на почту.
// Пишется под старый PHP хостинга (5.2+): без [] , ?? и замыканий.

$TO = 'danyakorotkov_1@mail.ru';
$FROM = 'noreply@afton.ru';
$MAX_FILE = 20 * 1024 * 1024;

header('Content-Type: application/json; charset=utf-8');

function done($ok, $msg) {
    if (!$ok) header('HTTP/1.1 400 Bad Request');
    echo json_encode(array('ok' => $ok, 'msg' => $msg));
    exit;
}

function clean($s) {
    return trim(str_replace(array("\r", "\n"), ' ', (string)$s));
}

function enc($s) {
    return '=?UTF-8?B?' . base64_encode($s) . '?=';
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') done(false, 'POST only');
if (!empty($_POST['website'])) done(true, 'ok'); // ловушка для спам-ботов

// на старом PHP могут быть включены magic quotes
function post($k) {
    $v = isset($_POST[$k]) ? (string)$_POST[$k] : '';
    if (function_exists('get_magic_quotes_gpc') && @get_magic_quotes_gpc()) $v = stripslashes($v);
    return $v;
}

$rows = json_decode(post('rows'), true);
if (!is_array($rows) || !count($rows)) done(false, 'empty');

$subject = clean(post('subject'));
if ($subject === '') $subject = 'Заявка с сайта';

// тело письма: таблица «поле — значение»
$html = '<table cellpadding="6" cellspacing="0" border="1" style="border-collapse:collapse;font:14px Arial,sans-serif">';
$replyTo = '';
foreach ($rows as $row) {
    if (!is_array($row) || count($row) < 2) continue;
    $k = (string)$row[0];
    $v = (string)$row[1];
    if ($v === '') continue;
    if (!$replyTo && filter_var(trim($v), FILTER_VALIDATE_EMAIL)) $replyTo = trim($v);
    $html .= '<tr><td style="background:#f5f5f5"><b>' . htmlspecialchars($k, ENT_QUOTES, 'UTF-8') . '</b></td><td>'
           . nl2br(htmlspecialchars($v, ENT_QUOTES, 'UTF-8')) . '</td></tr>';
}
$html .= '</table><p style="font:12px Arial,sans-serif;color:#888">Отправлено с ' . htmlspecialchars($_SERVER['HTTP_HOST'], ENT_QUOTES, 'UTF-8') . ', ' . date('d.m.Y H:i') . '</p>';

$boundary = 'b' . md5(uniqid(mt_rand(), true));
$headers = 'From: ' . enc('Сайт АФТОН') . ' <' . $FROM . ">\r\n";
if ($replyTo) $headers .= 'Reply-To: ' . $replyTo . "\r\n";
$headers .= "MIME-Version: 1.0\r\n";
$headers .= 'Content-Type: multipart/mixed; boundary="' . $boundary . "\"\r\n";

$body = '--' . $boundary . "\r\n"
      . "Content-Type: text/html; charset=UTF-8\r\n"
      . "Content-Transfer-Encoding: base64\r\n\r\n"
      . chunk_split(base64_encode($html)) . "\r\n";

// файл проекта, если приложили
if (isset($_FILES['projectFile']) && $_FILES['projectFile']['error'] === UPLOAD_ERR_OK) {
    $f = $_FILES['projectFile'];
    if ($f['size'] > $MAX_FILE) done(false, 'file too big');
    $name = clean(basename($f['name']));
    $body .= '--' . $boundary . "\r\n"
           . 'Content-Type: application/octet-stream; name="' . enc($name) . "\"\r\n"
           . "Content-Transfer-Encoding: base64\r\n"
           . 'Content-Disposition: attachment; filename="' . enc($name) . "\"\r\n\r\n"
           . chunk_split(base64_encode(file_get_contents($f['tmp_name']))) . "\r\n";
}
$body .= '--' . $boundary . "--\r\n";

if (mail($TO, enc($subject), $body, $headers, '-f' . $FROM)) done(true, 'ok');
done(false, 'mail failed');
