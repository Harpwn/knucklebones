<?php

trait StatesTrait
{
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
}
