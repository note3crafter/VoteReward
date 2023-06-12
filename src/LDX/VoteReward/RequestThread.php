<?php

namespace LDX\VoteReward;

use pocketmine\Server;
use pocketmine\scheduler\AsyncTask;

class RequestThread extends AsyncTask {

    private $id;
    private $queries;
    private $rewards;
    private string $error;
    private Main $plugin;

    public function __construct(Main $plugin, $id, $queries) {
        $this->id = $id;
        $this->queries = $queries;
        $this->plugin = $plugin;
    }

    public function onRun() :void {
        foreach(igbinary_unserialize($this->queries) as $query) {
            if(($return = Utils::getURL(str_replace("{USERNAME}", urlencode($this->id), $query->getCheckURL()))) && is_array(($return = json_decode($return, true))) && isset($return["voted"]) && is_bool($return["voted"]) && isset($return["claimed"]) && is_bool($return["claimed"])) {
                    $query->setVoted($return["voted"] ? 1 : -1);
                $query->setClaimed($return["claimed"] ? 1 : -1);
                if($query->hasVoted() && !$query->hasClaimed()) {
                    if(($return = Utils::getURL(str_replace("{USERNAME}", urlencode($this->id), $query->getClaimURL()))) && is_array(($return = json_decode($return, true))) && isset($return["voted"]) && is_bool($return["voted"]) && isset($return["claimed"]) && is_bool($return["claimed"])) {
                        $query->setVoted($return["voted"] ? 1 : -1);
                        $query->setClaimed($return["claimed"] ? 1 : -1);
                        if($query->hasVoted() || $query->hasClaimed()) {
                            $this->rewards++;
                        }
                    } else {
                        $this->error = "Error sending claim data for \"" . $this->id . "\" to \"" . str_replace("{USERNAME}", urlencode($this->id), $query->getClaimURL()) . "\". Invalid VRC file or bad Internet connection.";
                        $query->setVoted(-1);
                        $query->setClaimed(-1);
                    }
                }
            } else {
                $this->error = "Error fetching vote data for \"" . $this->id . "\" from \"" . str_replace("{USERNAME}", urlencode($this->id), $query->getCheckURL()) . "\". Invalid VRC file or bad Internet connection.";
                $query->setVoted(-1);
                $query->setClaimed(-1);
            }
        }
    }

    public function onCompletion() :void {
        if(isset($this->error)) {
            $this->plugin->getLogger()->error($this->error);
        }
        $this->plugin->rewardPlayer($this->plugin->getServer()->getPlayerExact($this->id), $this->rewards);
        array_splice($this->plugin->queue, array_search($this->id, $this->plugin->queue, true), 1);
    }
}