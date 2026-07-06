<?php
return [
    // Финально здесь будет почта Андрея.
    'mail_to' => 'a.lashchukhin@l-group.su',
    'mail_from' => 'site@lider-vino.ru',
    'telegram_bot_token' => '',
    'telegram_chat_id' => '',
    'debug' => false,
    'anti_spam' => [
        'min_form_seconds' => 3,
        'rate_limit_window' => 600,
        'rate_limit_max' => 5,
        'max_links' => 2,
        'require_form_token' => true,
        'blocked_terms' => ['1xbet', '1хбет', 'casino', 'казино', 'ставки', 'букмекер', 'viagra', 'crypto', 'крипто', 'forex', 'loan', 'займ', 'кредит'],
    ],
    'smtp' => [
        'enabled' => true,
        'host' => 'server282.hosting.reg.ru',
        'port' => 465,
        'secure' => 'ssl',
        'username' => 'site@lider-vino.ru',
        'password' => 'mailbox-password',
        'from_email' => 'site@lider-vino.ru',
        'from_name' => 'Сайт Лидер',
    ],
];
