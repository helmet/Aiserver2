<?php
require('aiserver.class.php');
require('holdem.php');

# Poker rules: 2 players, $5, big blind $2, small blind $1, a maximum of 1000 hands
$poker = new poker;
$poker->setRules(4, 10, 2, 1, 1000);
?>