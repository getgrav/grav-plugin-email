<?php
namespace Grav\Plugin\Email;
use Psr\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Mailer\Mailer as SymfonyMailer;
use Symfony\Component\Mime\RawMessage;

class Mailer
{
    /** @var SymfonyMailer  */
    protected $mailer;

    public function __construct(TransportInterface $transport, MessageBusInterface $bus = null, EventDispatcherInterface $dispatcher = null) {
        $this->mailer = new SymfonyMailer($transport, $bus, $dispatcher);
    }

    /**
     * @throws TransportExceptionInterface
     */
    public function send(RawMessage $message, Envelope $envelope = null): void
    {
        $this->mailer->send($message, $envelope);
    }
}