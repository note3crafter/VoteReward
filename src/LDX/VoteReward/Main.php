<?php

namespace LDX\VoteReward;

use pocketmine\command\Command;
use pocketmine\command\CommandSender;
use pocketmine\console\ConsoleCommandSender;
use pocketmine\item\Item;
use pocketmine\item\ItemIdentifier;
use pocketmine\item\StringToItemParser;
use pocketmine\player\Player;
use pocketmine\plugin\PluginBase;
use pocketmine\Server;

class Main extends PluginBase {

    private string $message = "";
    private array $items = [];
    private array $commands = [];
    protected bool $debug;
    public array $queue = [];
    private array $lists;

    public function onLoad() :void {
        if(file_exists($this->getDataFolder() . "config.yml")) {
            $c = $this->getConfig()->getAll();
            if(isset($c["API-Key"])) {
                if(trim($c["API-Key"]) != "") {
                    if(!is_dir($this->getDataFolder() . "Lists/")) {
                        mkdir($this->getDataFolder() . "Lists/");
                    }
                    file_put_contents($this->getDataFolder() . "Lists/minecraftpocket-servers.com.vrc", "{\"website\":\"http://minecraftpocket-servers.com/\",\"check\":\"http://minecraftpocket-servers.com/api-vrc/?object=votes&element=claim&key=" . $c["API-Key"] . "&username={USERNAME}\",\"claim\":\"http://minecraftpocket-servers.com/api-vrc/?action=post&object=votes&element=claim&key=" . $c["API-Key"] . "&username={USERNAME}\"}");
                    rename($this->getDataFolder() . "config.yml", $this->getDataFolder() . "config.old.yml");
                    $this->getLogger()->info("§eConverting API key to VRC file...");
                } else {
                    rename($this->getDataFolder() . "config.yml", $this->getDataFolder() . "config.old.yml");
                    $this->getLogger()->info("§eSetting up new configuration file...");
                }
            }
        }
    }

    public function onEnable() :void {
        $this->reload();
    }

    public function reload() {
        $this->saveDefaultConfig();
        if(!is_dir($this->getDataFolder() . "Lists/")) {
            mkdir($this->getDataFolder() . "Lists/");
        }
        $this->lists = [];
        foreach(scandir($this->getDataFolder() . "Lists/") as $file) {
            $ext = explode(".", $file);
            $ext = (count($ext) > 1 && isset($ext[count($ext) - 1]) ? strtolower($ext[count($ext) - 1]) : "");
            if($ext == "vrc") {
                $this->lists[] = json_decode(file_get_contents($this->getDataFolder() . "Lists/$file"), true);
            }
        }
        $this->reloadConfig();
        $config = $this->getConfig()->getAll();
        $this->message = $config["Message"];
        $this->items = [];
        foreach($config["Items"] as $i) {
            $r = explode(":", $i);
            $this->items[] = StringToItemParser::getInstance()->parse($r[0])->setCount($r[1]);
        }
        $this->commands = $config["Commands"];
        $this->debug = isset($config["Debug"]) && $config["Debug"] === true ? true : false;
    }

    public function onCommand(CommandSender $sender, Command $command, string $label, array $args) : bool{
        switch(strtolower($command->getName())) {
            case "vote":
                if(isset($args[0]) && strtolower($args[0]) == "reload") {
                    if(Utils::hasPermission($sender, "votereward.command.reload")) {
                        $this->reload();
                        $sender->sendMessage("[VoteReward] All configurations have been reloaded.");
                        break;
                    }
                    $sender->sendMessage("You do not have permission to use this subcommand.");
                    break;
                }
                if(!$sender instanceof Player) {
                    $sender->sendMessage("This command must be used in-game.");
                    break;
                }
                if(!Utils::hasPermission($sender, "votereward.command.vote")) {
                    $sender->sendMessage("You do not have permission to use this command.");
                    break;
                }
                if(in_array(strtolower($sender->getName()), $this->queue)) {
                    $sender->sendMessage("[VoteReward] Slow down! We're already checking lists for you.");
                    break;
                }
                $this->queue[] = strtolower($sender->getName());
                $requests = [];
                foreach($this->lists as $list) {
                    if(isset($list["check"]) && isset($list["claim"])) {
                        $requests[] = new ServerListQuery($list["check"], $list["claim"]);
                    }
                }
                $query = new RequestThread(strtolower($sender->getName()), igbinary_serialize($requests));
                Server::getInstance()->getAsyncPool()->submitTask($query);
                break;
            default:
                $sender->sendMessage("Invalid command.");
                break;
        }
        return true;
    }

    public function rewardPlayer($player, $multiplier) {
        if(!$player instanceof Player) {
            return;
        }
        if($multiplier < 1) {
            $player->sendMessage("[VoteReward] You haven't voted on any server lists!");
            return;
        }
        $clones = [];
        foreach($this->items as $item) {
            $clones[] = clone $item;
        }
        foreach($clones as $item) {
            $item->setCount($item->getCount() * $multiplier);
            $player->getInventory()->addItem($item);
        }
        foreach($this->commands as $command) {
            $this->getServer()->dispatchCommand(new ConsoleCommandSender(Server::getInstance(), Server::getInstance()->getLanguage()), str_replace([
                "{USERNAME}",
                "{NICKNAME}",
                "{X}",
                "{Y}",
                "{Y1}",
                "{Z}"
            ], [
                $player->getName(),
                $player->getDisplayName(),
                $player->getPosition()->getX(),
                $player->getPosition()->getY(),
                $player->getPosition()->getY() + 1,
                $player->getPosition()->getZ()
            ], Utils::translateColors($command)));
        }
        if(trim($this->message) != "") {
            $message = str_replace([
                "{USERNAME}",
                "{NICKNAME}"
            ], [
                $player->getName(),
                $player->getDisplayName()
            ], Utils::translateColors($this->message));
            foreach($this->getServer()->getOnlinePlayers() as $p) {
                $p->sendMessage($message);
            }
            $this->getServer()->getLogger()->info($message);
        }
        $player->sendMessage("[VoteReward] You voted on $multiplier server list" . ($multiplier == 1 ? "" : "s") . "!");
    }

}