<?php
declare(strict_types=1);

namespace Pebble\Services;

use Pebble\Note\Note;
use Symfony\Component\Mailer\MailerInterface;
use Psr\Log\LoggerInterface;
use Throwable;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\Part\DataPart;
use Symfony\Component\Mime\Part\File;

final readonly class RecordingMailer
{
    private Email $email;

    public function __construct(
        private MailerInterface $mailer,
        string $from,
        string $to,
        private string $subjectPrefix,
        private ?LoggerInterface $logger = null,
    ) {
        // Validate addresses during configuration, before any files are saved.
        $this->email = (new Email())->from($from)->to($to);
    }

    public function send(Note $note): void
    {
        $email = clone $this->email;
        $email->subject($this->subjectPrefix . ' ' . $note->recordedAt->format('Y-m-d H:i:s'))
            ->text($note->transcription)
            ->addPart(new DataPart($note->infoText, 'info.txt', 'text/plain'));
        if ($note->audio !== null) {
            $email->addPart(new DataPart(new File($note->audio->path), 'audio.m4a', 'audio/mp4'));
        }
        try {
            $this->mailer->send($email);
        } catch (Throwable $exception) {
            SafeLog::write($this->logger, 'error', 'Note email sending failed.');
            throw $exception;
        }
        SafeLog::write($this->logger, 'info', 'Note email accepted by SMTP server.');
    }
}
