<?php

/*
 * Basic player class holding variables not relevant to any game
*/
class player {
    public $id;
    public $name;
    public $socket = null;
    public $buffer = null;
}

/*
 * This is the Texas Hold`em poker ruleset for aiEngine version 2
*/

class poker extends aiv2 {
    public $spots = 0;
    public $start;
    public $starter = null;
    public $turn = 0;
    public $moves;
    public $playerblinds = array(0,0);
    public $bigblind;
    public $smallblind;
    public $totalgames = null;
    public $game = null;
    public $turntimer;
    public $gamecards;
    public $gamecardstable = array();
    public $deck;
    public $pot;
    public $maxraises;
    public $raises = null;
    public $bet = false;
    public $betamount = false;
    public $fold;
    public $out;
    public $playersingame;
    public $newgame = false;
    public $playercalls = array();

    public function __construct() {
        parent :: __construct();
        printf ("Loaded the Texas Hold `em Module succesfully!\n");

        # Generate the deck only has to be done once
        $types = array('spades', 'clubs', 'hearts', 'diamonds');
        $cards = array(2,3,4,5,6,7,8,9,10,'Jack', 'Queen', 'King', 'Ace');
        $deck = array();
        foreach($types as $type) {
            reset($cards);
            foreach ($cards as $card) {
                $deck[] = sprintf("%s of %s", $card, $type);
            }
        }
        $this->deck = $deck;
    }

    // Heartbeat
    // Checks if a player takes too long about his turn or whenever we can start the
    // game
    public function heartbeat() {
        // This is where the game logic is done, whenever the game is full
        // Start a new game
        if ( ($this->spots == $this->connections) && $this->game == 0) {
            $this->startgame();
        }

        // Time the player's turns
        if ($this->turn) {
            $this->turntimer++;
            if ($this->turntimer == 5) {
                /*
                 * 20 seconds have passed, player is forced to fold
                */
                $this->turntimer = 0;
                $player = $this->players[$this->turn];
                $this->broadcast(sprintf("Player %d has waited too long and is forced to fold!", $player->id));
                $this->playerFold($player);
            }
        }

        // New game ?
        if ($this->newgame == true) {
            $this->startgame();
            $this->newgame = false;
        }
    }


    /*
     *  checkstraight
     *  checks if the given array of cards contains a straight
    */

    function checkstraight(array $cards) {
        $am = count($cards);
        $possible = $am - 4;
        $straightcards = array();
        if ($possible == 0) {
            return false;
        }
        # Make sure our array doesn't go out of bounds
        for ($i = 0; $i < $possible; $i++) {
            $start = $cards[$i];
            $straightcards = array();
            $straightcards[] = $start;
            $expect = $cards[$i] -1;
            // We need four cards to match below the card we have
            for ($j = 1; $j <= 4; $j++) {
                $card = $cards[$j + $i];
                if ($expect == $card) {
                    $expect = $expect -1;
                    $straightcards[] = $card;
                    # We have a straight, return the cards
                    if ($j == 4) {
                        return $straightcards;
                    }
                }
                else {
                    # This starter did not attain a straight
                    break;
                }
            }
        }
        return false;
    }

    /*
        * Calcpoker returns a score based on the deck of cards entered
        * into the function!
    */

    function calcPoker(array $cards) {
        $myCards = array();
        $myColors = array('spades' => 0, 'diamonds' => 0, 'hearts' => 0, 'clubs' => 0);
        $sortCards = array('spades' => array(), 'diamonds' => array(), 'hearts' => array(), 'clubs' => array());
        $score = 0;

        // The points one could gain for such an interesting event :-)
        $straight_flush = 180;
        $four_of_a_kind = 140;
        $fullhouse = 120;
        $flush = 100;
        $straight = 80;
        $three_pair = 40;
        $two_pair = 20;
        $one_pair = 10;
        $high_card = 0;

        # Analyse data given as input
        foreach ($cards as $card) {
            $card = explode(" ", $card);
            $data = $card[0];
            $type = strtolower($card[2]);

            switch ($data) {
                // No problems with normal cards
                case 10:
                case 9:
                case 8:
                case 7:
                case 6:
                case 5:
                case 4:
                case 3:
                case 2:
                    $sortCards[$type][] = $data;
                    $myCards[] = $data;
                    break;
                // Give a numeric value to king, queen and jack
                case 'King':
                case 'Queen':
                case 'Jack':
                    $values = array('Jack' => 11, 'Queen' => 12, 'King' => 13);
                    $myCards[] = $values[$data];
                    $sortCards[$type][] = $values[$data];
                    break;

                // Ace is a smart brother, he can count as both 1 and 14
                case 'Ace':
                    $myCards[] = 1;
                    $myCards[] = 14;
                    $sortCards[$type][] = 14;
                    break;

            }
            $myColors[$type]++;
        }

        # Reverse sort cards so we actually have the highest cards first
        rsort($myCards);
        $tmp = $paired = array_count_values($myCards);

        // Flush?
        foreach ($sortCards as $key => $value) {
            $tempArray = $sortCards[$key];
            if (count($tempArray) >= 5) {
                $flushcards = $sortCards[$key];
                rsort($flushcards);
                # Check for a straight flush
                $check = $this->checkStraight($flushcards);
                if (!$check) {
                    $score += $straight;
                    for ($i = 0; $i < 5; $i++) {
                        $score += ($flushcards[$i] / 100);
                        $flushscore = $score;
                    }
                }
                else {
                    // Straight flush!
                    $added = array_sum($check) /100;
                    $score += $straight_flush + $added;
                    echo "Got a straight flush! score ({$score})\n";
                    # Highest possible score, we can return here!
                    return $score;
                }
            }
        }


        // Four of a kind?
        if (in_array(4, $paired)) {
            $card = array_search(4, $paired);
            $score += $four_of_a_kind + (4 * ($card /100));
            // Find the high card
            for ($i = 0; $i <= 5; $i++) {
                if ($myCards[$i] != $card) {
                    $score += $myCards[$i] / 1000;
                    break;
                }
            }
            echo "Four of a kind: {$score}\n";
            return $score;
        }

        // Full house
        $three = 0;
        $pair = 0;
        foreach ($tmp as $card => $amount) {
            switch ($amount) {
                case 1:
                    unset($tmp[$card]);
                    break;

                case 2:
                    if ($card != 1) {
                        $pair++;
                    }
                    break;

                case 3:
                    $three++;
                    // If we already had a three of a kind, the two of three
                    // cards might still be viable for a higher pair!
                    if ($three > 1 && $card != 1) {
                        $tmp[$card]--;
                        $pair++;
                    }
                    break;
            }
        }
        if ($three >= 1 && $pair >= 1) {
            $scorethree = 0;
            $scorepair = 0;
            reset ($tmp);
            foreach ($tmp as $card => $amount) {
                # Find the highest pair
                if ($scorepair == 0 && $amount == 2) {
                    $scorepair = $card;
                }
                # And the highest three of a kind
                if ($scorethree == 0 && $amount == 3) {
                    $scorethree = $card;
                }
            }
            $score += $fullhouse + ( ((3*$scorethree) + (2*$scorepair)) /100 );
            echo "Got a full house {$scorethree} and {$scorepair} ({$score})\n";
            return $score;
        }

        # We have a flush, at this moment this is the highest we can attain
        if (isSet($flushscore) && !empty($flushscore)) {
            echo "Flush with score: {$flushscore}\n";
            return $flushscore;
        }

        # Straight
        $cards = $this->checkstraight($myCards);
        if ($cards) {
            $score += $straight;
            $add = array_sum($cards) / 100;
            $score += $add;
            echo "Normal straigth ({$score})\n";
            return $score;
        }

        # Three of a kind
        if ($three == 1) {
            $highcard = array();
            $gotcards = 0;
            $threes = 0;
            foreach ($myCards as $card) {
                # Is our card a high card that needs to be taken into account?
                if ($paired[$card] == 1 && $gotcards != 2) {
                    $highcard[$gotcards] = $card;
                    $gotcards++;
                }
                # Is this the three of a kind?!
                if ($paired[$card] == 3 && !$threes) {
                    $threes = $card;
                }
            }
            $score = $three_pair;
            $add = (($threes * 3)  + ($highcard[0] + $highcard[1])) /100;
            $score += $add;
            echo "Got three of a kind with score {$score}\n";
            return $score;
        }

        # Two pair
        if ($pair == 2) {
            $highcard = 0;
            $pairs = 0;
            $pairs = array();
            foreach ($myCards as $card) {
                if ($paired[$card] == 2 && $pairs != 2) {
                    $pairs++;
                    $pairs[] = $card;
                }

                else {
                    if ($highcard == 0) {
                        $highcard = $card;
                    }
                }
            }
            $score = $two_pair;
            $add = ((array_sum($pairs) *20) + $highcard) /100;
            $score += $add;
            echo "Got two pair with score {$score}\n";
            return $score;
        }

        # One pair
        if ($pair == 1) {
            $highcards = array();
            $hc = 0;
            $pair = 0;
            foreach ($myCards as $card) {
                if ($paired[$card] ==1 && $hc != 3) {
                    $hc++;
                    $highcards[] = $card;
                }
                if ($paired[$card] == 2 && !$pair) {
                    $pair = $card;
                }
            }
            $score = $one_pair;
            $add = (array_sum($highcards) + ($pair *20)) / 100;
            $score += $add;
            echo "Got a pair with score: {$score}\n";
            return $score;
        }

        # High card
        $score = $high_card;
        $score += $myCards[0] /100;
        echo "Got a high card with score: {$score}\n";
        return $score;
    }


    /*
     * setRules
     * Sets up the rules and the amount of hands played for each game!
    */
    public function setRules($players = 2, $playermoney  = 10, $bigblind = 1, $smallblind = .5, $games =5, $maxraises = 3) {
        if ($players < 2) {
            printf("I'm sorry to report that Texas Hold `em requires at least 2 players.\n");
            return;
        }
        if ($players > 10) {
            printf("I am sorry to report that the maximum amount of players for Texas Hold `em is 10\n");
            return;
        }
        $this->spots = $players;
        $this->playermoney = $playermoney;
        $this->bigblind = $bigblind;
        $this->smallblind = $smallblind;
        $this->totalgames = $games;
        $this->maxraises = $maxraises;
        # Have the server accept recipients
        parent :: acceptclients($players);
    }

    public function addplayer($playerid) {
        $player = new pokerplayer();
        $player->name = null;
        $player->id = $playerid;
        $player->money = $this->playermoney;
        $this->players[$playerid] = $player;
        if ($this->spots != $this->connections) {
            // Provided the connected members with a message that our game is waiting for x more player(s)
            $required = $this->spots - $this->connections;
            parent :: broadcast(sprintf("[SERVER] The game is waiting for %d more player%s", $required, $required != 1 ? 's' : ''));
        }
        return $player;
    }

    public function disconnect($player) {
        // Cancel the game
        parent :: disconnect($player);
        $required = $this->spots - $this->connections;
        parent :: broadcast(sprintf("[SERVER] The game is waiting for %d more player%s", $required, $required != 1 ? 's' : ''));
        $this->turn = 0;
    }

    public function welcome($player) {
        if (!isSet($player->id)) {
            return false;
        }
        parent :: welcome($player);
        $this->send($player, "Texas Hold`em module - (C) 2010 Patrick Mennen <helmet@helmet.nl>\r\n");
        $this->send($player, "You are playing Texas Hold`em (Limit!) using the following rules:\r\n");
        $this->send($player, sprintf("- Players: %d\r\n- Big blind: %0.2f\r\n- Small blind: %0.2f\r\n- Games: %d\r\n\r\nYou receive $%0.2f\r\n", $this->spots, $this->bigblind, $this->smallblind, $this->totalgames,$this->playermoney));
        $this->send($player, sprintf("RS=%d,%0.2f,%0.2f,%0.2f,%d,%d", $this->spots, $this->playermoney, $this->bigblind, $this->smallblind, $this->totalgames, $this->maxraises));
    }

    /*
     * Winner
     * assigns the pot to the winner and starts a new game
    */
    private function winner($player) {
        # Player exists?
        if (!$player->id) {
            return false;
        }

        $this->broadcast(sprintf("Player %d has won the hand and won the pot of $%0.2f!", $player->id, $this->pot), $player);
        $player->money += $this->pot;
        $this->send($player, sprintf("You won the pot of $%0.2f and now have $%0.2f", $this->pot, $player->money));
        # If we are not at the amount of hands played, start a new game!
        if ($this->game <= $this->totalgames) {
            $this->newgame = true;
            # Put the new game command in the heartbeat timer because resetting the game
            # interfered with distribution of the pot and determining the winners
        }
    }

    /*
     * playerFold
     * is executed when a player folds or gets folded automatically by the system
    */
    private function playerFold($player) {
        $this->fold++;
        $this->broadcast(sprintf("P%d=FOLD", $player->id));
        $this->broadcast(sprintf("Player %d folds.", $player->id));
        $player->fold = true;
        #$this->players[$player->id] = $player;
        $players_left = $this->playersingame - $this->fold;
        if ($players_left == 1) {
            // We have a winner, based on the amount of folds, determine the player that
            // didn't fold
            foreach ($this->players as $pl) {
                if (!$pl->fold) {
                    $this->winner($pl);
                    break;
                }
            }
            return;
        }
        # We still have multiple players in the game
        $this->nextTurn();

    }

    /*
     * Overrides the default handlecommand function allowing the module to add it's own
     * new commands in this case: bet, raise, fold and check
    */
    public function handleCommand($player, $command, $args) {
        $orig = $command;
        $command = strtolower($command[0]);
        switch ($command) {
            case 'fold':
                if ($this->turn == $player->id) {
                    $this->playerFold($player);
                }
                else {
                    $this->send($player, "Sorry, it is not your turn yet!");
                }
                break;

            case 'call':
                if ($this->turn == $player->id) {
                    if ($this->bet) {
                        $bet = $this->getbet();
                        if ($player->money <= $bet) {
                            // Have to fix this function here, because I don't know
                            // what happens when the player can't afford the call
                            // suppose he/she has to go all-in

                        }
                        else {
                            $player->money -= $bet;
                            $this->pot+= $bet;
                            $this->broadcast(sprintf("Player %d CALLS the bet", $player->id));
                            $this->playercalls[$player->id] = true;
                            $this->nextTurn();
                        }
                    }
                    else {
                        $this->send($player, "There are no previous bets! would you like to BET?");
                    }
                }
                else {
                    $this->send($player, "Sorry, it is not your turn yet!");
                }
                break;
                break;

            case 'bet':
                if ($this->turn == $player->id) {
                    # Did some player already bet?
                    if ($this->bet) {
                        # Yes, provide a nice error message
                        $this->send($player, "Another player already made a bet!");
                        $this->send($player, "You can either CALL, RAISE or FOLD");
                    }
                    else {
                        # NO! place the bet
                        $bet = $this->getbet();
                        if ($player->money >= $bet) {
                            $this->bet = true;
                            $this->broadcast(sprintf("Player %d places a bet of $%0.2f", $player->id, $bet));
                            $player->money -= $bet;
                            $this->pot += $bet;
                            $this->resetplayercalls();
                            $this->playercalls[$player->id] = true;
                            $this->nextTurn();
                        }
                        else {
                            $this->send($player, "You can NOT afford this BET");
                        }
                    }

                }
                else {
                    $this->send($player, "Sorry, it is not your turn yet!");
                }
                break;

            case 'raise':
                if ($this->turn == $player->id) {
                    # No previous bets have been made, therefore the player can't bet in this turn
                    # as of yet!
                    if ($player->id == $this->playerblinds[0] && $this->moves == 1) {
                    }


                    if (!$this->bet) {
                        $this->send($player, "There are no previous bets! would you like to BET?");
                    }
                    else {
                        if ($this->raises == $this->maxraises)
                        {
                            $this->send($player, "Error: you can not raise this bet use either CALL or FOLD!");
                            break;
                        }
                        $money = $this->getbet();
                        # Player can afford the raise
                        if ($player->money >= $money) {
                            $this->betamount += $money;
                            $player->money -= $money;
                            $this->pot += $money;
                            $this->raises++;
                            $this->broadcast(sprintf("Player %d calls the current bet and raises with $%0.2f", $player->id, $money));
                            $this->resetplayercalls();
                            $this->playercalls[$player->id] = true;
                            $this->nextTurn();
                        }
                        else {
                            $this->send($player, "Sorry, you can not afford to RAISE");
                        }
                    }
                }
                else {
                    $this->send($player, "Sorry, it is not your turn yet!");
                }
                break;

            case 'check':
                if ($this->turn == $player->id) {

                    # We cannot check if somebody placed a bet in this turn :(
                    if ($this->bet) {
                        $this->send($player, "You can not check, because another player bet in this turn!");
                        $this->send($player, "You can only CALL, RAISE or FOLD");
                        return;
                    }

                    # Is the player the small blind and are we in the pre-flop phase?
                    if ($player->id == $this->playerblinds[0] && $this->moves == 1) {
                        $bet = $this->smallblind;
                        if ($player->money <= $bet) {
                            // Don't know yet
                        }
                        else {
                            $this->pot += $bet;
                            $player->money -= $bet;
                            $this->broadcast(sprintf("Player %d CHECKS and automatically called the big blind", $player->id));
                            $this->playercalls[$player->id] = true;
                            $this->nextTurn();
                        }
                        return;
                    }

                    $player->checks++;
                    $this->broadcast(sprintf("P%d=CHECK", $player->id));
                    $this->broadcast(sprintf("Player %d checks", $player->id));
                    $this->playercalls[$player->id] = true;
                    $this->nextTurn();
                }
                else {
                    $this->send($player, "Sorry, it is not your turn yet!");
                }
                break;

            default:
            # Module doesn't know the command, fall back to the original commands
            # as specified in the aiv2 module, don't you just love parent functions :-)
                parent :: handleCommand($player, $orig, $args);
                break;
        }
    }

    /*
     * getbet
     * Returns the amount of BET that is used for the current turn
    */
    public function getbet() {
        $money = false;
        switch ($this->moves) {
            case 1:
            case 2:
                $money = $this->smallblind;
                break;
            case 3:
            case 4:
                $money = $this->bigblind;
        }
        return $money;
    }


    /*
     * Gets the next player that is still in the game
    */
    public function getNextPlayer($id) {
        // Whenever no blind is set e.g. new game
        if ($id == 0) {
            if (!$this->playerblinds[0]) {
                return $this->players[1];
            }
            else {
                return $this->players[2];
            }
        }
        $before = $id -1;
        // Get the players after
        for ($i = $id; $i <= $this->connections; $i++) {
            $player = $this->players[$i];
            # if the player is in the game and NOT this player
            if (!$player->out && $player->id != $id) {
                return $player;
                break;
            }
        }
        // Get the players before
        if ($before > 0) {
            for ($i = 1; $i < $id; $i++) {
                $player = $this->players[$i];
                # if the player is in the game and NOT this player
                if (!$player->out && $player->id != $id) {
                    return $player;
                    break;
                }

            }
        }
        return false;
    }

    public function resetgame() {
        // Resets a game, removing bets and pots
        $this->fold = 0;
        $this->pot = 0;
        $this->moves = 0;
        $this->raises = 0;
        $this->bet = false;
        $this->betamount = null;
        $this->playersingame = $this->connections;
        $this->turntimer = 0;
        # Remove any previous folds
        foreach ($this->players as $i => $pl) {
            $pl->fold = false;
            $pl->cards = array();
        }
        $this->resetplayercalls();
    }

    public function resetplayercalls() {
        foreach ($this->players as $i => $pl) {
            if ($pl->fold || $pl->out) {
                $this->playercalls[$i] = true;
            }
            else {
                $this->playercalls[$i] = false;
            }
        }
    }


    public function startgame() {
        $this->game++;
        $this->resetgame();
        if ($this->game == 1) {
            $this->broadcast("Started Texas Hold`em AI Tournament\r\nGood luck to all the contestants!");
            $this->playerblinds = array(0,0);
        }

        $this->broadcast(sprintf("Playing hand: %d/%d (%2.0f%%)", $this->game, $this->totalgames, ($this->game / $this->totalgames) * 100));
        $i = 0;

        while ($i < 2) {
            $player = $this->getNextPlayer($this->playerblinds[$i]);
            $blind = $i == 0 ? $this->smallblind : $this->bigblind;
            if ($player->money >= $i) {
                $player->money -= $blind;
                $this->pot += $blind;
                $this->broadcast("SB=%d", $player->id);
                $this->broadcast(sprintf("Player %d has paid the %s blind", $player->id, $i == 0 ? 'SMALL' : 'BIG'), $player);
                $this->send($player, sprintf("You paid the %s blind and have $%0.2f left", $i == 0 ? 'SMALL' : 'BIG', $player->money));
                $this->playerblinds[$i] = $player->id;
                $i++;
            }
            else {
                # remove the player from the game
                $this->out++;
                $player->out = true;
                if ($this->out == $this->playersingame -1) {
                    echo "We have a winner!";
                    break;
                }
            }
        }

        $this->turn = $this->starter = $this->playerblinds[0];
        $this->progress();
    }

    /*
     * sendplayer cards
     * sends each players the two cards he/she is holding
    */
    public function sendplayercards($message = "You hold the cards: %s") {
        foreach ($this->players as $player) {
            $this->send($player, sprintf($message, implode(', ', $player->cards)));
        }
    }

    /*
     * Progress
     * Puts the game in the next phase!
    */
    public function progress() {
        $this->moves++;
        $this->turntimer = 0;
        $this->bet = false;
        $this->raises = 0;
        $this->resetplayercalls();

        switch ($this->moves) {
            case 1:
            // Deal the cards to the players
                $this->gamecards = $this->deck;
                shuffle($this->gamecards);
                for ($i = 1; $i <= 2; $i++) {
                    for ($j = 1; $j <= $this->playersingame; $j++) {
                        $player = $this->players[$j];
                        $player->cards[] = $this->gamecards[0];
                        array_shift($this->gamecards);
                    }
                }
                break;

            case 2:
            // Deals the flop cards
                for ($i = 0; $i <= 3; $i++) {
                    switch ($i) {
                        case 0:
                            array_shift($this->gamecards);
                            break;
                        default:
                            $this->gamecardstable[] = $this->gamecards[0];
                            array_shift($this->gamecards);
                            break;
                    }
                }
                $this->broadcast(sprintf("The flop reveals: %s", implode(', ', $this->gamecardstable)));
                break;

            case 3:
            case 4:
                array_shift($this->gamecards);
                $this->gamecardstable[] = $this->gamecards[0];
                $this->broadcast(sprintf("The %s-card reveals: %s", $this->moves == 3 ? 'turn' : 'river', implode(', ', $this->gamecardstable)));
                break;

            case 5:
                $scores = array();
                foreach ($this->players as $pl) {
                    $playercards = array_merge($pl->cards, $this->gamecardstable);
                    $score = $this->calcPoker($playercards);
                    $scores[$pl->id] = $score;
                    $this->broadcast(sprintf("Player %d has a score of %0.2f", $pl->id, $score));
                }
                break;

        }
        $this->sendplayercards();
        $this->nextTurn();
    }

    /*
     * Select the next player that is in the game and sends the turn to him/her
    */
    public function nextTurn() {
        # Is the turn over?

        if (array_sum($this->playercalls) == $this->playersingame) {
            $this->progress();
            $this->turn = $this->starter;
            $pl = $this->players[$this->starter];
            if ($pl->out == true || $player->fold == true)
            {
                $player = $this->getNextPlayer($player->id);
            }
            return;
        }
        $player = $this->getNextPlayer($this->turn);
        $this->turn = $player->id;
        $this->turntimer = 0;
        # Tell the players that the turn belongs to $player->id
        $this->broadcast(sprintf("T=%d", $player->id));
        $this->send($player, "It is your turn.. you have 20 seconds to make a move!");
    }

}

/*
 * This is the Texas Hold`em poker player class and contains variables
 * that are only used in the Texas Hold `em game
*/
class pokerplayer extends player {
    public $money;
    public $fold = false;
    public $out = false;
    public $cards = array();
    // Statistical information is stored per player
    public $bets;
    public $folds;
    public $calls;
    public $raises;
}


/*
 * Class AIV2
 * This is the default engine allowing players to connect to the system
 * and providing a one channel chat time-line, games based on this class do
 * not need to worry about the socket stuff as this module handles it perfectly.
*/

class aiv2 {
    static $version = 2.05;
    public $connections;
    public $players = array();
    public $port = 22;
    public $bindip = '127.0.0.1';
    public $maxconnections = null;
    private $socket;
    public $check;

    /*
     * Constructor
    */
    public function __construct() {
        error_reporting(E_ALL);
        printf("This is AIEngine version %01.2f\n", self :: $version);
        printf("Attempting to create a listening socket on %s (port: %d)\n", $this->bindip, $this->port);
        $socket = $this->socket = @socket_create(AF_INET, SOCK_STREAM, 0);
        $listen = @socket_bind($socket, $this->bindip, $this->port);
        $server = @socket_listen($socket);
        if (!$socket || !$listen || !$server) {
            die("Could not create a TCP/IP socket... is the socket-extension loaded in PHP.ini?\n");
        }
        printf("Socket created succesfully, waiting for clients...\n");
    }

    /*
     * Wait for $amount clients before starting the event in question
    */
    public function acceptclients($amount = 5) {
        $this->maxconnections = $amount;
        while (true) {
            $read = array();
            $read[0] = $this->socket;

            # checks function is executed on every loop!
            // Reading from already connected clients
            for ($i = 1; $i <= $this->connections; $i++) {
                if (isSet($this->players[$i]) && is_object($this->players[$i])) {
                    $player = $this->players[$i];
                    if (isSet($player->socket) && !empty($player->socket)) {
                        $read[$i] = $player->socket;
                    }
                }
            }

            $ready = socket_select($read, $write = null, $except = null, 5);
            $this->checks();
            // Received a new connection!
            if (in_array($this->socket, $read)) {
                for ($i = 1; $i <= $this->maxconnections; $i++) {
                    if (!isSet($this->players[$i])) {
                        $this->connections++;
                        $player = $this->addplayer($i);
                        $player->socket = socket_accept($this->socket);
                        $this->players[$i] = $player;
                        $this->broadcast("Player {$i} has joined the server...", $player);
                        $this->welcome($player);
                        break;
                    }
                    elseif ($this->connections == $this->maxconnections) {
                        $remove = socket_accept($this->socket);
                        socket_write($remove, "AIEngine:\r\nYou have been removed of this server: The server is full!");
                        socket_close($remove);
                    }
                }
                if (--$ready <= 0) {
                    continue;
                }

            }

            // Handle data and commands sent by the clients
            foreach ($this->players as $id => $player) {
                if (isSet($player->socket) && in_array($player->socket, $read)) {
                    $data = socket_read($player->socket, 1096);
                    if ($data == null) {
                        $this->disconnect($player);
                        continue;
                    }
                    $player->buffer .= $data;
                    if ($data == "\n" || $data == "\r\n") {
                        $command = explode(' ', trim($player->buffer));
                        $args = array_slice($command,1 );
                        # The client actually sent a command, try to handle it!
                        if (!empty($command)) {
                            $this->handleCommand($player, $command, $args);
                            $player->buffer = null;
                        }
                    }
                }
            }
        }
    }

    /*
     * Heartbeat is called every 5 seconds (so 12 times each minute) and can be used
     * to perform timeout checks or other calculations on the server side! in this default
     * example it will just send the time to all players each minute
     *
     * Checks is a function to prevent the heartbeat executing on user input and is merely used
     * as an interval function
    */
    private function checks() {
        $now = strtotime('now');
        if ($now >= $this->check) {
            $this->heartbeat();
            $this->check = $now +5;
        }
    }

    // Just a container, this should be extended in the individual modules
    public function heartbeat() {

    }

    /*
     * Welcomes a new player and sends rules to the player (if specified by the module)
    */
    public function welcome($player) {
        if (!isSet($player->socket)) {
            return false;
        }
        $this->send($player, sprintf("Welcome to AIEngine, Player %d", $player->id));
        $this->send($player, "(C) 2010 Patrick Mennen <helmet@helmet.nl>");
    }


    /*
     * Sends a message to a specific player
    */
    public function send($player, $message) {
        if (!isSet($player->id) || !isSet($player->socket)) {
            return false;
        }
        socket_write($player->socket, $message . "\r\n");
    }


    /*
     * Broadcast a message to all players!
    */
    public function broadcast($message, $skip = null) {
        foreach($this->players as $player) {
            if (is_object($player) && isSet($player->socket) && $skip == null || (isSet($skip->id) && $skip->id != $player->id)) {
                socket_write($player->socket, $message . "\r\n");
            }
        }
    }

    /*
     * disconnect, MUST be called whenever a player is disconnected
    */
    public function disconnect($player) {
        if (!isSet($this->players[$player->id])) {
            return false;
        }
        else {
            $id = $player->id;
            if (isSet($player->socket)) {
                socket_close($player->socket);
                $player->socket = null;
            }
            unset($this->players[$player->id]);
            unset($player);
            $this->connections--;
            $this->broadcast(sprintf("Player %d has left the game", $id));
        }
    }

    /*
     * HandleCommand
     * ---
     * Default commands for the AIEngine platform
    */
    public function handleCommand($player, $command, $args) {
        $command = strtolower($command[0]);
        switch ($command) {
            case 'quit':
            case 'exit':
                $this->disconnect($player);
                break;
            case 'name':
                $name = trim(implode(" ", $args));
                if (!$name || empty($name)) {
                    $this->send($player, "Name: not enough parameters");
                }
                else {
                    $now = !empty($player->name) ? trim($player->name)  : "Player " . $player->id;
                    $player->name = $name;
                    $this->broadcast(sprintf("%s is now known as %s", $now, $name));
                }
                break;
            # If the command is unknown, we will send a chatmessage
            # This might come in handy when debugging certain parameters!
            default:
                $message = ucfirst($command) . ' ' . trim(implode(" ", $args));
                $name = !empty($player->name) ? trim($player->name)  : "Player " . $player->id;
                $this->broadcast(sprintf("<%s> %s", $name, $message));
                break;
        }
    }

    /*
     * Adds a default player to the game
    */
    public function addplayer($playerid) {
        $player = new player();
        $player->name = null;
        $player->id = $playerid;
        $this->players[$playerid] = $player;
        return $player;
    }
}

$poker = new poker;
# Poker rules: 2 players, $25, big blind $2, small blind $1, 100 hands and 3 maximal raises per turn
$poker->setRules(2, 25, 2, 1, 33, 3);
?>