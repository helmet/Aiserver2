<?php
/*
 * AIServer 2
 * ---
 * (C) 2010 Patrick Mennen <helmet@helmet.nl>
 * ---
 * view the README for more information regarding the functions and extra commands
 * and instructions on how to work with this piece of software
*/
require('aiserver.class.php');
require('holdem.php');

# Poker rules: 4 players, $24 amount of cash, big blind $2, small blind $1, a maximum of 1000 hands
$poker = new poker; // Start the Poker module
$poker->setRules(4, 10, 2, 1, 1000);
?>