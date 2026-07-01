<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

const LEAD_RECIPIENT = 'mmocaluk@gmail.com';
const MAIL_FROM = 'no-reply@lider-vino.ru';

function respond(bool $ok, string $message, int $status = 200): never
{
    http_response_code($status);
    echo json_encode([
        'ok' => $ok,
        'message' => $message,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

function clean_text(string $value): string
{
    $value = str_replace(["\r", "\n"], ' ', $value);
    return trim(preg_replace('/\s+/u', ' ', $value) ?? $value);
}

function clean_body(string $value): string
{
    $value = str_replace("\r", '', $value);
    return trim($value);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond(false, 'Метод не поддерживается.', 405);
}

if (clean_text((string)($_POST['company'] ?? '')) !== '') {
    respond(true, 'Заявка отправлена.');
}

$name = clean_text((string)($_POST['name'] ?? ''));
$phone = clean_text((string)($_POST['phone'] ?? ''));
$email = clean_text((string)($_POST['email'] ?? ''));
$message = clean_body((string)($_POST['message'] ?? ''));
$source = clean_text((string)($_POST['source'] ?? 'leader-vino-site'));
$agree = clean_text((string)($_POST['agree'] ?? ''));

if ($name === '' || $phone === '' || $message === '' || $agree !== 'yes') {
    respond(false, 'Заполните обязательные поля.', 422);
}

if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    respond(false, 'Проверьте e-mail.', 422);
}

$lines = [
    'Новая заявка с сайта lider-vino.ru',
    '',
    'Имя: ' . $name,
    'Телефон: ' . $phone,
    'E-mail: ' . ($email !== '' ? $email : 'не указан'),
    'Источник: ' . $source,
    '',
    'Что нужно привезти:',
    $message,
    '',
    'Дата: ' . date('d.m.Y H:i:s'),
    'IP: ' . clean_text((string)($_SERVER['REMOTE_ADDR'] ?? 'не определён')),
];

$subject = '=?UTF-8?B?' . base64_encode('Новая заявка с сайта Лидер') . '?=';
$fromName = '=?UTF-8?B?' . base64_encode('Сайт Лидер') . '?=';
$headers = [
    'MIME-Version: 1.0',
    'Content-Type: text/plain; charset=UTF-8',
    'From: ' . $fromName . ' <' . MAIL_FROM . '>',
];

if ($email !== '') {
    $headers[] = 'Reply-To: ' . $email;
}

$sent = mail(
    LEAD_RECIPIENT,
    $subject,
    implode("\n", $lines),
    implode("\r\n", $headers)
);

if (!$sent) {
    respond(false, 'Сервер не смог отправить письмо.', 500);
}

respond(true, 'Заявка отправлена.');
