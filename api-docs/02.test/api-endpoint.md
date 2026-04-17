---
title: 'Test Email'
template: api-endpoint
taxonomy:
  category: docs
api:
  method: POST
  path: /email/test
  description: 'Send a test email to verify that the email plugin is correctly configured. Sends a simple HTML email with a timestamp. Useful for validating SMTP settings, DNS, and deliverability.'
  parameters:
    -
      name: to
      type: string
      required: false
      description: 'Recipient email address. Defaults to the email plugin''s configured "to" address.'
  request_example: "{\n    \"to\": \"admin@example.com\"\n}"
  response_example: "{\n    \"data\": {\n        \"message\": \"Test email sent successfully.\",\n        \"to\": \"admin@example.com\"\n    }\n}"
  response_codes:
    -
      code: '200'
      description: 'Test email sent successfully'
    -
      code: '401'
      description: 'Unauthorized - missing or invalid API key'
    -
      code: '403'
      description: 'Forbidden - missing api.system.write permission'
    -
      code: '422'
      description: 'No recipient - no "to" provided and no default configured'
    -
      code: '500'
      description: 'Send failed - mailer transport error'
    -
      code: '503'
      description: 'Email plugin not enabled or configured'
---

## Usage Notes

### Quick Configuration Check

The simplest way to verify your email setup works end-to-end:

```bash
curl -X POST "https://yoursite.com/api/v1/email/test" \
  -H "X-API-Key: grav_your_key" \
  -H "X-Grav-Environment: localhost" \
  -H "Content-Type: application/json" \
  -d '{"to": "your@email.com"}'
```

### Default Recipient

If you omit the `to` field, the test email is sent to the default recipient configured in the email plugin settings (`plugins.email.to`). If neither is set, the endpoint returns a 422 error.

### Test Email Content

The test email contains:

- **Subject**: "Grav API - Test Email"
- **Body**: A simple HTML message with the send timestamp
- **From**: The configured sender address from email plugin settings

This is intentionally simple to isolate transport issues from content/template problems.
