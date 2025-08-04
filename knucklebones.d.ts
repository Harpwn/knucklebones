interface KnucklebonesPlayer extends Player {
}

interface KnucklebonesGamedatas extends Gamedatas<KnucklebonesPlayer> {
    roll: number,
    board: { col: number, row: number, player: string, dice_value: number}[]
}
