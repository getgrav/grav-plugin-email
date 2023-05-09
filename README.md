# Grav Email Plugin

The **email plugin** for [Grav](http://github.com/getgrav/grav) adds the ability to send email utilizing the `symfony/mailer` package. This is particularly useful for the **admin** and **login** plugins.

> IMPORTANT: **Version 4.0** replaced the old deprecated **SwiftMailer** library with **Symfony/Mailer** package.  This is a modern and well supported library that also has the capability to support 3rd party transport engines such as `SendGrid`, `MailJet`, `MailGun`, `MailChimp`, etc. This library should be backwards compatible with existing configurations, but please create an issue if you run into any problems.

# Installation

The email plugin is easy to install with GPM.

```
$ bin/gpm install email
```

# Configuration

The plugin uses `sendmail` binary as the default mail engine.

```
enabled: true
from:
to:
mailer:
  engine: sendmail
  smtp:
    server: localhost
    port: 25
    encryption: none
    user:
    password:
  sendmail:
    bin: '/usr/sbin/sendmail -bs'
content_type: text/html
debug: false
```

You can configure the Email plugin by using the Admin plugin, navigating to the Plugins list and choosing `Email`.

That's the easiest route. Or you can also alter the Plugin configuration by copying the `user/plugins/email/email.yaml` file into `user/config/plugins/email.yaml` and make your modifications there.

The first setting you'd likely change is your `Email from` / `Email to` names and emails.

Also, you'd likely want to setup a SMTP server instead of using PHP Mail, as the latter is not 100% reliable and you might experience problems with emails.

## Built-in Engines

By default Email 4.0 supports 4 native engines:

* SMTP - Standard "Simple Mail Transport Protocol" - The default for most providers  
* SMTPS - "Simple Mail Transport Protocol Secure" - Not very commonly used
* Sendmail - Uses the built-in `sendmail` binary file available on many Linux and Mac systems
* Native - Uses `sendmail_path` of `php.ini` for Mac + Linux, and `smtp` and `smtp_port` on Windows

Due to the modular nature of Symfony/Mailer, 3rd party engines are supported via Grav plugins.

## 3rd-Party Engines Plugin Support

Along with the **Email** `v4.0` release, there has also been several custom provider plugins released to provide support for `SMTP`, `API`, and sometimes even `HTTPS` support for 3rd party providers such as **Sendgrid**, **MailJet**, **MailGun**, **Amazon SES**, **Mailchimp/Mandrill**, and others!  `API` or `HTTPS` will provide a faster email sending experience compared to `SMTP` which is an older protocol and requires more back-and-forth negotiation and communication compared to the single-request of `API` or `HTTPS` solutions.

Examples of the currently available plugins include: 

* https://github.com/getgrav/grav-plugin-email-sendgrid - Sengrid Mailer
* https://github.com/getgrav/grav-plugin-email-amazon - Amazon SES
* https://github.com/getgrav/grav-plugin-email-mandrill - Mailchimp Mandrill Mailer
* https://github.com/getgrav/grav-plugin-email-mailersend - Mailersend Mailer

More plugins will be released soon to support `Gmail`, `Mailgun`, `Mailjet`, `OhMySMTP`, `Postmark`, and `SendInBlue`.

## SMTP Configurations for popular solutions:

### Google Email

A popular option for sending email is to simply use your Google Accounts SMTP server.  To set this up you will need to do 2 things first:

As Gmail no longer supports the "allow less secure apps" option, you now need to have 2FA enabled on the account and setup an "App Password" to create a specific password rather than your general account password.  Follow these instructions: [https://support.google.com/accounts/answer/185833](https://support.google.com/accounts/answer/185833)

Then configure the Email plugin:

```
mailer:
  engine: smtp
  smtp:
    server: smtp.gmail.com
    port: 587
    user: 'YOUR_GOOGLE_EMAIL_ADDRESS'
    password: 'YOUR_GOOGLE_PASSWORD'
```

> NOTE: Check your email sending limits: https://support.google.com/a/answer/166852?hl=en

### Mailtrap.io

A good way to test emails is to use a SMTP server service that's built for testing emails, for example [https://mailtrap.io](https://mailtrap.io)

Setup the Email plugin to use that SMTP server with the fake inbox data. For example enter this configuration in `user/config/plugins/email.yaml` or through the Admin panel:

```
mailer:
  engine: smtp
  smtp:
    server: smtp.mailtrap.io
    port: 2525
    encryption: none
    user: YOUR_MAILTRAP_INBOX_USER
    password: YOUR_MAILTRAP_INBOX_PASSWORD
```

That service will intercept emails and show them on their web-based interface instead of sending them for real.

You can try and fine tune the emails there while testing.

### Sparkpost

Generous email sending limits even in the free tier, and simple setup, make [Sparkpost](https://www.sparkpost.com) a great option for email sending. You just need to create an account, then setup a verified sending domain.  Sparkpost does a nice job of making this process very easy and undertandable. Then just click on the SMTP Relay option to get your details for the configuration:

```
mailer:
  engine: smtp
  smtp:
    server: smtp.sparkpostmail.com
    port: 587
    user: 'SMTP_Injection'
    password: 'SEND_EMAIL_API_KEY'
```

Then try sending a test email...

### Sendgrid

[Sendgrid](https://sendgrid.com) offers a very easy-to-setup serivce with 100 emails/day for free.  The next level allows you to send 40k/email a day for just $10/month. Configuration is pretty simple, just create an account, then click SMTP integration and click the button to create an API key.  The configuration is as follows:

```
mailer:
  engine: smtp
  smtp:
    server: smtp.sendgrid.net
    port: 587
    user: 'apikey'
    password: 'YOUR_SENDGRID_API_KEY'
```

### Mailgun

[Mailgun is a great service](https://www.mailgun.com/) that offers 10k/emails per month for free.  Setup does require SPIF domain verification so that means you need to add at least a TXT entry in your DNS.  This is pretty standard for SMTP sending services and does provide verification for remote email servers and makes your email sending more reliable.  The Mailgun site, walks you through this process however, and the verification process is simple and fast.

```
mailer:
  engine: smtp
  smtp:
    server: smtp.mailgun.org
    port: 587
    user: 'MAILGUN_EMAIL_ADDRESS'
    password: 'MAILGUN_EMAIL_PASSWORD'
```

Adjust these configurations for your account.

### MailJet

Mailjet is another great service that is easy to quickly setup and get started sending email.  The free account gives you 200 emails/day or 600 emails/month.  Just signup and setup your SPF and DKIM entries for your domain.  Then click on the SMTP settings and use those to configure the email plugin:

```
mailer:
  engine: smtp
  smtp:
    server: in-v3.mailjet.com
    port: 587
    user: 'MAILJUST_USERNAME_API_KEY'
    password: 'MAILJUST_PASSWORD_SECRET_KEY'
```

### ZOHO

ZOHO is a popular solution for hosted email due to it's great 'FREE' tier.  It's paid options are also very reasonable and combined with the latest UI updates and outstanding security features, it's a solid email option.

In order to get ZOHO working with Grav, you need to send email via a user account.  You can either use your users' password or generate an **App Password** via your ZOHO account (clicking on your avatar once logged in), then navigating to `My Account -> Security -> App Passwords -> Generate`. Just enter a unique app name (i.e. `Grav Website`).

NOTE: The SMTP host required can be found in `Settings -> Mail - > Mail Accounts -> POP/IMAP -> SMTP`.  This will provide the SMTP server for this account (it may not be `imap.zoho.com` depending on what region you are in)

```
mailer:
  engine: smtp
  smtp:
    server: smtp.zoho.com
    port: 587
    user: 'ZOHO_EMAIL_ADDRESS'
    password: 'ZOHO_EMAIL_PASSWORD'
```

### Sendmail

Although not as reliable as SMTP not providing as much debug information, sendmail is a simple option as long as your hosting provider is not blocking the default SMTP port `25`:

```
mailer:
  engine: sendmail
  sendmail:
    bin: '/usr/sbin/sendmail -bs'
```

Simply adjust your binary command line to suite your environment

## SMTP Email Services

Solid SMTP options that even provide a FREE tier for low email volumes include:

* SendGrid (100/day free) - https://sendgrid.com
* Mailgun - (10k/month free) - https://www.mailgun.com
* Mailjet - (6k/month free) - https://www.mailjet.com/
* Sparkpost - (15k/month free) - https://www.sparkpost.com
* Amazon SES (62k/month free) - https://aws.amazon.com/ses/

If you are still unsure why should be using one in the first place, check out this article: https://zapier.com/learn/email-marketing/best-transactional-email-sending-services/

## Testing with CLI Command

You can test your email configuration with the following CLI Command:

```
$ bin/plugin email test-email -t test@email.com
```

You can also pass in a configuration environment:

```
$ bin/plugin email test-email -t test@email.com --env=mysite.com
```

This will use the email configuration you have located in `user/mysite.com/config/plugins/email.yaml`. Read the docs to find out more about environment-based configuration: https://learn.getgrav.org/advanced/environment-config

# Programmatically send emails

Add this code in your plugins:

```php

        $to = 'email@test.com';
        $from = 'email@test.com';

        $subject = 'Test';
        $content = 'Test';

        $message = $this->grav['Email']->message($subject, $content, 'text/html')
            ->setFrom($from)
            ->setTo($to);

        $sent = $this->grav['Email']->send($message);
```

# Emails sent with Forms

When executing email actions during form processing, action parameters are inherited from the global configuration but may also be overridden on a per-action basis.

```
title: Custom form

form:
  name: custom_form
  fields:

    # Any fields you'd like to add to the form:
    # Their values may be referenced in email actions via '{{ form.value.FIELDNAME|e }}'

  process:
    email:
      subject: "[Custom form] {{ form.value.name|e }}"
      body: "{% include 'forms/data.txt.twig' %}"
      from: Custom Sender <sender@example.com>
      to: Custom Recipient <recipient@example.com>
      process_markdown: true
```

## Multiple Emails

You can send multiple emails by creating an array of emails under the `process: email:` option in the form:

```
title: Custom form

form:
  name: custom_form
  fields:

    # Any fields you'd like to add to the form:
    # Their values may be referenced in email actions via '{{ form.value.FIELDNAME|e }}'

  process:
    email:
      -
        subject: "[Custom Email 1] {{ form.value.name|e }}"
        body: "{% include 'forms/data.txt.twig' %}"
        from: Site Owner <owner@mysite.com>
        to: Recipient 1 <recepient_1@example.com>
        template: "email/base.html.twig"
      -
        subject: "[Custom Email 2] {{ form.value.name|e }}"
        body: "{% include 'forms/data.txt.twig' %}"
        from: Site Owner <owner@mysite.com>
        to: Recipient 2 <recepient_2@example.com>
        template: "email/base.html.twig"
```

## Templating Emails

You can specify a Twig template for HTML rendering, else Grav will use the default one `email/base.html.twig` which is included in this plugin.  You can also specify a custom template that extends the base, where you can customize the `{% block content %}` and `{% block footer %}`.  For example:

```twig
{% extends 'email/base.html.twig' %}

{% block content %}
<p>
    Greetings {{ form.value.name|e }},
</p>

<p>
    We have received your request for help. Our team will get in touch with you within 3 Business Days.
</p>

<p>
    Regards,
</p>

<p>
    <b>My Company</b>
    <br /><br />
    E - <a href="mailto:help@mycompany.com">help@mycompany.com</a><br />
    M - +1 555-123-4567<br />
    W - <a href="https://mycompany.com">mycompany.com</a>
</p>
{% endblock %}

{% block footer %}
  <p style="text-align: center;">My Company - All Rights Reserved</p>
{% endblock %}
```

## Sending Attachments

You can add file inputs to your form, and send those files via Email.
Just add an `attachments` field and list the file input fields names. You can have multiple file fields, and this will send all the files as attachments. Example:

```
form:
  name: custom_form
  fields:

    my-file:
      label: 'Add a file'
      type: file
      multiple: false
      destination: user/data/files
      accept:
        - application/pdf
        - application/x-pdf
        - image/png
        - text/plain

  process:

    email:
      body: '{% include "forms/data.html.twig" %}'
      attachments:
        - 'my-file'
```

## Additional action parameters

To have more control over your generated email, you may also use the following additional parameters:

* `reply_to`: Set one or more addresses that should be used to reply to the message.
* `cc` _(Carbon copy)_: Add one or more addresses to the delivery list. Many email clients will mark email in one's inbox differently depending on whether they are in the `To:` or `Cc:` list.
* `bcc` _(Blind carbon copy)_: Add one or more addresses to the delivery list that should (usually) not be listed in the message data, remaining invisible to other recipients.

### Specifying email addresses

Email-related parameters (`from`, `to`, `reply_to`, `cc`and `bcc`) allow different notations for single / multiple values:

#### Single email address string

```
to: mail@example.com
```

#### `name-addr` RFC822 Formatted string

```
to: Joe Bloggs <maiil@example.com>
```

####  Multiple email address strings

```
to:
  - mail@example.com
  - mail+1@example.com
  - mail+2@example.com
```

or in `name-addr` format:

```
to:
  - Joe Bloggs <mail@example.com>
  - Jane Doe <mail+1@example.com>
  - Jasper Jesperson <mail+2@example.com>
```

#### Simple array format with names

```
to: [mail@exmaple.com, Joe Bloggs]
```

#### Formatted email address with names

```
to:
  email: mail@example.com
  name: Joe Bloggs
```

or inline:

```
to: {email: 'mail@example.com', name: 'Joe Bloggs'}
```

#### Multiple email addresses (with and without names)

```
to:
  - [mail@example.com, Joe Bloggs]
  - [mail+2@example.com, Jane Doe]
```

```
to:
  -
    email: mail@example.com
    name: Joe Bloggs
  -
    email: mail+2@example.com
    name: Jane Doe
```

or inline:

```
to:
  - {email: 'mail@example.com', name: 'Joe Bloggs'}
  - {email: 'mail+2@example.com', name: 'Jane Doe'}
```

## Multi-part MIME messages

Apart from a simple string, an email body may contain different MIME parts (e.g. HTML body with plain text fallback):

```
body:
  -
    content_type: 'text/html'
    body: "{% include 'forms/default/data.html.twig' %}"
  -
    content_type: 'text/plain'
    body: "{% include 'forms/default/data.txt.twig' %}"

```

# Troubleshooting

## Emails are not sent

#### Debugging

The first step in determining why emails are not sent is to enable debugging.  This can be done via the `user/config/email.yaml` file or via the plugin settings in the admin.  Just enable this and then try sending an email again.  Then inspect the `logs/email.log` file for potential problems.

#### ISP Port 25 blocking

By default, when sending via PHP or Sendmail the machine running the webserver will attempt to send mail using the SMTP protocol.  This uses port `25` which is often blocked by ISPs to protected against spamming.  You can determine if this port is blocked by running this command in your temrinal (mac/linux only):

```
(echo >/dev/tcp/localhost/25) &>/dev/null && echo "TCP port 25 opened" || echo "TCP port 25 closed"
```

If it's blocked there are ways to configure relays to different ports, but the simplest solution is to use SMTP for mail sending.


#### Exceptions

If you get an exception when sending email but you cannot see what the error is, you need to enable more verbose exception messages. In the `user/config/system.yaml` file ensure your have the following configuration:

```
errors:
  display: 1
  log: true
```

## Configuration Issues

As explained above in the Configuration section, if you're using the default settings, set the Plugin configuration to use a SMTP server. It can be [Gmail](https://www.digitalocean.com/community/tutorials/how-to-use-google-s-smtp-server) or another SMTP server you have at your disposal.

This is the first thing to check. The reason is that PHP Mail, the default system used by the Plugin, is not 100% reliable and emails might not arrive.
