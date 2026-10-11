<?php

declare(strict_types=1);

namespace ChatFixer;

use pocketmine\event\Listener;
use pocketmine\event\server\DataPacketSendEvent;
use pocketmine\network\mcpe\protocol\TextPacket;
use pocketmine\plugin\PluginBase;
use pocketmine\scheduler\Task;
use pocketmine\utils\Config;

class Main extends PluginBase implements Listener
{
    /** @var array<string, int> */
    private array $recentMessages = [];

    /** @var array<string> */
    private array $blockedPatterns = [];

    private int $duplicateWindow = 5;
    private int $maxDuplicates = 1;
    private bool $blockRepeatedAutoMessages = true;

    public function onEnable(): void
    {
        @mkdir($this->getDataFolder());
        $this->saveDefaultConfig();
        $config = $this->getConfig();

        $this->blockedPatterns = $config->get("blocked-patterns", [
            "/^§[a-z0-9]/i",        // رنگ‌های معمول
        ]);
        $this->duplicateWindow = (int) $config->get("duplicate-window-seconds", 5);
        $this->maxDuplicates = (int) $config->get("max-duplicates", 1);
        $this->blockRepeatedAutoMessages = (bool) $config->get("block-repeated-auto-messages", true);

        $this->getServer()->getPluginManager()->registerEvents($this, $this);

        // پاکسازی دوره‌ای حافظه
        $this->getScheduler()->scheduleRepeatingTask(new class($this) extends Task {
            private Main $plugin;
            public function __construct(Main $plugin) { $this->plugin = $plugin; }
            public function onRun(): void { $this->plugin->cleanupCache(); }
        }, 400); // هر 20 ثانیه

        $this->getLogger()->info("ChatFixer enabled!");
    }

    public function cleanupCache(): void
    {
        $now = time();
        foreach ($this->recentMessages as $hash => $timestamp) {
            if ($now - $timestamp > $this->duplicateWindow * 2) {
                unset($this->recentMessages[$hash]);
            }
        }
    }

    /**
     * @priority HIGHEST
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

            // فقط پیام‌های نوع chat و translation و popup رو بررسی کن
            if ($packet->type !== TextPacket::TYPE_CHAT
                && $packet->type !== TextPacket::TYPE_TRANSLATION
                && $packet->type !== TextPacket::TYPE_POPUP
            ) {
                $newPackets[] = $packet;
                continue;
            }

            $message = $packet->message;

            // اگر پیام از طرف بازیکن نیست، بررسی کن که تکراریه یا نه
            $senderName = $packet->sourceName ?? "";

            // چک کردن الگوهای مسدود
            if ($this->matchesBlockedPattern($message)) {
                $modified = true;
                continue;
            }

            // فقط پیام‌های بدون فرستنده (auto messages) رو فیلتر کن
            if ($senderName === "" || $this->isServerMessage($message, $senderName)) {
                $hash = md5($message);

                if (isset($this->recentMessages[$hash])) {
                    $elapsed = time() - $this->recentMessages[$hash];
                    if ($elapsed < $this->duplicateWindow) {
                        // پیام تکراری - مسدودش کن
                        $modified = true;
                        continue;
                    }
                }

                $this->recentMessages[$hash] = time();
            }

            $newPackets[] = $packet;
        }

        if ($modified) {
            $event->setPackets($newPackets);
        }
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

    private function isServerMessage(string $message, string $senderName): bool
    {
        // اگه پیام هیچ اسم فرستنده‌ای نداره یا اسمش با نام‌های سیستمی یکیه
        $serverNames = ["Server", "SERVER", "System", "Console", ""];
        return in_array($senderName, $serverNames, true);
    }
}
