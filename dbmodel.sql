CREATE TABLE IF NOT EXISTS `board` (
  `board_dice_value` SMALLINT(5) UNSIGNED DEFAULT NULL,
  `board_col` SMALLINT(5) UNSIGNED NOT NULL,
  `board_row` SMALLINT(5) UNSIGNED NOT NULL,
  `board_player` INT(10) UNSIGNED NOT NULL,
  PRIMARY KEY (`board_player`, `board_col`, `board_row`)
) ENGINE=InnoDB;