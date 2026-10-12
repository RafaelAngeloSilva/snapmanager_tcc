<?php

namespace App\Mail\Transport;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\MessageConverter;
use Throwable;

/**
 * Envio de e-mail pela API HTTPS da Brevo (POST /v3/smtp/email).
 *
 * Por que não SMTP: a Railway bloqueia SMTP de saída nos planos Trial e
 * Hobby — só HTTPS passa. Implementado com o cliente HTTP do Laravel (sem
 * pacote extra). Ativado com MAIL_MAILER=brevo e BREVO_API_KEY.
 *
 * Erros: lança TransportException com mensagem SEM a chave da API nem o
 * conteúdo do e-mail (só status HTTP e código de erro da Brevo).
 */
class BrevoTransport extends AbstractTransport
{
    public const ENDPOINT = 'https://api.brevo.com/v3/smtp/email';

    public function __construct(
        #[\SensitiveParameter] private readonly ?string $apiKey,
        private readonly int $timeout = 10,
    ) {
        parent::__construct();
    }

    protected function doSend(SentMessage $message): void
    {
        if (blank($this->apiKey)) {
            throw new TransportException('Brevo: BREVO_API_KEY não configurada.');
        }

        $email = MessageConverter::toEmail($message->getOriginalMessage());

        if ($email->getTo() === []) {
            throw new TransportException('Brevo: a mensagem precisa de ao menos um destinatário em "Para".');
        }
        $envelope = $message->getEnvelope();

        $recipients = fn (array $addresses) => array_values(array_map(
            fn (Address $address) => array_filter(['email' => $address->getAddress(), 'name' => $address->getName() ?: null]),
            $addresses,
        ));

        $sender = $envelope->getSender();
        $from = $email->getFrom()[0] ?? $sender;

        $payload = array_filter([
            'sender' => array_filter(['email' => $from->getAddress(), 'name' => $from->getName() ?: null]),
            // Só o To: explícito. O envelope mistura to/cc/bcc — usá-lo aqui
            // exporia os destinatários em cópia oculta.
            'to' => $recipients($email->getTo()),
            'cc' => $recipients($email->getCc()),
            'bcc' => $recipients($email->getBcc()),
            'replyTo' => ($reply = $email->getReplyTo()[0] ?? null) ? array_filter(['email' => $reply->getAddress(), 'name' => $reply->getName() ?: null]) : null,
            'subject' => $email->getSubject(),
            'htmlContent' => $email->getHtmlBody(),
            'textContent' => $email->getTextBody(),
            'attachment' => array_values(array_map(fn ($part) => [
                'name' => $part->getFilename() ?? 'anexo',
                'content' => base64_encode($part->getBody()),
            ], $email->getAttachments())),
        ], fn ($value) => $value !== null && $value !== []);

        try {
            $response = Http::withHeaders(['api-key' => $this->apiKey])
                ->acceptJson()
                ->asJson()
                ->timeout($this->timeout)
                ->post(self::ENDPOINT, $payload);
        } catch (Throwable $e) {
            Log::warning('Brevo: falha de conexão ao enviar e-mail.', ['exception' => $e::class]);

            throw new TransportException('Brevo: não foi possível conectar à API de e-mail.', 0, $e);
        }

        if ($response->failed()) {
            $code = (string) ($response->json('code') ?? 'desconhecido');
            Log::warning('Brevo: e-mail recusado pela API.', ['status' => $response->status(), 'code' => $code]);

            throw new TransportException("Brevo: envio recusado (HTTP {$response->status()}, {$code}).");
        }

        if ($messageId = $response->json('messageId')) {
            $message->setMessageId(trim((string) $messageId, '<>'));
        }
    }

    public function __toString(): string
    {
        return 'brevo';
    }
}
