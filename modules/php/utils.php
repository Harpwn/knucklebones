<?php

trait UtilsTrait
{
    function getPlayersIds()
    {
        return array_keys($this->loadPlayersBasicInfos());
    }
}
