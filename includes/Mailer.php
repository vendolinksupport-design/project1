<?php
/**
 * ============================================================
 * VendoLink — Production SMTP Mailer Service
 * ============================================================
 *
 * Provides a self-contained, robust, authenticated SMTP client
 * supporting SSL (port 465) and STARTTLS (port 587) without external
 * Composer dependencies.
 *
 * Key features:
 * - Full RFC-compliant SMTP handshake with STARTTLS crypto negotiation.
 * - Centralized configuration via config/config.php and config/mail.local.php.
 * - Strict credential and secret protection (no passwords or OTPs in logs).
 * - Multi-line SMTP response parsing.
 * - Test mock handler injection for deterministic automated tests.
 *
 * @package VendoLink
 * @version 1.1
 */

class Mailer
{
    /** @var string|null Last error message encountered */
    private static ?string $lastError = null;

    /** @var callable|null Test mock handler (receives array $data, returns bool) */
    private static $mockHandler = null;

    /**
     * Set a mock handler for testing email delivery without connecting to an SMTP server.
     *
     * @param callable|null $handler Function($data): bool or null to disable
     */
    public static function setMockHandler(?callable $handler): void
    {
        self::$mockHandler = $handler;
    }

    /**
     * Get the most recent mailer error message.
     */
    public static function getLastError(): ?string
    {
        return self::$lastError;
    }

    /**
     * Send an email using configured SMTP settings.
     *
     * @param string      $to        Recipient email address
     * @param string      $subject   Subject line
     * @param string      $htmlBody  HTML message content
     * @param string|null $plainText Optional plain-text fallback
     * @param string|null $from      Optional sender email override
     * @param string|null $fromName  Optional sender display name override
     * @return bool True if successfully sent, false on failure
     */
    public static function send(
        string $to,
        string $subject,
        string $htmlBody,
        ?string $plainText = null,
        ?string $from = null,
        ?string $fromName = null
    ): bool {
        self::$lastError = null;

        $to = trim($to);
        if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
            self::$lastError = 'Invalid recipient email address: ' . htmlspecialchars($to);
            self::logError(self::$lastError);
            return false;
        }

        // Test Mock Interceptor
        if (self::$mockHandler !== null) {
            $mock = self::$mockHandler;
            try {
                $result = (bool)$mock([
                    'to'        => $to,
                    'subject'   => $subject,
                    'htmlBody'  => $htmlBody,
                    'plainText' => $plainText,
                    'from'      => $from,
                    'fromName'  => $fromName,
                ]);
                if (!$result && self::$lastError === null) {
                    self::$lastError = 'Mock mailer rejected delivery.';
                }
                self::logAudit($to, $subject, $result, $result ? null : self::$lastError);
                return $result;
            } catch (Throwable $e) {
                self::$lastError = 'Mock mailer exception: ' . $e->getMessage();
                self::logError(self::$lastError);
                self::logAudit($to, $subject, false, self::$lastError);
                return false;
            }
        }

        if (!defined('SMTP_ENABLED') || !SMTP_ENABLED) {
            self::$lastError = 'SMTP delivery is disabled in application configuration.';
            self::logAudit($to, $subject, false, self::$lastError);
            return false;
        }

        // Resolve Configuration
        $rawHost  = defined('SMTP_HOST') ? SMTP_HOST : 'smtp.gmail.com';
        $port     = defined('SMTP_PORT') ? (int)SMTP_PORT : 587;
        $username = defined('SMTP_USER') ? SMTP_USER : '';
        $password = defined('SMTP_PASS') ? SMTP_PASS : '';
        $secure   = defined('SMTP_SECURE') ? strtolower(SMTP_SECURE) : 'tls';
        $from     = $from ?? (defined('SMTP_FROM_EMAIL') ? SMTP_FROM_EMAIL : 'vendolink.support@gmail.com');
        $fromName = $fromName ?? (defined('SMTP_FROM_NAME') ? SMTP_FROM_NAME : 'VendoLink Support');
        $timeout  = defined('SMTP_TIMEOUT') ? (int)SMTP_TIMEOUT : 10;

        // Normalize Host & Encryption Mode
        $isDirectSsl = false;
        if (str_starts_with($rawHost, 'ssl://')) {
            $host = substr($rawHost, 6);
            $isDirectSsl = true;
        } elseif ($secure === 'ssl' || $port === 465) {
            $host = $rawHost;
            $isDirectSsl = true;
        } else {
            $host = $rawHost;
        }

        // Pre-Flight Check: Credentials must not be empty
        if (empty($host) || empty($username) || empty($password)) {
            self::$lastError = 'SMTP configuration incomplete: Host, username, or password is not configured.';
            self::logError('[VendoLink Mailer] ' . self::$lastError . ' (Ensure SMTP_PASS is set in environment or config/mail.local.php)');
            self::logAudit($to, $subject, false, 'SMTP credentials incomplete (missing password/username)');
            return false;
        }

        // Establish Socket Connection
        $connectTarget = ($isDirectSsl ? 'ssl://' : 'tcp://') . $host;
        $context = stream_context_create([
            'ssl' => [
                'verify_peer'       => true,
                'verify_peer_name'  => true,
                'allow_self_signed' => false,
            ],
        ]);

        $socket = @stream_socket_client(
            $connectTarget . ':' . $port,
            $errno,
            $errstr,
            $timeout,
            STREAM_CLIENT_CONNECT,
            $context
        );

        if (!$socket) {
            self::$lastError = "Connection to SMTP server {$host}:{$port} failed: {$errstr} ({$errno})";
            self::logError('[VendoLink Mailer] ' . self::$lastError);
            self::logAudit($to, $subject, false, "Connection failed: {$errstr}");
            return false;
        }

        stream_set_timeout($socket, $timeout);

        try {
            // Read Service Ready Banner (220)
            $response = self::readSmtpResponse($socket);
            if ($response['code'] !== 220) {
                throw new RuntimeException("Unexpected greeting from server: {$response['raw']}");
            }

            // Initial EHLO Handshake
            $ehloHost = gethostname() ?: 'localhost';
            self::sendSmtpCommand($socket, "EHLO {$ehloHost}");
            $response = self::readSmtpResponse($socket);
            if ($response['code'] !== 250) {
                throw new RuntimeException("EHLO failed: {$response['raw']}");
            }

            // Negotiate STARTTLS if on port 587 or tls mode
            if (!$isDirectSsl && ($secure === 'tls' || $port === 587)) {
                self::sendSmtpCommand($socket, "STARTTLS");
                $response = self::readSmtpResponse($socket);
                if ($response['code'] !== 220) {
                    throw new RuntimeException("STARTTLS rejected: {$response['raw']}");
                }

                $cryptoMethod = STREAM_CRYPTO_METHOD_TLS_CLIENT
                              | STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT
                              | STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT;

                $cryptoSuccess = @stream_socket_enable_crypto($socket, true, $cryptoMethod);
                if (!$cryptoSuccess) {
                    throw new RuntimeException("Failed to establish secure TLS session via stream_socket_enable_crypto.");
                }

                // Re-send EHLO after TLS upgrade (RFC 3207 requirement)
                self::sendSmtpCommand($socket, "EHLO {$ehloHost}");
                $response = self::readSmtpResponse($socket);
                if ($response['code'] !== 250) {
                    throw new RuntimeException("Post-TLS EHLO failed: {$response['raw']}");
                }
            }

            // Authenticate with AUTH LOGIN
            self::sendSmtpCommand($socket, "AUTH LOGIN");
            $response = self::readSmtpResponse($socket);
            if ($response['code'] !== 334) {
                throw new RuntimeException("AUTH LOGIN command rejected: {$response['raw']}");
            }

            self::sendSmtpCommand($socket, base64_encode($username));
            $response = self::readSmtpResponse($socket);
            if ($response['code'] !== 334) {
                throw new RuntimeException("Username rejected by server: {$response['raw']}");
            }

            self::sendSmtpCommand($socket, base64_encode($password), true); // sanitize password from logging
            $response = self::readSmtpResponse($socket);
            if ($response['code'] !== 235) {
                throw new RuntimeException("Authentication failed (code {$response['code']}). Please verify your username and 16-character App Password.");
            }

            // Envelope Sender & Recipient
            self::sendSmtpCommand($socket, "MAIL FROM:<{$from}>");
            $response = self::readSmtpResponse($socket);
            if ($response['code'] !== 250) {
                throw new RuntimeException("MAIL FROM failed: {$response['raw']}");
            }

            self::sendSmtpCommand($socket, "RCPT TO:<{$to}>");
            $response = self::readSmtpResponse($socket);
            if ($response['code'] !== 250 && $response['code'] !== 251) {
                throw new RuntimeException("RCPT TO failed for {$to}: {$response['raw']}");
            }

            // Initiate Data Transmission
            self::sendSmtpCommand($socket, "DATA");
            $response = self::readSmtpResponse($socket);
            if ($response['code'] !== 354) {
                throw new RuntimeException("DATA command rejected: {$response['raw']}");
            }

            // Build RFC-compliant MIME payload
            $payload = self::buildMimePayload($to, $subject, $htmlBody, $plainText, $from, $fromName, $host);
            fputs($socket, $payload['smtpData']);

            $response = self::readSmtpResponse($socket);
            if ($response['code'] !== 250) {
                throw new RuntimeException("Message body rejected: {$response['raw']}");
            }

            // Graceful Quit
            self::sendSmtpCommand($socket, "QUIT");
            fclose($socket);

            self::logAudit($to, $subject, true);
            return true;

        } catch (Throwable $e) {
            self::$lastError = $e->getMessage();
            self::logError('[VendoLink Mailer Error] ' . self::$lastError);
            self::logAudit($to, $subject, false, self::$lastError);

            if (is_resource($socket)) {
                @fputs($socket, "QUIT\r\n");
                @fclose($socket);
            }
            return false;
        }
    }

    /**
     * Send an SMTP command string to the socket.
     *
     * @param resource $socket
     * @param string   $command
     * @param bool     $isSecret
     */
    private static function sendSmtpCommand($socket, string $command, bool $isSecret = false): void
    {
        fputs($socket, $command . "\r\n");
    }

    /**
     * Read and parse multi-line SMTP server response.
     *
     * @param resource $socket
     * @return array ['code' => int, 'raw' => string]
     */
    private static function readSmtpResponse($socket): array
    {
        $rawLines = [];
        $code = 0;

        while ($line = fgets($socket, 1024)) {
            $rawLines[] = trim($line);
            if (strlen($line) >= 4) {
                $code = (int)substr($line, 0, 3);
                // End of multi-line response marked by space after 3-digit code
                if (substr($line, 3, 1) === ' ') {
                    break;
                }
            } else {
                break;
            }
        }

        return [
            'code' => $code,
            'raw'  => implode('; ', $rawLines),
        ];
    }

    /**
     * Build an RFC 5322 and RFC 2046 compliant MIME payload.
     *
     * Ensures:
     * - Pure CRLF (\r\n) line endings without multi-pass corruption.
     * - Exactly one CRLF between headers, and exactly two CRLFs separating headers from body.
     * - RFC 2047 subject and display-name encoding when non-ASCII.
     * - Valid multipart/alternative structure with properly formatted boundaries.
     * - RFC 5321 §4.5.2 dot-stuffing transparency.
     *
     * @param string      $to Recipient email address
     * @param string      $subject Subject line
     * @param string      $htmlBody HTML message content
     * @param string|null $plainText Optional plain-text fallback
     * @param string      $from Sender email
     * @param string      $fromName Sender display name
     * @param string      $host Server hostname (for Message-ID generation)
     * @return array ['headers' => string, 'body' => string, 'rawMessage' => string, 'smtpData' => string, 'boundary' => string|null]
     */
    public static function buildMimePayload(
        string $to,
        string $subject,
        string $htmlBody,
        ?string $plainText = null,
        string $from = 'vendolink.support@gmail.com',
        string $fromName = 'VendoLink Support',
        string $host = 'localhost'
    ): array {
        // Prevent header injection by removing line breaks from header values
        $cleanTo       = str_replace(["\r", "\n"], '', trim($to));
        $cleanSubject  = str_replace(["\r", "\n"], '', trim($subject));
        $cleanFrom     = str_replace(["\r", "\n"], '', trim($from));
        $cleanFromName = str_replace(["\r", "\n"], '', trim($fromName));

        // RFC 2047 Subject encoding
        if (preg_match('/^[\x20-\x7E]+$/', $cleanSubject)) {
            $encodedSubject = $cleanSubject;
        } elseif (function_exists('mb_encode_mimeheader')) {
            $encodedSubject = mb_encode_mimeheader($cleanSubject, 'UTF-8', 'B', "\r\n");
        } else {
            $encodedSubject = '=?UTF-8?B?' . base64_encode($cleanSubject) . '?=';
        }

        // RFC 2047 From display name encoding
        if (preg_match('/^[\x20-\x7E]+$/', $cleanFromName)) {
            $encodedFromName = '"' . addcslashes($cleanFromName, '"\\') . '"';
        } elseif (function_exists('mb_encode_mimeheader')) {
            $encodedFromName = mb_encode_mimeheader($cleanFromName, 'UTF-8', 'B', "\r\n");
        } else {
            $encodedFromName = '=?UTF-8?B?' . base64_encode($cleanFromName) . '?=';
        }

        $hostDomain = preg_replace('/[^a-zA-Z0-9\.\-]/', '', $host) ?: 'vendolink.local';
        $messageId  = '<' . bin2hex(random_bytes(16)) . '@' . $hostDomain . '>';
        $dateHeader = date('r');

        $hasPlainText = ($plainText !== null && strlen(trim($plainText)) > 0);
        $boundary     = $hasPlainText ? ('=_vl_' . bin2hex(random_bytes(16))) : null;

        // Clean newline normalization: convert any \r\n or \r to \n first, then convert \n to standard \r\n
        $normalizedHtml = str_replace("\n", "\r\n", str_replace(["\r\n", "\r"], "\n", $htmlBody));
        $normalizedText = $hasPlainText ? str_replace("\n", "\r\n", str_replace(["\r\n", "\r"], "\n", $plainText)) : '';

        // Assemble RFC 5322 Headers
        $headerLines   = [];
        $headerLines[] = "From: {$encodedFromName} <{$cleanFrom}>";
        $headerLines[] = "To: <{$cleanTo}>";
        $headerLines[] = "Subject: {$encodedSubject}";
        $headerLines[] = "Date: {$dateHeader}";
        $headerLines[] = "Message-ID: {$messageId}";
        $headerLines[] = "X-Mailer: VendoLink SMTP Engine 1.2";
        $headerLines[] = "MIME-Version: 1.0";

        if ($hasPlainText) {
            $headerLines[] = "Content-Type: multipart/alternative; boundary=\"{$boundary}\"";
        } else {
            $headerLines[] = "Content-Type: text/html; charset=UTF-8";
            $headerLines[] = "Content-Transfer-Encoding: 8bit";
        }

        // Headers are joined with single CRLF; header block terminates with CRLF CRLF
        $headersString = implode("\r\n", $headerLines);
        $headerBlock   = $headersString . "\r\n\r\n";

        // Assemble Body
        if ($hasPlainText) {
            $body  = "--{$boundary}\r\n";
            $body .= "Content-Type: text/plain; charset=UTF-8\r\n";
            $body .= "Content-Transfer-Encoding: 8bit\r\n\r\n";
            $body .= rtrim($normalizedText, "\r\n") . "\r\n";
            $body .= "--{$boundary}\r\n";
            $body .= "Content-Type: text/html; charset=UTF-8\r\n";
            $body .= "Content-Transfer-Encoding: 8bit\r\n\r\n";
            $body .= rtrim($normalizedHtml, "\r\n") . "\r\n";
            $body .= "--{$boundary}--\r\n";
        } else {
            $body = rtrim($normalizedHtml, "\r\n") . "\r\n";
        }

        $rawMessage = $headerBlock . $body;

        // RFC 5321 §4.5.2: Dot-stuffing transparency (prepend period to lines starting with a dot)
        $stuffedMessage = preg_replace('/^\./m', '..', $rawMessage);

        // Terminate SMTP DATA block with CRLF . CRLF
        $smtpData = rtrim($stuffedMessage, "\r\n") . "\r\n.\r\n";

        return [
            'headers'    => $headersString,
            'body'       => $body,
            'rawMessage' => $rawMessage,
            'smtpData'   => $smtpData,
            'boundary'   => $boundary,
        ];
    }

    /**
     * Write an error log without leaking secrets.
     */
    private static function logError(string $message): void
    {
        error_log($message);
    }

    /**
     * Write an audit dispatch entry to logs/mail.log without exposing OTPs or secrets.
     */
    private static function logAudit(string $to, string $subject, bool $success, ?string $reason = null): void
    {
        $logDir = defined('LOGS_PATH') ? LOGS_PATH : dirname(__DIR__) . '/logs';
        if (!is_dir($logDir)) {
            @mkdir($logDir, 0755, true);
        }

        $logFile = $logDir . '/mail.log';
        $statusStr = $success ? 'SENT' : 'FAILED';
        $reasonStr = ($reason !== null && !$success) ? " | Reason: {$reason}" : '';

        // Strip actual 6-digit OTP codes or token parameters from logged subject
        $cleanSubject = preg_replace('/[0-9]{6}/', '******', $subject);

        $line = sprintf(
            "[%s] [DISPATCH %s] To: %s | Subject: %s%s\n",
            date('Y-m-d H:i:s'),
            $statusStr,
            function_exists('mask_email') ? mask_email($to) : $to,
            $cleanSubject,
            $reasonStr
        );

        @file_put_contents($logFile, $line, FILE_APPEND | LOCK_EX);
    }
}
