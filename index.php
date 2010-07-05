<?php
require('aiserver.class.php');
require('holdem.php');

# Poker rules: 4 players, $10 amount of cash, big blind $2, small blind $1, a maximum of 1000 hands
$poker = new poker;
$poker->setRules(10, 25, 2, 1, 1000);
?>