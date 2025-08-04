<?php

declare(strict_types=1);

namespace Bga\Games\Knucklebones;

class Game extends \Bga\GameFramework\Table
{
    public function __construct()
    {
        parent::__construct();

        $this->initGameStateLabels([]);

        /* example of notification decorator.
        // automatically complete notification args when needed
        $this->notify->addDecorator(function(string $message, array $args) {
            if (isset($args['player_id']) && !isset($args['player_name']) && str_contains($message, '${player_name}')) {
                $args['player_name'] = $this->getPlayerNameById($args['player_id']);
            }
        
            if (isset($args['card_id']) && !isset($args['card_name']) && str_contains($message, '${card_name}')) {
                $args['card_name'] = self::$CARD_TYPES[$args['card_id']]['card_name'];
                $args['i18n'][] = ['card_name'];
            }
            
            return $args;
        });*/
    }

    public function actPlaceDice(int $col): void
    {
        // Retrieve the active player ID.
        $player_id =  intval($this->getActivePlayerId());

        // check input values
        $args = $this->argPlayerTurn();
        $playableCols = $args['playableCols'];

        $roll = intval($this->getStat('dice-val', $player_id));

        if (!in_array($col, $playableCols)) {
            throw new \BgaUserException('Invalid Dice Placement');
        }

        $this->placePlayerDice($player_id, $roll, $col);
        $otherPlayerId = array_values(array_diff($this->getPlayersIds(), [$player_id]))[0];
        $this->reactToDicePlacement($otherPlayerId, $col, $roll);

        // at the end of the action, move to the next state
        $this->gamestate->nextState("placeDice");
    }

    public function argPlayerTurn(): array
    {
        // Get some values from the current game situation from the database.

        return [
            "playableCols" => $this->getPossibleCols(intval($this->getActivePlayerId())),
        ];
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

    public function stNextPlayer(): void
    {
        $prev_player_id = (int)$this->getActivePlayerId();
        $player_id = intval($this->activeNextPlayer());

        $result = $this->DbQuery("SELECT COUNT(*) AS empty_spaces FROM board WHERE board_player = $prev_player_id AND board_dice_value IS NULL");
        $prev_player_empty_spaces = [];
        foreach ($result as $row) {
            $prev_player_empty_spaces[] = (int)$row['empty_spaces'];
        }

        // If the prev player has no empty spaces, end the game
        if ($prev_player_empty_spaces[0] === 0) {
            $this->notify->all("endGame", clienttranslate('The game ends because ${player_name} has no empty spaces left.'), [
                "player_id" => $prev_player_id,
                "player_name" => $this->getActivePlayerName(), // remove this line if you uncomment notification decorator
            ]);

            // Go to the end game state
            $this->gamestate->nextState("endGame");
            return;
        } else {
            // Notify all players about the next player.
            $this->notify->all("nextPlayer", clienttranslate('${player_name} is the next player.'), [
                "player_id" => $player_id,
                "player_name" => $this->getActivePlayerName(),
            ]);

            $this->rollDice($player_id);
            $this->giveExtraTime($player_id);
            $this->gamestate->nextState("nextTurn");
        }
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

    function getPossibleCols(int $player_id): array
    {
        $sql = "SELECT board_col AS col FROM board WHERE board_player = $player_id AND board_dice_value IS NULL";
        $result = $this->DbQuery($sql);
        $cols = [];
        foreach ($result as $row) {
            $cols[] = (int)$row['col'];
        }

        return array_unique($cols);
    }

    function setNewScore(int $player_id, int $col): array
    {
        $sql = "SELECT board_row, board_dice_value FROM board WHERE board_player = $player_id AND board_col = $col AND board_dice_value IS NOT NULL";
        $result = $this->getCollectionFromDB($sql);
        $intDiceVals = [];
        foreach ($result as $row) {
            $intDiceVals[] = intval($row['board_dice_value']);
        }

        $colScore = 0;
        $seen = [];
        foreach ($intDiceVals as $val) {
            if (!in_array($val, $seen)) {
                $count = array_count_values($intDiceVals)[$val];
                if ($count > 1) {
                    $colScore += pow($val,$count);
                } else {
                    $colScore += $val;
                }
                $seen[] = $val;
            }
        }

        $this->setStat($colScore, "col-{$col}-score", $player_id);

        $totalScore = 0;
        for ($i = 1; $i <= 3; $i++) {
            $totalScore += $this->getStat("col-{$i}-score", $player_id);
        }

        $sql = "UPDATE player SET player_score = $totalScore WHERE player_id = $player_id";
        $this->DbQuery($sql);

        // return object containing colscore and total score
        return [
            'col' => $colScore,
            'total' => $totalScore
        ];
    }

    function placePlayerDice($player_id, int $dice_val, int $col): void
    {
        $sql = "SELECT board_row FROM board WHERE board_col = $col AND board_player = $player_id AND board_dice_value IS NULL ORDER BY board_row ASC LIMIT 1";
        $result = $this->DbQuery($sql);
        $rows = [];
        foreach ($result as $row) {
            $rows[] = (int)$row['board_row'];
        }

        //Set in first empty row
        $sql = "UPDATE board SET board_dice_value = $dice_val WHERE board_col = $col AND board_player = $player_id AND board_row = $rows[0]";
        $this->DbQuery($sql);

        $playerScore = $this->setNewScore($player_id, $col);

        $this->notify->all("placeDice", clienttranslate('${player_name} places ${dice_val} dice in col ${col}'), [
            "player_id" => $player_id,
            "player_name" => $this->getActivePlayerName(), // remove this line if you uncomment notification decorator
            "dice_val" => $dice_val,
            "col" => $col,
            "player_score" => $playerScore
        ]);
    }

    function reactToDicePlacement(int $player_id, int $col, int $dice_val): void
    {
        $sql = "SELECT * FROM board WHERE board_col = $col AND board_player = $player_id AND board_dice_value IS NOT NULL";
        $result = $this->getCollectionFromDB($sql);

        if (count($result) == 0) {
            return;
        }

        $sql = "UPDATE board SET board_dice_value = NULL WHERE board_col = $col AND board_player = $player_id AND board_dice_value = $dice_val";
        $this->DbQuery($sql);

        $playerScore = $this->setNewScore($player_id, $col);

        $this->notify->all("loseDice", clienttranslate('${player_name} loses all their ${dice_val}\'s in col ${col}'), [
            "player_id" => $player_id,
            "player_name" => $this->getActivePlayerName(),
            "dice_val" => $dice_val,
            "col" => $col,
            "player_score" => $playerScore
        ]);
    }

    function getPlayersIds()
    {
        return array_keys($this->loadPlayersBasicInfos());
    }

    function rollDice(int $player_id)
    {
        $roll = rand(1, 6);
        $this->notify->all("rollDice", clienttranslate('${player_name} rolls a ${dice_val}!'), [
            "player_id" => $player_id,
            "player_name" => $this->getActivePlayerName(),
            "dice_val" => $roll
        ]);

        $this->setStat($roll, "dice-val", $player_id);
    }

    function debug_playToEndGame()
    {
        while (intval($this->gamestate->state_id()) < ST_END_GAME) {
            $state = intval($this->gamestate->state_id());
            switch ($state) {
                case ST_PLAYER_PLACE_DICE:
                    $args = $this->argPlayerTurn();
                    $possibleMoves = $args['playableCols'];
                    $dice_val = 6;

                    $this->actPlaceDice($possibleMoves[0]);
                    break;
            }
        }
    }
}
