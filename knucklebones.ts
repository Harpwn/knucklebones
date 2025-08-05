// @ts-ignore
GameGui = (function () {
  // this hack required so we fake extend GameGui
  function GameGui() {}
  return GameGui;
})();

// Note: it does not really extend it in es6 way, you cannot call super you have to use dojo way
class Knucklebones extends GameGui<KnucklebonesGamedatas> {
  constructor() {
    super();
  }

  public setup(gamedatas: KnucklebonesGamedatas) {
    this.sounds.load('dice_roll', 'rollDie');

    console.log("Starting game setup");
    console.log("gamedatas", gamedatas);

    // Example to add a div on the game area
    this.getGameAreaElement().insertAdjacentHTML(
      "beforeend",
      `<div id="game-area-container">
      </div>`
    );

    // get player and enemy
    const player = gamedatas.players[this.player_id];
    const enemy = Object.values(gamedatas.players).find(
      (p) => p.id !== this.player_id.toString()
    );

    this.setupPlayerBoard(gamedatas, enemy);
    document
      .getElementById("game-area-container")
      .insertAdjacentHTML("beforeend", `<div id="game-area-divider"></div>`);
    this.setupPlayerBoard(gamedatas, player);

    document
      .querySelectorAll(":not(.enemy-player-area) .player-area-col")
      .forEach((col) =>
        col.addEventListener("click", (e: MouseEvent) => this.onPlaceDice(e))
      );

    //Setup game notifications to handle (see "setupNotifications" method below)
    this.setupNotifications();

    console.log("Ending game setup");
  }

  public onEnteringState(stateName: string, args: any) {
    console.log("Entering state: " + stateName, args);
    switch (stateName) {
      case "playerTurn":
        if (this.isCurrentPlayerActive()) {
          const { playableCols } = args.args;
          const possibleMoves = playableCols || [];
          Object.values(possibleMoves).forEach((col) => {
            document
              .getElementById(`${this.player_id}_${col}`)
              .classList.add("possible-move");
          });
        }
        break;

      case "endGame":
        break;
    }
  }

  public onLeavingState(stateName: string) {
    console.log("Leaving state: " + stateName);
    switch (stateName) {
      case "playerTurn":
        document
          .querySelectorAll(".possible-move")
          .forEach((div) => div.classList.remove("possible-move"));
        break;

      case "endGame":
        break;
    }
  }

  public onUpdateActionButtons(stateName: string, args: any) {}

  public setupNotifications() {
    console.log("notifications subscriptions setup");
    this.bgaSetupPromiseNotifications();
  }

  public async notif_placeDice(args) {
    const {
      player_id: playerid,
      dice_val,
      col,
      player_score: { col: player_score_col, total: player_score_total },
    } = args;

    // Remove current possible moves (makes the board more clear)
    document
      .querySelectorAll(".possible-move")
      .forEach((div) => div.classList.remove("possible-move"));

    await this.addDiceToBoard(playerid, col, dice_val);
    this.setColScore(playerid, col, player_score_col);
    this.setTotalScore(playerid, player_score_total);
  }

  public async notif_loseDice(args) {
    const {
      player_id: playerid,
      dice_val,
      col,
      player_score: { col: player_score_col, total: player_score_total },
    } = args;

    await this.removeDiceFromBoard(playerid, col, dice_val);

    this.setColScore(playerid, col, player_score_col);
    this.setTotalScore(playerid, player_score_total);
  }

  public async notif_rollDice(args) {
    const { player_id: playerid, dice_val } = args;

    this.setDiceRoll(playerid, dice_val);

    //Dice roll anim
  }

  private setupPlayerBoard(
    gamedatas: KnucklebonesGamedatas,
    player: KnucklebonesPlayer
  ) {
    if (!gamedatas.board) return;
    const colScores = gamedatas[`${player.id}-score`];
    const isPlayer = player.id == this.player_id.toString();
    let playerBoardArea = `
        <div id="player-area-${player.id}" class="${
      isPlayer ? "my-player-area" : "enemy-player-area"
    } player-area"}">
        <div class="player-area-overview">
          <span class="player-area-text player-area-title">${player.name}</span>
          <span class="player-area-text player-area-score">${player.score}</span>
          <div class="player-area-dice-tray">
            <div class="player-area-dice dice">
            </div>
          </div>
        </div>
      <div class="player-area-inner">`;

    for (var i = 1; i <= 3; i++) {
      playerBoardArea += `
        <div id="${
          player.id
        }_${i}" class="player-area-col"><h1 class="player-area-col-title">${colScores[i]}</h1>
          ${this.setupCols(gamedatas, player, i)}
        </div>`;
    }

    playerBoardArea += `
          </div>
        </div>`;

    document
      .getElementById("game-area-container")
      .insertAdjacentHTML("beforeend", playerBoardArea);

    this.setDiceRoll(player.id, gamedatas.roll);
  }

  private setupCols(
    gamedatas: KnucklebonesGamedatas,
    player: KnucklebonesPlayer,
    col: number
  ) {
    let colBoardArea = "";
    for (var row = 1; row <= 3; row++) {
      const cell = gamedatas.board.find(
        (x) => x.col == col && x.row == row && x.player == player.id
      );
      const diceValue = cell.dice_value;

      if (diceValue) {
        const countForVal = gamedatas.board.filter(
          (x) =>
            x.col == col && x.player == player.id && x.dice_value == diceValue
        );
        const multiplier =
          countForVal.length > 2
            ? "dice-3x"
            : countForVal.length > 1
            ? "dice-2x"
            : "";

        colBoardArea += `
            <div data-value="${diceValue}" id="${player.id}_${col}_${row}" class="player-area-row">
              <span class="dice dice-${diceValue} ${multiplier}"></span>
            </div>`;
      } else {
        colBoardArea += `
            <div data-value="0" id="${player.id}_${col}_${row}" class="player-area-row">
            </div>
            `;
      }
    }

    return colBoardArea;
  }

  private async addDiceToBoard(
    player_id: string,
    col: number,
    dice_val: number
  ) {
    const colEle = document.getElementById(`${player_id}_${col}`);
    // Find empty row in the column
    const emptyRow = colEle.querySelectorAll(
      `.player-area-row[data-value="0"]`
    )[0];

    const newDiceId = `dice_${emptyRow.id}}`;

    const diceEle = document.getElementById(`player-area-${player_id}`).querySelector(".player-area-dice-tray .dice");

    //set empty row data value
    emptyRow.setAttribute("data-value", dice_val.toString());

    emptyRow.insertAdjacentHTML(
      "beforeend",
      `<span class="mobile-dice dice dice-${dice_val}" id="${newDiceId}"></span>`
    );
    this.placeOnObject(newDiceId, diceEle);
    this.fadeOutAndDestroy(diceEle, 10);

    const anim = this.slideToObject(newDiceId, emptyRow);
    await this.bgaPlayDojoAnimation(anim);

    this.setMultiplierEffect(col, dice_val, player_id);
  }

  private async removeDiceFromBoard(
    player_id: string,
    col: number,
    dice_val: number
  ) {
    const colElement = document.getElementById(`${player_id}_${col}`);
    const rowsToRemoveDiceFrom = colElement.querySelectorAll(
      `div[data-value="${dice_val}"]`
    );
    await rowsToRemoveDiceFrom.forEach(async (row) => {
      const dice = row.querySelector(".dice");
      await this.bgaPlayDojoAnimation(this.fadeOutAndDestroy(dice, 300));
      row.setAttribute("data-value", "0");
    });
  }

  private setColScore(player_id: string, col: number, score: number) {
    const colId = `${player_id}_${col}`;
    document
      .getElementById(colId)
      .querySelector(".player-area-col-title").textContent = score.toString();
  }

  private setTotalScore(player_id: string, score: number) {
    this.scoreCtrl[player_id].toValue(score);
    const playerArea = document.getElementById(`player-area-${player_id}`);
    playerArea.querySelector(".player-area-score").textContent = score.toString();
  }

  private setDiceRoll(player_id: string, roll: number) {
    //dice roll anim
    this.sounds.play("dice_roll");

    document.getElementById(`player-area-${player_id}`).querySelector(".player-area-dice-tray")
    .innerHTML = `<div class="player-area-dice dice dice-${roll}"></div>`;
  }

  private onPlaceDice(evt: MouseEvent) {
    console.log("onPlaceDice", evt);
    // Stop this event propagation
    evt.preventDefault();
    evt.stopPropagation();

    // The click does nothing when not active
    if (!this.isCurrentPlayerActive()) {
      return;
    }

    const elementTarget = evt.target as HTMLElement;

    // Get the cliqued square x and y
    // Note: square id format is "square_X_Y"
    var idSplit = elementTarget.id.split("_");
    var col = idSplit[1];

    if (!elementTarget.classList.contains("possible-move")) {
      // This is not a possible move => the click does nothing
      return;
    }

    this.bgaPerformAction("actPlaceDice", {
      col,
    });
  }

  private setMultiplierEffect(
    col: number,
    dice_val: number,
    player_Id: string
  ) {
    const colEle = document.getElementById(`${player_Id}_${col}`);
    const matchingDiceInRow = colEle.querySelectorAll(
      `.player-area-row[data-value="${dice_val}"] .dice`
    );
    matchingDiceInRow.forEach((dice) => {
      dice.classList.remove("dice-2x", "dice-3x");
      if (matchingDiceInRow.length > 1) {
        dice.classList.add(
          matchingDiceInRow.length > 2 ? "dice-3x" : "dice-2x"
        );
      }
    });
  }
}
