<?php
require('aiserver.class.php');
require('holdem.php');

# Poker rules: 2 players, $25, big blind $2, small blind $1, a maximum of 100 hands
$poker = new poker;
$poker->setRules(2, 50, 2, 1, 100);
?>