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

    /** @var array<string> */
    private array $consoleNames = [];

    private int $duplicateWindow = 10;
    private bool $blockConsole = true;

    public function onEnable(): void
    {
        @mkdir($this->getDataFolder());
        $this->saveDefaultConfig();
        $config = $this->getConfig();

        $this->blockedPatterns = $config->get("blocked-patterns", []);
        $this->duplicateWindow = (int) $config->get("duplicate-window-seconds", 10);
        $this->blockConsole = (bool) $config->get("block-console-messages", true);
        $this->consoleNames = $config->get("console-sender-names", [
            "",
            "CONSOLE",
            "Console",
            "console",
            "Server",
            "SERVER",
            "System",
            "SYSTEM",
        ]);

        $this->getServer()->getPluginManager()->registerEvents($this, $this);

        $this->getScheduler()->scheduleRepeatingTask(new class($this) extends Task {
            private Main $plugin;
            public function __construct(Main $plugin) { $this->plugin = $plugin; }
            public function onRun(): void { $this->plugin->cleanupCache(); }
        }, 400);

        $this->getLogger()->info("ChatFixer enabled! Console blocking: " . ($this->blockConsole ? "ON" : "OFF"));
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

            if ($packet->type !== TextPacket::TYPE_CHAT
                && $packet->type !== TextPacket::TYPE_TRANSLATION
                && $packet->type !== TextPacket::TYPE_POPUP
            ) {
                $newPackets[] = $packet;
                continue;
            }

            $message = $packet->message;
            $senderName = $packet->sourceName ?? "";

            if ($this->isFromPlayer($senderName)) {
                $newPackets[] = $packet;
                continue;
            }

            $isConsoleMessage = $this->isFromConsole($senderName);

            if ($this->matchesBlockedPattern($message)) {
                $modified = true;
                continue;
            }

            if ($this->blockConsole && $isConsoleMessage) {
                $modified = true;
                continue;
            }

            $hash = md5($message);

            if (isset($this->recentServerMessages[$hash])) {
                $elapsed = time() - $this->recentServerMessages[$hash];
                if ($elapsed < $this->duplicateWindow) {
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

    private function isFromPlayer(string $senderName): bool
    {
        if ($senderName === "") {
            return false;
        }

        $player = Server::getInstance()->getPlayerExact($senderName);
        if ($player !== null) {
            return true;
        }

        foreach (Server::getInstance()->getOnlinePlayers() as $onlinePlayer) {
            if (strcasecmp($onlinePlayer->getName(), $senderName) === 0) {
                return true;
            }
        }

        return false;
    }

    private function isFromConsole(string $senderName): bool
    {
        foreach ($this->consoleNames as $name) {
            if (strcasecmp($name, $senderName) === 0) {
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
