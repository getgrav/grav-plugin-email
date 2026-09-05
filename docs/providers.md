# The provider contract

Everything a mail provider knows about itself belongs to that provider's own Grav plugin. How its delivery webhooks are verified and read, how a webhook is created from a pasted API key, what a sending domain's DNS has to say, what its transport does to custom headers on the way out — all of it lives in `grav-plugin-email-<provider>`, behind a contract the Email plugin owns. Anything else on the site only consumes.

The reason is simple. Before this, an add-on that wanted to record bounces carried a parser for every provider it supported, which meant adding a provider was editing that add-on. Two add-ons meant two copies of the same parser drifting apart, and a provider that renamed a field in its payload broke both of them silently. Now a provider is a class in the plugin that already talks to that provider, and everything else asks.

## What you write

One class implementing `Grav\Plugin\Email\Providers\Provider`, and one listener that registers it. Nothing else changes in your plugin.

```php
use Grav\Plugin\Email\Providers\ProviderRegistry;
use RocketTheme\Toolbox\Event\Event;

public static function getSubscribedEvents()
{
    return [
        'onEmailEngines'   => ['onEmailEngines', 0],
        'onEmailProviders' => ['onEmailProviders', 0],
    ];
}

public function onEmailProviders(Event $event): void
{
    /** @var ProviderRegistry $providers */
    $providers = $event['providers'];
    $providers->add(new Smtp2goProvider($this->config()));
}
```

`onEmailProviders` is fired once per request, the first time anything asks. A listener that does real work in it — a network call, a database read — is a listener that runs on every admin screen, so build the value object and nothing more.

The registry refuses two providers claiming the same engine, and two claiming the same key, with an exception naming both classes. That is deliberate: a site where two plugins both answer for `ses` has one of them quietly winning on plugin load order, and a merchant would see delivery events verified with the wrong key and nothing anywhere saying why.

## The interface

```php
interface Provider
{
    public function engines(): array;          // ['smtp2go'] — the keys you register on onEmailEngines
    public function key(): string;             // 'smtp2go' — lowercase, addresses your routes and config
    public function label(): string;           // 'SMTP2GO' — a brand name, never translated
    public function capabilities(): Capabilities;
    public function reports(): ?DeliveryReports;   // null when you cannot report deliveries at all
    public function setup(): ?WebhookSetup;        // null when there is no API to create the webhook with
    public function domain(): DomainFacts;
    public function instructions(): string;    // one paragraph, a language key with an English fallback
}
```

`engines()` is a list because a provider that has offered its transport under more than one name over the years should answer for all of them. `key()` is separate from the engine because it is what a route and a config block are addressed by, and only one of those can exist per provider however many engines there are.

### `capabilities()`

Three answers about what your transport does to a message on the way out, and every one of them is a thing that is invisible when it goes wrong.

```php
new Capabilities(
    customHeaders: true,        // an X- header set on the message reaches the wire
    unsubscribeHeaders: true,   // List-Unsubscribe and List-Unsubscribe-Post survive
    echoesHeaders: true,        // the provider hands a registered custom header back in its webhooks
    echoNote: 'Add X-KahunaCart-Send to the webhook\'s own header list, or press Set up.',
);
```

A store sets `List-Unsubscribe`, an API transport turns the message into a JSON body and drops every header it does not recognise, the mail goes out looking perfectly fine, and a year later Gmail is filing it as spam because a bulk sender with no unsubscribe button is what a spammer looks like. Nothing on any screen would say so. Answering `unsubscribeHeaders: false` honestly is what lets a screen say so.

`signsWebhooks` is optional and almost always left out: a screen works out whether you sign from `verificationKeys()`, and only a provider that signs with a published certificate and asks the merchant for no key, like SES, needs to say `signsWebhooks: true` itself. `echoNote` is where you say what a merchant has to do for `echoesHeaders` to be true — register the header in a dashboard, or press your setup button — in plain words, because "configure header passthrough" is not instructions.

### `reports()`

Null when the provider has no delivery API at all. There is no null object to write and none to register: a transport with nothing to report simply registers nothing there, and a store says plainly that this transport cannot report deliveries. That sentence is worth far more to a merchant than a webhook address nothing will ever post to.

```php
interface DeliveryReports
{
    public function events(): array;            // from Event::TYPES: what you can report, not what a store ticked
    public function verificationKeys(): array;  // ['signing_key'] — config keys under your own plugin's config
    public function verify(WebhookRequest $request, array $config): Verdict;
    public function parse(WebhookRequest $request): Payload;
    public function sendHeader(): string;       // the header the store stamps its send id into
}
```

Three rules, and they are not style preferences — each one is a real failure that has happened:

**Verify before parse.** `verify()` runs first, over the raw bytes, before anything has decoded the body. A signature checked after the payload was parsed is a signature protecting nothing; by then a stranger has had your parser walk their JSON. And an HMAC over a body that was decoded and re-encoded on the way will not match however right the key is, so read `$request->body` and nothing else.

**Never throw from `parse()`.** Truncated JSON, an XML error page from somebody's proxy, an empty body, a documented field that turned out to be a list — all of them are `Payload::unreadable('what went wrong')`. The caller logs the first few hundred bytes and answers 200 anyway, because every one of these providers treats a 4xx as a reason to retry for days and some treat it as a reason to drop the event outright. `parse()` runs on a public address anybody can post to.

**An unrecognised event is skipped, not refused.** Every provider sends more event types than any store acts on — `processed`, `deferred`, `unsubscribed`, `delivery_delayed`. A merchant who ticked every box in your dashboard should get a 200 and a quiet log line, not a refusal. Skip it and carry on with the rest of the batch; `Payload::nothing('an event type this store does not act on')` when the whole batch was skippable.

`verify()` answers a `Verdict`: `verified()` when the signature checked out, `unsigned()` when your provider signs nothing and the URL secret was the whole check, `refused('why')` when it did not, and `confirm($url)` when this request was the provider asking you to fetch a URL to prove the address is yours — Amazon's SNS subscriptions begin that way. Name the URL and check it is really the provider's own host; the caller makes the request, because a provider is a pure function of a request and fetching a URL is not.

The refusal reason is written for the store's log, in plain words. It is never sent back to the caller: telling a forger which check they failed is telling them what to fix.

### `setup()`

Null when there is no API for it. Otherwise a driver sets itself up from the pasted key, and manual steps are the fallback rather than the plan — a merchant who has already given your plugin an API key should not then have to find a dashboard, guess which of four things called "webhooks" is the right one, tick five boxes, register a custom header by hand and pick an output format, every one of which is a place to get it silently wrong.

```php
interface WebhookSetup
{
    public function create(string $url, array $events, array $config): SetupResult;
    public function permissionsNeeded(): string;
}
```

Pressing the button twice must not leave a store with two webhooks posting the same events at the same address, so look for your own and update it where the API allows that. `SetupResult::failed()` takes a finished sentence a merchant can act on — "403" is not a message, and "The API key was refused. It needs the Manage Webhooks permission, which is set on the key's own page" is. `permissionsNeeded()` is separate because that sentence is the same every time and is worth showing before the button is pressed.

### `domain()`

```php
new DomainFacts(
    spfInclude: 'spf.smtp2go.com',
    dkimZone: 'dkim.smtp2go.net',
    returnPathZone: 'return.smtp2go.net',
    lookup: fn (string $domain): array => $this->api->domainFacts($domain),
);
```

The selector itself is deliberately not here. Selectors are per domain and per account — one provider uses `s` plus the account's numeric id, another generates three random tokens, a third uses a date — so there is nothing to guess and no way to walk a zone to find one. What is here is the convention: the zone a selector points into, which is enough to tell a real selector from a name that happens to resolve, and enough to spot the store that changed provider and left last year's record behind.

`lookup` is the way out of that when your API will say. It is handed a domain and answers `['selectors' => [...], 'return_paths' => [...]]`, either key absent meaning "could not say". It must never throw and it must have a short timeout: a provider's API being slow is an unanswered question, not a broken settings screen. Callers cache the answer.

### A transport with no delivery API

Do nothing. Do not register a provider that answers null from everything; that is worse than registering none, because a store then draws a card for a provider that can say nothing about anything. Leave `onEmailProviders` unimplemented and the store will say the transport cannot report deliveries, which is true and is the more useful sentence.

## Where the credentials live

Two different secrets, owned by two different plugins, and they are constantly confused.

**The URL secret** belongs to whatever built the webhook address — an add-on that receives delivery reports, typically. It is the random string in the path of the URL a merchant pastes into a provider's dashboard, and for a provider that signs nothing it is the whole of the protection. It is that add-on's to mint, store and print on its own settings screen.

**The verification keys** belong to your plugin. Mailgun's HTTP webhook signing key, SendGrid's verification key, Postmark's basic auth pair — every one of them is a credential for talking to that provider, and it goes in your plugin's own blueprint beside the sending credentials you already keep. `verificationKeys()` names them and `verify()` is handed their values.

The rule is: if losing it would let somebody forge events from the provider, it is yours. If losing it would let somebody find the store's address, it is the store's.

## Reading the contract from another plugin

Ask, do not compare version numbers — a version comparison hard-codes a release into every caller and gets it wrong the first time a fix is backported.

```php
$email = Grav::instance()['Email'] ?? null;

if ($email !== null && method_exists($email, 'supportsFeature') && $email::supportsFeature('providers')) {
    $provider = $email::providerFor($engine);   // null when no plugin registered one
} else {
    // whatever you were doing before
}
```

`Email::providers()` answers the whole registry, `providerFor(string $engine)` finds the one whose `engines()` names an engine, and `providerByKey(string $key)` finds one by key. All three answer null or an empty registry rather than throwing, and null is a real answer with a plain meaning: this transport cannot report deliveries. Say that, rather than showing a merchant an address nothing will ever post to.

## Testing a provider

Against the provider's own documented sample payloads, saved as fixtures, parsed, and checked field by field. That is the test that catches the thing nothing else catches — every one of these providers renames a field eventually, and the failure is silent. Check the timestamp too: a date format nobody parsed reads as zero and gets quietly stamped with the receiver's clock, and a whole store's charts are then wrong in a way nobody can see.

For signatures, compute them in the test rather than pasting one, then break them: change a byte of the body, replace the signature, replay an old timestamp, point a certificate URL at a host that merely ends with the provider's domain. Every one of those is a real attack and every one is three lines of test.

`WebhookRequest` is built directly in a test — a method, a path, a headers array, a body string — so none of this needs Grav, a route, or a running site.
