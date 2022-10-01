<?php
namespace Grav\Plugin\Email;

use Symfony\Component\Mime\Email as SymfonyEmail;

class Message
{
    /** @var SymfonyEmail  */
    protected $email;

    public function __construct() {
        $this->email = new SymfonyEmail();
    }

    public function subject($subject): Message
    {
        $this->email->subject($subject);
        return $this;
    }

    public function to($to): Message
    {
        $this->email->to($to);
        return $this;
    }

    public function from($from): Message
    {
        $this->email->from($from);
        return $this;
    }

    public function cc($cc): Message
    {
        $this->email->cc($cc);
        return $this;
    }

    public function bcc($bcc): Message
    {
        $this->email->bcc($bcc);
        return $this;
    }

    public function replyTo($reply_to): Message
    {
        $this->email->replyTo($reply_to);
        return $this;
    }

    public function text($text): Message
    {
        $this->email->text($text);
        return $this;
    }

    public function html($html): Message
    {
        $this->email->html($html);
        return $this;
    }

    public function attachFromPath($path): Message
    {
        $this->email->attachFromPath($path);
        return $this;
    }

    public function embedFromPath($path): Message
    {
        $this->email->embedFromPath($path);
        return $this;
    }

    public function reply_to($reply_to): Message
    {
        $this->replyTo($reply_to);
        return $this;
    }

    public function setFrom($from): Message
    {
        $this->from($from);
        return $this;
    }

    public function setTo($to): Message
    {
        $this->to($to);
        return $this;
    }

    public function getEmail(): SymfonyEmail
    {
        return $this->email;
    }
}