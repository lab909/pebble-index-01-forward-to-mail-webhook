<?php
declare(strict_types=1);

namespace Pebble\Config;

use DateTimeZone;

/** Aggregate configuration; legacy properties remain during the phased migration. */
final readonly class WebhookConfig
{
    public string $webhookToken;
    public DateTimeZone $timezone;
    public bool $debugMode;
    public bool $storeToFolder;
    public ?string $storageDirectory;
    public bool $forwardToMail;
    public ?string $smtpDsn;
    public ?string $mailFrom;
    public ?string $mailTo;
    public ?string $subjectPrefix;
    public bool $saveToTodoist;
    public ?string $todoistApiToken;
    public ?string $todoistParentProject;

    public function __construct(
        public CoreConfig $core,
        public LoggingConfig $logging,
        public ?StorageConfig $storage,
        public ?NoteEmailConfig $email,
        public ?TodoistConfig $todoist,
    ) {
        $this->webhookToken = $core->webhookToken;
        $this->timezone = $core->timezone;
        $this->debugMode = $core->debugMode;
        $this->storeToFolder = $storage !== null;
        $this->storageDirectory = $storage?->directory;
        $this->forwardToMail = $email !== null;
        $this->smtpDsn = $email?->smtp->dsn;
        $this->mailFrom = $email?->from;
        $this->mailTo = $email?->to;
        $this->subjectPrefix = $email?->subjectPrefix;
        $this->saveToTodoist = $todoist !== null;
        $this->todoistApiToken = $todoist?->apiToken;
        $this->todoistParentProject = $todoist?->parentProjectId;
    }

    public static function debugModeFromEnvironment(array $values): bool
    {
        return (new Environment('', $values))->boolean('DEBUG_MODE', false);
    }

    public static function fromEnvironment(string $projectDirectory, #[\SensitiveParameter] array $values): self
    {
        $environment = new Environment($projectDirectory, $values);
        $store = $environment->boolean('STORE_TO_FOLDER', true);
        $mail = $environment->boolean('FORWARD_TO_MAIL', true);
        $todoist = $environment->boolean('SAVE_TO_TODOIST', false);
        $core = CoreConfig::fromEnvironment($environment);
        return new self($core, LoggingConfig::fromEnvironment($environment),
            $store ? StorageConfig::fromEnvironment($environment) : null,
            $mail ? NoteEmailConfig::fromEnvironment($environment) : null,
            $todoist ? TodoistConfig::fromEnvironment($environment) : null);
    }
}
