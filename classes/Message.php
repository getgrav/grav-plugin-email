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

    public function subject($subject): self
    {
        $this->email->subject($subject);
        return $this;
    }

    public function to($to): self
    {
        $this->email->to($to);
        return $this;
    }

    public function from($from): self
    {
        $this->email->from($from);
        return $this;
    }

    public function cc($cc): self
    {
        $this->email->cc($cc);
        return $this;
    }

    public function bcc($bcc): self
    {
        $this->email->bcc($bcc);
        return $this;
    }

    public function replyTo($reply_to): self
    {
        $this->email->replyTo($reply_to);
        return $this;
    }

    public function text($text): self
    {
        $this->email->text($text);
        return $this;
    }

    public function html($html): self
    {
        $this->email->html($html);
        return $this;
    }

    public function attachFromPath($path): self
    {
        $this->email->attachFromPath($path);
        return $this;
    }

    public function embedFromPath($path): self
    {
        $this->email->embedFromPath($path);
        return $this;
    }

    public function reply_to($reply_to): self
    {
        $this->replyTo($reply_to);
        return $this;
    }

    public function setFrom($from): self
    {
        $this->from($from);
        return $this;
    }

    public function setTo($to): self
    {
        $this->to($to);
        return $this;
    }

    public function getEmail(): SymfonyEmail
    {
        return $this->email;
    }
}