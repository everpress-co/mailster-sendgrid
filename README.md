# Mailster SendGrid Integration

Contributors: everpress, mailster, xaverb  
Tags: sendgrid, mailster, newsletter, email, mailsteresp  
Requires at least: 6.0  
Tested up to: 7.1  
Stable tag: 2.1.1  
License: GPLv2 or later

Deliver Mailster newsletters with SendGrid via the Web API or SMTP, with API key checks, categories, and bounce syncing.

## Description

**Mailster SendGrid Integration** connects [SendGrid](https://sendgrid.com/) to the [Mailster Newsletter Plugin for WordPress](https://mailster.co/?utm_campaign=wporg&utm_source=wordpress.org&utm_medium=readme&utm_term=SendGrid) so you can send campaigns through SendGrid instead of your web host’s mail server.

Use it when SendGrid is your email delivery provider and you want to keep Mailster’s campaign editor, lists, and subscriber management.

> This plugin requires [Mailster](https://mailster.co/?utm_campaign=wporg&utm_source=wordpress.org&utm_medium=readme&utm_term=SendGrid) **4.0** or newer and a SendGrid account with an API key. When SendGrid is the active delivery method, outgoing email content and delivery metadata are sent to SendGrid at `api.sendgrid.com`.

### Why send Mailster newsletters with SendGrid?

- **SendGrid delivery for Mailster** — Registers SendGrid as a delivery method under **Newsletter → Settings → Delivery**.
- **Web API or SMTP** — Send with the SendGrid Mail Send API v3 (recommended), or relay through `smtp.sendgrid.net` using the username `apikey`. Secure SMTP uses port **465**; otherwise the plugin uses port **587**.
- **API key check** — Verifies the key against the SendGrid scopes endpoint and shows whether it is accepted.
- **Categories** — On the Web API, attach up to 10 SendGrid categories so you can group sends in SendGrid.
- **Reply-to, attachments, and inline images** — The Web API sends reply-to, file attachments, and inline images with the message.
- **Campaign metadata** — The Web API includes Mailster campaign, subscriber, and message IDs as SendGrid custom arguments, and forwards Mailster headers.
- **Bounce and suppression sync** — Reads SendGrid bounces, blocks, spam reports, and unsubscribes and applies them in Mailster.

### How it works

1. Install and activate **Mailster SendGrid Integration** alongside Mailster 4.0+.
2. Create a SendGrid API key with **Mail Send** access and **Suppressions** read access, and authenticate the sender or domain in SendGrid.
3. Open **Newsletter → Settings → Delivery**, select the **SendGrid** tab, paste the API key, and save.
4. Choose **WEB API** or **SMTP API**. For SMTP, turn on the secure connection if you need port 465.
5. Send a test email from a verified From address, then run your Mailster campaigns through SendGrid.

Setup walkthrough: [Send your newsletters via SendGrid](https://kb.mailster.co/send-your-newsletters-via-sendgrid/?utm_campaign=wporg&utm_source=SendGrid&utm_medium=readme).

## Installation

1. Upload the entire `mailster-sendgrid` folder to the `/wp-content/plugins/` directory, or install it from the WordPress plugins screen.
2. Activate the plugin through the **Plugins** menu in WordPress.
3. Make sure [Mailster](https://mailster.co/?utm_campaign=wporg&utm_source=wordpress.org&utm_medium=readme&utm_term=SendGrid) **4.0** or newer is installed and active.
4. In SendGrid, create an API key with Mail Send access and Suppressions read access, and complete sender or domain authentication. See the [setup guide](https://kb.mailster.co/send-your-newsletters-via-sendgrid/?utm_campaign=wporg&utm_source=SendGrid&utm_medium=readme).
5. Go to **Settings → Newsletter → Delivery**, open the **SendGrid** tab, enter the API key, and save.
6. Choose WEB API (recommended) or SMTP API, add optional categories, and send a test email.

## Frequently Asked Questions

### Does this plugin work without Mailster?

No. This is a delivery addon for the [Mailster Newsletter Plugin](https://mailster.co/?utm_campaign=wporg&utm_source=wordpress.org&utm_medium=readme&utm_term=SendGrid) and requires Mailster **4.0** or newer.

### Do I need a SendGrid account?

Yes. You need a SendGrid account, an API key, and a verified sender or authenticated domain. SendGrid is a separate email delivery service; see SendGrid for current plans. This plugin is not a SendGrid product.

### What is the difference between WEB API and SMTP?

**WEB API** (recommended) sends with `POST https://api.sendgrid.com/v3/mail/send` and supports categories, reply-to, attachments, and inline images. **SMTP API** relays through `smtp.sendgrid.net` with username `apikey` and your API key as the password. Use port 465 when “secure connection” is enabled, or port 587 when it is not.

### Which API key permissions are required?

Mailster’s setup guide asks for **Mail Send** access so messages can be sent, and **Suppressions** read access so bounces, blocks, spam reports, and unsubscribes can be imported. The plugin checks the key with SendGrid’s scopes endpoint.

### How are bounces handled?

Choose **SendGrid** (recommended) to import suppressions into Mailster. With the Web API, Mailster cannot process bounces itself, so bounce handling is set to SendGrid. With SMTP you can choose Mailster’s bounce mailbox instead, but SendGrid must have **preserve headers** enabled or the return path is overwritten. When SendGrid handles bounces, the settings on Mailster’s bounce tab are ignored.

Details: [How to handle bounces with SendGrid](https://kb.mailster.co/how-to-handle-bounces-with-sendgrid/?utm_campaign=wporg&utm_source=SendGrid&utm_medium=readme).

### What are categories?

On the Web API you can enter up to 10 comma-separated SendGrid categories. SendGrid uses categories to group messages. They are not a substitute for Mailster lists.

### Is email data sent to a third party?

Yes. When this delivery method is active, Mailster sends email content and related delivery metadata to **SendGrid** so messages can be delivered. Suppression data is read back from SendGrid’s API.

### Where can I get support?

For Mailster and this addon, see [mailster.co](https://mailster.co/?utm_campaign=wporg&utm_source=wordpress.org&utm_medium=readme&utm_term=SendGrid) and the [SendGrid setup guide](https://kb.mailster.co/send-your-newsletters-via-sendgrid/?utm_campaign=wporg&utm_source=SendGrid&utm_medium=readme). For SendGrid account, billing, and API questions, use [SendGrid’s Mail Send documentation](https://docs.sendgrid.com/api-reference/mail-send/mail-send).

## Changelog

### 2.1.1

- fixed: API key status messages using the SparkPost translation domain
- fixed: warning on PHP >8.1

### 2.1

- fixed: issue with invalid data type with WP 5.5

### 2.0.2

- fixed: issue with Mailster 2.3.16+ and reply_to headers

### 2.0.1

- fixed: undefined reply_to email address

### 2.0

- switch to SendGrid API v3 requires now an API Key
- embedded images works now with the WEB API
- several improvements

### 1.0.1

- fixed: issue with slashes in content when using the WEB API

### 1.0

- initial release
