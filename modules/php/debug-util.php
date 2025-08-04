<?php

trait DebugUtilTrait
{
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
