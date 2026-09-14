<?php
/**
 * 邮件发送服务
 *
 * 无 Composer 依赖，使用原生 socket 实现 SMTP 通信：
 * - log   驱动：邮件落盘到 storage/mail/，供本地开发查看（默认）
 * - smtp  驱动：支持 AUTH LOGIN，加密方式支持 ssl（465）/ starttls（587）/ none
 *
 * 邮件正文发送 multipart/alternative（纯文本 + HTML），内容统一 base64 编码，
 * 可安全承载中文等非 ASCII 字符。
 */

declare(strict_types=1);

namespace App\Services;

use RuntimeException;

class Mailer
{
    /**
     * 发送邮件
     *
     * @return bool 是否发送成功（log 驱动视为始终成功）
     */
    public static function send(string $to, string $subject, string $htmlBody, string $textBody = ''): bool
    {
        $to = trim($to);
        if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
            return false;
        }

        $textBody = $textBody !== '' ? $textBody : self::htmlToText($htmlBody);
        $from = (array) config('mail.from', []);
        $fromEmail = (string) ($from['address'] ?? 'noreply@dramatool.local');
        $fromName = (string) ($from['name'] ?? 'Dramatool');

        if ((string) config('mail.driver', 'log') === 'smtp') {
            return self::sendSmtp($to, $subject, $htmlBody, $textBody, $fromEmail, $fromName);
        }

        return self::writeLog($to, $subject, $htmlBody, $textBody, $fromEmail, $fromName);
    }

    /**
     * log 驱动：把完整邮件写入 storage/mail 日志文件
     */
    private static function writeLog(
        string $to,
        string $subject,
        string $htmlBody,
        string $textBody,
        string $fromEmail,
        string $fromName
    ): bool {
        $dir = rtrim((string) config('paths.storage'), '/\\') . '/mail';
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            return false;
        }

        $record = sprintf(
            "==== %s ====\nFrom: %s <%s>\nTo: %s\nSubject: %s\n\n-- text --\n%s\n\n-- html --\n%s\n\n",
            date('Y-m-d H:i:s'),
            $fromName,
            $fromEmail,
            $to,
            $subject,
            $textBody,
            $htmlBody
        );

        return file_put_contents($dir . '/emails-' . date('Ymd') . '.log', $record, FILE_APPEND | LOCK_EX) !== false;
    }

    /**
     * smtp 驱动：原生 socket 发信
     */
    private static function sendSmtp(
        string $to,
        string $subject,
        string $htmlBody,
        string $textBody,
        string $fromEmail,
        string $fromName
    ): bool {
        $cfg = (array) config('mail.smtp', []);
        $host = (string) ($cfg['host'] ?? '');
        $port = (int) ($cfg['port'] ?? 465);
        $encryption = (string) ($cfg['encryption'] ?? 'ssl');
        $timeout = max(3, (int) ($cfg['timeout'] ?? 10));

        if ($host === '') {
            throw new RuntimeException('SMTP 主机未配置');
        }

        $remote = ($encryption === 'ssl' ? 'ssl://' : '') . $host;
        $errno = 0;
        $errstr = '';
        $socket = @stream_socket_client(
            $remote . ':' . $port,
            $errno,
            $errstr,
            $timeout,
            STREAM_CLIENT_CONNECT
        );

        if (!is_resource($socket)) {
            throw new RuntimeException('SMTP 连接失败：' . $errstr . ' (' . $errno . ')');
        }

        stream_set_timeout($socket, $timeout);

        try {
            self::readReply($socket, 220);
            $ehlo = self::clientHost();
            self::command($socket, 'EHLO ' . $ehlo, 250);

            // STARTTLS：明文连接后协商 TLS，再 EHLO 一次
            if ($encryption === 'starttls') {
                self::command($socket, 'STARTTLS', 220);
                $crypto = STREAM_CRYPTO_METHOD_TLS_CLIENT
                    | STREAM_CRYPTO_METHOD_TLSv1_1_CLIENT
                    | STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT
                    | STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT;
                if (!stream_socket_enable_crypto($socket, true, [
                    'crypto_method' => $crypto,
                    'SNI_server_name' => $host,
                    'peer_name'      => $host,
                ])) {
                    throw new RuntimeException('STARTTLS 加密协商失败');
                }
                self::command($socket, 'EHLO ' . $ehlo, 250);
            }

            // AUTH LOGIN
            $username = (string) ($cfg['username'] ?? '');
            $password = (string) ($cfg['password'] ?? '');
            if ($username !== '') {
                self::command($socket, 'AUTH LOGIN', 334);
                self::command($socket, base64_encode($username), 334);
                self::command($socket, base64_encode($password), 235);
            }

            self::command($socket, 'MAIL FROM:<' . $fromEmail . '>', 250);
            self::command($socket, 'RCPT TO:<' . $to . '>', [250, 251]);
            self::command($socket, 'DATA', 354);

            $headers = self::buildHeaders($to, $subject, $fromEmail, $fromName);
            $body = self::buildMultipart($htmlBody, $textBody);

            // DATA 内容中以点开头的行需要双点转义
            $payload = preg_replace('/^\./m', '..', $headers . "\r\n" . $body) ?? '';
            self::writeRaw($socket, $payload . "\r\n.");
            self::readReply($socket, 250);

            self::command($socket, 'QUIT', 221);
        } finally {
            fclose($socket);
        }

        return true;
    }

    /**
     * 构造邮件头
     */
    private static function buildHeaders(
        string $to,
        string $subject,
        string $fromEmail,
        string $fromName
    ): string {
        $boundary = 'b=_' . bin2hex(random_bytes(12));
        $headers = [
            'Date' => date('r'),
            'From' => self::formatAddress($fromEmail, $fromName),
            'To' => '<' . $to . '>',
            'Subject' => self::encodeHeader($subject),
            'Message-ID' => '<' . bin2hex(random_bytes(16)) . '@' . self::clientHost() . '>',
            'MIME-Version' => '1.0',
            'Content-Type' => 'multipart/alternative; boundary="' . $boundary . '"; charset=UTF-8',
        ];

        // boundary 存到调用方可获取的位置：通过静态属性传递
        self::$boundary = $boundary;

        $out = '';
        foreach ($headers as $name => $value) {
            $out .= $name . ': ' . $value . "\r\n";
        }
        return $out;
    }

    /** @var string 当前邮件的 multipart 分隔符 */
    private static string $boundary = '';

    /**
     * 构造 multipart/alternative 正文
     */
    private static function buildMultipart(string $htmlBody, string $textBody): string
    {
        $boundary = self::$boundary;
        $out = '';

        $out .= '--' . $boundary . "\r\n";
        $out .= "Content-Type: text/plain; charset=UTF-8\r\n";
        $out .= "Content-Transfer-Encoding: base64\r\n\r\n";
        $out .= chunk_split(base64_encode($textBody)) . "\r\n";

        $out .= '--' . $boundary . "\r\n";
        $out .= "Content-Type: text/html; charset=UTF-8\r\n";
        $out .= "Content-Transfer-Encoding: base64\r\n\r\n";
        $out .= chunk_split(base64_encode($htmlBody)) . "\r\n";

        $out .= '--' . $boundary . "--\r\n";
        return $out;
    }

    /**
     * 格式化发件人地址（显示名做 RFC 2047 编码）
     */
    private static function formatAddress(string $email, string $name): string
    {
        if ($name === '') {
            return '<' . $email . '>';
        }
        return self::encodeHeader($name) . ' <' . $email . '>';
    }

    /**
     * RFC 2047 邮件头编码（中文主题/名称）
     */
    private static function encodeHeader(string $value): string
    {
        if (preg_match('/[\x80-\xff]/', $value) !== 1) {
            return $value;
        }
        return '=?UTF-8?B?' . base64_encode($value) . '?=';
    }

    /**
     * SMTP 命令并校验响应码
     *
     * @param int|int[] $expect 可接受的响应码
     */
    private static function command($socket, string $command, $expect): string
    {
        self::writeRaw($socket, $command);
        return self::readReply($socket, $expect);
    }

    /**
     * 读取 SMTP 响应并校验状态码（支持多行续接）
     *
     * @param int|int[] $expect
     */
    private static function readReply($socket, $expect): string
    {
        $expect = (array) $expect;
        $reply = '';
        while (true) {
            $line = fgets($socket, 1024);
            if ($line === false) {
                throw new RuntimeException('SMTP 服务器无响应（超时或连接已断开）');
            }
            $reply .= $line;
            // 第 4 位为空格表示响应结束，如 "250-..." 为续行
            if (isset($line[3]) && $line[3] === ' ') {
                break;
            }
        }

        $code = (int) substr($reply, 0, 3);
        if (!in_array($code, $expect, true)) {
            throw new RuntimeException('SMTP 错误：' . trim($reply));
        }
        return $reply;
    }

    /**
     * 向 socket 写入（自动补 CRLF）
     */
    private static function writeRaw($socket, string $data): void
    {
        $line = $data . "\r\n";
        for ($written = 0, $len = strlen($line); $written < $len;) {
            $n = fwrite($socket, substr($line, $written));
            if ($n === false || $n === 0) {
                throw new RuntimeException('SMTP 写入失败');
            }
            $written += $n;
        }
    }

    /**
     * SMTP 握手用的本机主机名
     */
    private static function clientHost(): string
    {
        $host = (string) ($_SERVER['SERVER_NAME'] ?? 'localhost');
        return preg_replace('/[^a-zA-Z0-9.\-]/', '', $host) ?: 'localhost';
    }

    /**
     * HTML 转纯文本（log 邮件与兜底文本用）
     */
    private static function htmlToText(string $html): string
    {
        $text = preg_replace('/<br\s*\/?>/i', "\n", $html) ?? $html;
        $text = preg_replace('/<\/p>/i', "\n\n", $text) ?? $text;
        $text = strip_tags($text);
        return html_entity_decode(trim($text), ENT_QUOTES, 'UTF-8');
    }
}
