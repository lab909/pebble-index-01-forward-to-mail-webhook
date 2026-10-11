<?php
declare(strict_types=1);

// CLI-only: never expose diagnostic email sending as a public endpoint.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$arguments = array_slice($argv, 1);
if ($arguments === ['--help']) {
    echo "Usage: php bin/test-alert-email.php --check|--send\n"
        . "--check validates enabled alert configuration without network or state writes.\n"
        . "--send explicitly sends one diagnostic email, bypassing webhook debug/cooldown gates.\n";
    exit(0);
}
if ($arguments !== ['--check'] && $arguments !== ['--send']) {
    fwrite(STDERR, "Choose --check or --send. Use --help for details.\n");
    exit(2);
}
ini_set('display_errors', '0');
$transport = null;
$code = 0;
try {
    require dirname(__DIR__) . '/vendor/autoload.php';
    $values = (new Pebble\Bootstrap(dirname(__DIR__)))->loadEnvironment();
    $environment = new Pebble\Config\Environment(dirname(__DIR__), $values);
    if (!$environment->boolean('FAILURE_EMAIL_ENABLED', false)) {
        throw new Pebble\Config\ConfigurationException('Enable FAILURE_EMAIL_ENABLED before testing alert delivery.');
    }
    $config = Pebble\Config\AlertConfig::fromEnvironment($environment);
    $transport = Pebble\Notification\AlertTransportFactory::create($config);
    if ($arguments === ['--check']) {
        echo "Alert configuration is valid. No email sent; connectivity and inbox delivery remain untested.\n";
    } else {
        $requestId = bin2hex(random_bytes(16));
        $redactor = new Pebble\Logging\Redactor($values, $requestId);
        $truncated = false;
        $timezone = new DateTimeZone($environment->string('APP_TIMEZONE', 'UTC'));
        $message = (new Symfony\Component\Mime\Email())->from($config->from)->to(...$config->recipients)
            ->subject($redactor->text($config->subjectPrefix, $truncated) . ' diagnostic')
            ->text("Explicit alert transport diagnostic. No note or attachments are included.\n"
                . 'requestId: ' . $requestId . "\n"
                . 'timestamp: ' . (new DateTimeImmutable('now', $timezone))->format('c') . "\n");
        $transport->send($message);
        echo "Diagnostic email accepted by SMTP. Confirm receipt in the configured inbox.\n";
    }
} catch (Throwable) {
    // Transport exceptions can contain credentials and host details. Keep CLI
    // failure output generic, including before dependencies/redaction are available.
    fwrite(STDERR, "Alert diagnostic failed. Check enabled settings, TLS, credentials, and outbound SMTP access.\n");
    $code = 1;
} finally {
    try { $transport?->stop(); } catch (Throwable) {}
}
exit($code);
