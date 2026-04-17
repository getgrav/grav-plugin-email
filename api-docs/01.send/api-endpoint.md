---
title: 'Send Email'
template: api-endpoint
taxonomy:
  category: docs
api:
  method: POST
  path: /email/send
  description: 'Send an ad-hoc email. Uses the email plugin''s configured mailer transport (SMTP, Sendmail, etc). The "from" address defaults to the plugin''s configured sender if not specified.'
  parameters:
    -
      name: to
      type: string
      required: true
      description: 'Recipient email address. Supports: "user@example.com", "Name <user@example.com>", or comma-separated for multiple recipients.'
    -
      name: subject
      type: string
      required: true
      description: 'Email subject line.'
    -
      name: body
      type: string
      required: true
      description: 'Email body content. Interpreted as HTML by default (see content_type).'
    -
      name: from
      type: string
      required: false
      description: 'Sender email address. Defaults to the email plugin''s configured "from" address.'
    -
      name: cc
      type: string
      required: false
      description: 'CC recipient(s). Same format as "to".'
    -
      name: bcc
      type: string
      required: false
      description: 'BCC recipient(s). Same format as "to".'
    -
      name: reply_to
      type: string
      required: false
      description: 'Reply-To address.'
    -
      name: content_type
      type: string
      required: false
      description: 'Content type: "text/html" (default) or "text/plain".'
  request_example: "{\n    \"to\": \"recipient@example.com\",\n    \"subject\": \"Hello from Grav API\",\n    \"body\": \"<h1>Hello</h1><p>This email was sent via the Grav API.</p>\",\n    \"from\": \"sender@example.com\",\n    \"cc\": \"copy@example.com\",\n    \"content_type\": \"text/html\"\n}"
  response_example: "{\n    \"data\": {\n        \"message\": \"Email sent successfully.\",\n        \"to\": \"recipient@example.com\",\n        \"subject\": \"Hello from Grav API\"\n    }\n}"
  response_codes:
    -
      code: '200'
      description: 'Email sent successfully'
    -
      code: '401'
      description: 'Unauthorized - missing or invalid API key'
    -
      code: '403'
      description: 'Forbidden - missing api.system.write permission'
    -
      code: '422'
      description: 'Validation error - missing required fields (to, subject, body)'
    -
      code: '500'
      description: 'Send failed - mailer transport error'
    -
      code: '503'
      description: 'Email plugin not enabled or configured'
---

## Usage Notes

### Required Headers

```
X-API-Key: grav_your_api_key
X-Grav-Environment: localhost
Content-Type: application/json
```

### Multiple Recipients

Send to multiple recipients by comma-separating addresses:

```json
{
    "to": "user1@example.com, user2@example.com",
    "subject": "Team Update",
    "body": "<p>Hello team!</p>"
}
```

Or use named addresses:

```json
{
    "to": "Alice <alice@example.com>, Bob <bob@example.com>"
}
```

### Plain Text Emails

Set `content_type` to send plain text instead of HTML:

```json
{
    "to": "user@example.com",
    "subject": "Plain text message",
    "body": "This is a plain text email.\n\nNo HTML formatting.",
    "content_type": "text/plain"
}
```

### Example with cURL

```bash
curl -X POST "https://yoursite.com/api/v1/email/send" \
  -H "X-API-Key: grav_your_key" \
  -H "X-Grav-Environment: localhost" \
  -H "Content-Type: application/json" \
  -d '{
    "to": "recipient@example.com",
    "subject": "Hello from Grav",
    "body": "<h1>Hello</h1><p>Sent via the API</p>"
  }'
```

### Error Handling

If the email transport fails (e.g., SMTP connection error), the API returns a 500 error with details:

```json
{
    "status": 500,
    "title": "Internal Server Error",
    "detail": "Failed to send email: Connection to smtp.example.com:587 timed out"
}
```
