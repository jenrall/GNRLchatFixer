<?php

declare(strict_types=1);

namespace ChatFixer;

use pocketmine\event\Listener;
use pocketmine\event\server\DataPacketSendEvent;
use pocketmine\network\mcpe\protocol\TextPacket;
use pocketmine\plugin\PluginBase;
use pocketmine\scheduler\Task;
use pocketmine\Server;

class Main extends PluginBase implements Listener
{
    /** @var array<string, int> */
    private array $recentServerMessages = [];

    /** @var array<string> */
    private array $blockedPatterns = [];

    private int $duplicateWindow = 5;

    public function onEnable(): void
    {
        @mkdir($this->getDataFolder());
        $this->saveDefaultConfig();
        $config = $this->getConfig();

        $this->blockedPatterns = $config->get("blocked-patterns", []);
        $this->duplicateWindow = (int) $config->get("duplicate-window-seconds", 5);

        $this->getServer()->getPluginManager()->registerEvents($this, $this);

        $this->getScheduler()->scheduleRepeatingTask(new class($this) extends Task {
            private Main $plugin;
            public function __construct(Main $plugin) { $this->plugin = $plugin; }
            public function onRun(): void { $this->plugin->cleanupCache(); }
        }, 400);

        $this->getLogger()->info("ChatFixer enabled!");
    }

    public function cleanupCache(): void
    {
        $now = time();
        foreach ($this->recentServerMessages as $hash => $timestamp) {
            if ($now - $timestamp > $this->duplicateWindow * 2) {
                unset($this->recentServerMessages[$hash]);
            }
        }
    }

    /**
     * @priority HIGHEST
     * @ignoreCancelled true
     */
    public function onDataPacketSend(DataPacketSendEvent $event): void
    {
        $packets = $event->getPackets();
        $modified = false;
        $newPackets = [];

        foreach ($packets as $packet) {
            if (!$packet instanceof TextPacket) {
                $newPackets[] = $packet;
                continue;
            }

            // فقط chat و translation و popup
            if ($packet->type !== TextPacket::TYPE_CHAT
                && $packet->type !== TextPacket::TYPE_TRANSLATION
                && $packet->type !== TextPacket::TYPE_POPUP
            ) {
                $newPackets[] = $packet;
                continue;
            }

            $message = $packet->message;
            $senderName = $packet->sourceName ?? "";

            // ⭐ چک اصلی: اگه پیام از طرف بازیکن باشه، هرگز مسدود نکن
            if ($this->isFromPlayer($senderName)) {
                $newPackets[] = $packet;
                continue;
            }

            // ⭐ از اینجا به بعد یعنی پیام از سرور/سیستمه → فیلترش کن

            // چک الگوهای مسدود
            if ($this->matchesBlockedPattern($message)) {
                $modified = true;
                continue;
            }

            // چک تکراری بودن پیام‌های سرور
            $hash = md5($message);

            if (isset($this->recentServerMessages[$hash])) {
                $elapsed = time() - $this->recentServerMessages[$hash];
                if ($elapsed < $this->duplicateWindow) {
                    // پیام تکراری سرور → مسدودش کن
                    $modified = true;
                    continue;
                }
            }

            $this->recentServerMessages[$hash] = time();
            $newPackets[] = $packet;
        }

        if ($modified) {
            $event->setPackets($newPackets);
        }
    }

    /**
     * بررسی می‌کنه که آیا فرستنده پیام یه بازیکن آنلاینه یا نه
     */
    private function isFromPlayer(string $senderName): bool
    {
        if ($senderName === "") {
            return false;
        }

        // اگه اسم فرستنده یکی از بازیکن‌های آنلاین باشه → از بازیکنه
        $player = Server::getInstance()->getPlayerExact($senderName);
        if ($player !== null) {
            return true;
        }

        // اگه اسم فرستنده با یکی از بازیکن‌ها مطابقت داشته باشه (case-insensitive)
        foreach (Server::getInstance()->getOnlinePlayers() as $onlinePlayer) {
            if (strcasecmp($onlinePlayer->getName(), $senderName) === 0) {
                return true;
            }
        }

        return false;
    }

    private function matchesBlockedPattern(string $message): bool
    {
        foreach ($this->blockedPatterns as $pattern) {
            if (@preg_match($pattern, $message) === 1) {
                return true;
            }
        }
        return false;
    }
}
