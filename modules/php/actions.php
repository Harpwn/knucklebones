<?php

trait ActionTrait
{

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
            "dice_val" => $dice_val,
            "col" => $col,
            "player_score" => $playerScore
        ]);
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
                    $colScore += pow($val, $count);
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
}
