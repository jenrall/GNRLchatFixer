# ChatFixer

A lightweight PocketMine-MP plugin that cleans up chat by blocking spam, console messages, and repeated server messages — while always letting player messages and command output through.

## ✨ Features

- 🚫 Blocks duplicate server messages within a configurable time window
- 🚫 Blocks all console messages (optional)
- ✅ Player messages are **never** blocked (even if repeated)
- ✅ Command output from players is **never** blocked
- ⚙️ Fully configurable via `config.yml`
- 🔧 Works on all players including OPs (packet-level filter)

## 📦 Installation

1. Download the latest release from the [Releases](../../releases) page
2. Drop the `.phar` into your `plugins/` directory
3. Restart your server
4. Edit `plugins/ChatFixer/config.yml` to fit your needs

## ⚙️ Configuration

| Option | Type | Default | Description |
|--------|------|---------|-------------|
| `duplicate-window-seconds` | int | `10` | Time window for blocking duplicate server messages |
| `block-console-messages` | bool | `true` | Block all console messages from chat |
| `console-sender-names` | list | see config | Names treated as console / server |
| `blocked-patterns` | list | `[]` | Regex patterns to block |

## 🧠 How it works
