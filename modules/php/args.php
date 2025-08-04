<?php

trait ArgsTrait
{
    public function argPlayerTurn(): array
    {
        // Get some values from the current game situation from the database.

        return [
            "playableCols" => $this->getPossibleCols(intval($this->getActivePlayerId())),
        ];
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
    
}
