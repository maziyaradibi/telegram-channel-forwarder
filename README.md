# Telegram Channel Auto Forwarder Bot (PHP)

A lightweight PHP script that monitors a public Telegram channel and forwards new posts (including media) to another channel.

## Features

- Scrapes public Telegram channel posts
- Forwards text, photos, and videos
- Keyword-based advertisement filtering
- Lock mechanism to prevent concurrent execution
- State file to prevent duplicate sending

## Setup

1. Edit `bot.php`
2. Set:

   - BOT_TOKEN
   - SOURCE_CHANNEL
   - DEST_CHANNEL

3. Run with cron:
