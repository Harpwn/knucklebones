<?php

declare(strict_types=1);

namespace Bga\Games\Knucklebones;

require_once('constants.inc.php');
require_once('utils.php');
require_once('actions.php');
require_once('states.php');
require_once('args.php');
require_once('debug-util.php');

class Game extends \Bga\GameFramework\Table
{
    use \UtilsTrait;
    use \ActionTrait;
    use \StatesTrait;
    use \ArgsTrait;
    use \DebugUtilTrait;

    public function __construct()
    {
        parent::__construct();

        $this->initGameStateLabels([]);

        $this->notify->addDecorator(function(string $message, array $args) {
            if (isset($args['player_id']) && !isset($args['player_name']) && str_contains($message, '${player_name}')) {
                $args['player_name'] = $this->getPlayerNameById($args['player_id']);
            }
    
            return $args;
        });
    }

    public function getGameProgression()
    {
        //Get empty spaces for each player, group by playerid
        $sql = "SELECT board_player, COUNT(*) AS empty_spaces FROM board WHERE board_dice_value IS NULL GROUP BY board_player";
        $result = $this->DbQuery($sql);
        $counts = [];
        foreach ($result as $row) {
            $counts[] = (int)$row['empty_spaces'];
        }

        // get whichever is lower
        $min_empty = min($counts);

        // Calculate the game progression based on the minimum empty spaces.
        return (1 - ($min_empty / 9)) * 100;
    }

    public function upgradeTableDb($from_version)
    {
        //       if ($from_version <= 1404301345)
        //       {
        //            // ! important ! Use `DBPREFIX_<table_name>` for all tables
        //
        //            $sql = "ALTER TABLE `DBPREFIX_xxxxxxx` ....";
        //            $this->applyDbUpgradeToAllDB( $sql );
        //       }
        //
        //       if ($from_version <= 1405061421)
        //       {
        //            // ! important ! Use `DBPREFIX_<table_name>` for all tables
        //
        //            $sql = "CREATE TABLE `DBPREFIX_xxxxxxx` ....";
        //            $this->applyDbUpgradeToAllDB( $sql );
        //       }
    }

    protected function getAllDatas(): array
    {
        $result = [];

        // WARNING: We must only return information visible by the current player.
        $current_player_id = (int) $this->getCurrentPlayerId();

        $result['roll'] = intval($this->getStat("dice-val", $current_player_id));

        // Get information about players.
        // NOTE: you can retrieve some extra field you added for "player" table in `dbmodel.sql` if you need it.
        $players = $this->getCollectionFromDb(
            "SELECT `player_id` `id`, `player_score` `score` FROM `player`"
        );

        $result['players'] = $players;

        $result['board'] = $this->getObjectListFromDB("SELECT board_col col, board_row row, board_player player, board_dice_value dice_value
                                                       FROM board");

        // for players
        foreach ($players as $player_id => $player) {
            $result["$player_id-score"] = [];
            for ($i = 1; $i <= 3; $i++) {
                $result["$player_id-score"][$i] = $this->getStat('col-' . $i . '-score', $player_id);
            }
        }

        // TODO: Gather all information about current game situation (visible by player $current_player_id).

        return $result;
    }

    protected function setupNewGame($players, $options = [])
    {
        // Set the colors of the players with HTML color code. The default below is red/green/blue/orange/brown. The
        // number of colors defined here must correspond to the maximum number of players allowed for the gams.
        $gameinfos = $this->getGameinfos();
        $default_colors = $gameinfos['player_colors'];

        foreach ($players as $player_id => $player) {
            // Now you can access both $player_id and $player array
            $query_values[] = vsprintf("('%s', '%s', '%s', '%s', '%s')", [
                $player_id,
                array_shift($default_colors),
                $player["player_canal"],
                addslashes($player["player_name"]),
                addslashes($player["player_avatar"]),
            ]);
        }

        // Create players based on generic information.
        //
        // NOTE: You can add extra field on player table in the database (see dbmodel.sql) and initialize
        // additional fields directly here.
        static::DbQuery(
            sprintf(
                "INSERT INTO player (player_id, player_color, player_canal, player_name, player_avatar) VALUES %s",
                implode(",", $query_values)
            )
        );

        $this->reattributeColorsBasedOnPreferences($players, $gameinfos["player_colors"]);
        $this->reloadPlayersBasicInfos();

        // Init global values with their initial values.

        // Init game statistics.
        $this->initStat('player', 'col-1-score', 0);
        $this->initStat('player', 'col-2-score', 0);
        $this->initStat('player', 'col-3-score', 0);
        $this->initStat('player', 'dice-val', rand(1, 6));

        // TODO: Setup the initial game situation here.
        $sql = "INSERT INTO board (board_player, board_col, board_row) VALUES ";
        $values = [];
        foreach ($players as $player_id => $player) {
            for ($x = 1; $x <= 3; $x++) {
                for ($y = 1; $y <= 3; $y++) {
                    // Now you can access both $player_id and $player array
                    $values[] = vsprintf("('%s', '%s', '%s')", [
                        $player_id,
                        $x,
                        $y,
                    ]);
                }
            }
        }
        $sql .= implode(',', $values);
        $this->DbQuery($sql);

        // Activate first player once everything has been initialized and ready.
        $this->activeNextPlayer();
    }

    protected function zombieTurn(array $state, int $active_player): void
    {
        $state_name = $state["name"];

        if ($state["type"] === "activeplayer") {
            switch ($state_name) {
                default: {
                        $this->gamestate->nextState("zombiePass");
                        break;
                    }
            }

            return;
        }

        // Make sure player is in a non-blocking status for role turn.
        if ($state["type"] === "multipleactiveplayer") {
            $this->gamestate->setPlayerNonMultiactive($active_player, '');
            return;
        }

        throw new \feException("Zombie mode not supported at this game state: \"{$state_name}\".");
    }
}
