---
title: Email
template: chapter
description: Send emails via the Grav API
taxonomy:
    category:
        - docs
---

The Email API allows you to send ad-hoc emails and test your email configuration programmatically. These endpoints are registered by the Email plugin via the Grav API plugin's extensibility system.

## Requirements

- [Grav API Plugin](https://github.com/getgrav/grav-plugin-api) must be installed and enabled
- Email plugin must be installed, enabled, and configured with a valid mailer

## Authentication

All email endpoints require the `api.system.write` permission.

## Available Operations

| Method | Endpoint | Description |
|--------|----------|-------------|
| POST | `/email/send` | Send an ad-hoc email |
| POST | `/email/test` | Send a test email to verify configuration |
