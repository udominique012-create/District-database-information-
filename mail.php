<?php
/** Kohereza email (kode yo kwinjira). SMTP ya hosting yawe (config 'smtp'); idashyizweho, igerageza mail() ya PHP. */
function mail_send(string $to, string $subject, string $body): bool {
    global $C;
    $m = $C['smtp'] ?? []; $from = ($m['from'] ?? '') ?: (($m['user'] ?? '') ?: 'no-reply@localhost');
    $subj = '=?UTF-8?B?' . base64_encode($subject) . '?=';
    $head = ["From: Akarere ka Muhanga <$from>", 'MIME-Version: 1.0', 'Content-Type: text/plain; charset=UTF-8', 'Content-Transfer-Encoding: 8bit'];
    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) return false;
    if (empty($m['host'])) { $ok = @mail($to, $subj, $body, implode("\r\n", $head)); error_log('[MAIL php] ' . ($ok ? 'sent' : 'failed') . " $to"); return $ok; }
    $secure = $m['secure'] ?? 'ssl';
    $fp = @stream_socket_client(($secure === 'ssl' ? 'ssl://' : '') . $m['host'] . ':' . ($m['port'] ?? 465), $en, $es, 10);
    if (!$fp) { error_log("[MAIL] connect failed: $es"); return false; }
    stream_set_timeout($fp, 10);
    $rd = function () use ($fp) { $l = ''; while (($r = fgets($fp, 515)) !== false) { $l .= $r; if (isset($r[3]) && $r[3] === ' ') break; } return $l; };
    $cmd = function (string $c, string $ok) use ($fp, $rd) { fwrite($fp, $c . "\r\n"); return str_starts_with($rd(), $ok); };
    $rd();
    $ok = $cmd('EHLO localhost', '250');
    if ($ok && $secure === 'tls') $ok = $cmd('STARTTLS', '220') && stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT) && $cmd('EHLO localhost', '250');
    if ($ok && !empty($m['user'])) $ok = $cmd('AUTH LOGIN', '334') && $cmd(base64_encode($m['user']), '334') && $cmd(base64_encode($m['pass'] ?? ''), '235');
    $ok = $ok && $cmd("MAIL FROM:<$from>", '250') && $cmd("RCPT TO:<$to>", '25') && $cmd('DATA', '354');
    if ($ok) {
        $txt = str_replace("\n", "\r\n", preg_replace('/^\./m', '..', str_replace(["\r\n", "\r"], "\n", $body)));
        fwrite($fp, implode("\r\n", array_merge($head, ["To: <$to>", "Subject: $subj", 'Date: ' . date('r'), '', $txt, '.'])) . "\r\n");
        $ok = str_starts_with($rd(), '250');
    }
    $cmd('QUIT', '221'); fclose($fp);
    error_log('[MAIL smtp] ' . ($ok ? 'sent' : 'failed') . " $to");
    return $ok;
}
