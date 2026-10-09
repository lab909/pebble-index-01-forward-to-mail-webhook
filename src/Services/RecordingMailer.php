<?php
declare(strict_types=1);

namespace Pebble\Services;

use Pebble\Recording\Recording;
use Symfony\Component\Mailer\MailerInterface;
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
    ) {
        // Validate addresses during configuration, before any files are saved.
        $this->email = (new Email())->from($from)->to($to);
    }

    public function send(Recording $recording): void
    {
        $info = json_decode($recording->text, true, flags: JSON_THROW_ON_ERROR);
        $transcription = $info['postFields']['transcription'] ?? '';

        $email = clone $this->email;
        $email->subject($this->subjectPrefix . ' ' . $recording->recordedAt->format('Y-m-d H:i:s'))
            ->text(is_string($transcription) ? $transcription : '')
            ->addPart(new DataPart($recording->text, 'info.txt', 'text/plain'));
        if ($recording->audioPath !== null) {
            $email->addPart(new DataPart(new File($recording->audioPath), 'audio.m4a', 'audio/mp4'));
        }
        $this->mailer->send($email);
    }
}
