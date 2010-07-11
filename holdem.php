<?php
/*
 * This is the Texas Hold`em poker ruleset for aiEngine version 2
 * ---
 * view the README for more information regarding the functions and extra commands
 * available in this module.
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
    public $bet = false;
    public $playersingame;
    public $moneylog = array();
    public $newgame = false;
    public $playercalls = array();
    public $return = 0;
    # Variables added for pots
    public $pot;
    public $playerpot = array();
    public $betamount;

    public function __construct() {
        parent :: __construct();
        printf ("Loaded the Texas Hold `em Module succesfully!\n");
        if (!is_writeable('logs')) {
            echo "Attempting to change the directory's permissions\n";
            chmod('logs', 777);
            if (!is_writable('logs')) {
                die("Fatal error: The logs directory is not writable");
            }
        }
        if (!function_exists('imagecreate')) {
            die ("Fatal error: Texas Hold `em requires the GD-library to be enabled in PHP.ini");
        }

        # Generate the deck only, this has to be done only once
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
        if ($this->return == true) {
            return;
        }
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
                $this->broadcast(sprintf("%s has waited too long and is forced to fold!", parent :: playerName($player)));
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
     * cardName
     * ---
     * Returns the name of the card based on the number (reversed calculation)
    */
    function cardName($card) {
        $card = intval($card);

        $return = array(1 => 'Ace', 14 => 'Ace', 2 => 'Two', 3 => 'Three', 4 => 'Four',
                5 => 'Five', 6 => 'Six', 7 => 'Seven', 8 => 'Eight', 9 => 'Nine',
                10 => 'Ten', 11 => 'Jack', 12 => 'Queen', 13 => 'King');
        return $return[$card];
    }

    /*
        * Calcpoker returns a score based on the deck of cards entered
        * into the function!
    */

    function calcPoker(array $cards, $player) {
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
                    $this->broadcast(sprintf("%s got a straight flush! score: %0.3f", parent :: playerName($player), $score));
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
            $this->broadcast(sprintf("%s got four of a kind! score: %0.3f\n", parent :: playerName($player), $score));
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
            $score += $fullhouse +  ( ((3*$scorethree) /100) + ((2*$scorepair)) /1000) ;
            $this->broadcast(sprintf("%s got a full house %ss and %ss score %0.3f", parent :: playerName($player), $this->cardname($scorethree), $this->cardname($scorepair), $score));
            return $score;
        }

        # We have a flush, at this moment this is the highest we can attain
        if (isSet($flushscore) && !empty($flushscore)) {
            $this->broadcast(sprintf("%s got a flush with score %0.3f", parent :: playerName($player), $flushscore));
            return $flushscore;
        }

        # Straight
        $cards = $this->checkstraight($myCards);
        if ($cards) {
            $score += $straight;
            $add = array_sum($cards) / 100;
            $score += $add;
            $this->broadcast(sprintf("%s got a straight with a score of %0.3f", parent :: playerName($player), $score));
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
            $this->broadcast(sprintf("%s got a three of a kind %s with score: %0.3f", parent :: playerName($player), $this->cardName($threes), $score));
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
            $bcMsg = array();
            foreach ($pairs as $value) {
                $bcMsg[] = sprintf('%s%s', $this->cardName($value), $value == 6 ? 'es' : "s");
            }
            $bcMsg = array_unique($bcMsg);
            $bcMsg = implode(" and ", $bcMsg);
            $this->broadcast(sprintf("%s got two pair %s with score %0.3f", parent :: playerName($player), $bcMsg, $score));
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
            $this->broadcast(sprintf("%s got a pair of %ss with score: %0.3f", parent :: playerName($player),$this->cardName($pair),$score));
            return $score;
        }

        # High card
        $score = $high_card;
        $score += $myCards[0] /100;
        $this->broadcast(sprintf("%s got high card %s with total score: %0.3f", parent :: playerName($player), $this->cardName($myCards[0]), $score));
        return $score;
    }


    /*
     * setRules
     * Sets up the rules and the amount of hands played for each game!
    */
    public function setRules($players = 2, $playermoney  = 10, $bigblind = 1, $smallblind = .5, $games = 1000) {
        if ($players < 2) {
            printf("I'm sorry to report that Texas Hold `em requires at least 2 players.\n");
            die();
        }
        if ($players > 10) {
            printf("I am sorry to report that the maximum amount of players for Texas Hold `em is 10\n");
            die();
        }
        $this->spots = $players;
        $this->playermoney = $playermoney;
        $this->bigblind = $bigblind;
        $this->smallblind = $smallblind;
        $this->totalgames = $games;
        # Have the server accept new players
        parent :: acceptclients($players);
    }

    public function addplayer($playerid) {
        $player = new pokerplayer();
        $name = sprintf('Player %d', $playerid);
        $player->name = $name;
        $this->names[] = $name;
        $player->id = $playerid;
        $player->money = $this->playermoney;
        $this->moneylog[$player->id] = array($player->money);
        $this->players[$playerid] = $player;
        if ($this->spots != $this->connections) {
            // Provide the connected members with a message that our game is waiting for x more player(s)
            $required = $this->spots - $this->connections;
            parent :: broadcast(sprintf("[SERVER] The game is waiting for %d more player%s", $required, $required != 1 ? 's' : ''));
        }

        $required = $this->spots - $this->connections;
        if ($this->return == true && $required == 0) {
            $this->restore();
        }
        return $player;
    }

    public function disconnect($player) {
        // Cancel the game and wait for x amount of new players
        $p = $player;
        parent :: disconnect($player);
        $required = $this->spots - $this->connections;
        parent :: broadcast(sprintf("[SERVER] The game is waiting for %d more player%s", $required, $required != 1 ? 's' : ''));
        $this->turn = 0;
        $this->return = true;
    }

    /*
     * Welcome
     * ---
     * Welcomes the player to the game and explains the rules
    */

    public function welcome($player) {
        if (!isSet($player->id)) {
            return false;
        }
        parent :: welcome($player);
        $this->send($player, "Texas Hold`em module - (C) 2010 Patrick Mennen <helmet@helmet.nl>\r\n");
        $this->send($player, "You are playing Texas Hold`em (Limit!) using the following rules:\r\n");
        $this->send($player, sprintf("- Players: %d\r\n- Big blind: %0.2f\r\n- Small blind: %0.2f\r\n- Games: %d\r\n\r\nYou receive $%0.2f\r\n", $this->spots, $this->bigblind, $this->smallblind, $this->totalgames,$this->playermoney));
        $this->send($player, sprintf("RS=%d,%0.2f,%0.2f,%0.2f,%d", $this->spots, $this->playermoney, $this->bigblind, $this->smallblind, $this->totalgames));
        $this->send($player, sprintf("ID=%d", $player->id));
    }

    /*
     * AddMoney
    */
    public function addMoney($player, $amount) {
        if ($player->money >= $amount) {
            // Player has sufficient funds, spread the money over the new pots created
            $this->pot += $amount;
            $player->money -= $amount;
            $this->playerpot[$player->id] += $amount;
            if ($player->money == 0) {
                $player->tmpout = true;
            }
        }
        else {
            // Player's money is not sufficient, create a new pot in the current game
            $this->pot += $player->money;
            $this->playerpot[$player->id] += $player->money;
            $player->money = 0;
            $player->tmpout = true;
        }
    }

    /*
     * PlayersLeft
     * returns the amount of players still playing in the current hand
    */
    public function playersLeft() {
        $i = 0;
        foreach($this->players as $pl) {
            if (!$pl->out && !$pl->fold && !$pl->tmpout) {
                $i++;
            }
        }
        return $i;
    }



    public function playersLeftArray() {
        $players = array();
        foreach ($this->players as $pl) {
            if (!$pl->out) {
                $players[] = $pl->id;
            }
        }
        return $players;
    }

    /*
     * Winner
     * assigns the pot to the winner and starts a new game
    */
    private function winner($scores) {
        $max = 0;
        $handout = array();
        $return = array();
        $wincount = 0;

        foreach ($this->players as $player) {
            if (!$player->out) {
                $handout[$player->id] = 0;
                $return[$player->id] = 0;
            }
        }
        while ($this->pot > 0) {
            $winners = array();
            $high = null;
            $j = 0;
            reset($scores);
            if (count($scores >= 0)) {
                foreach ($scores as $i => $score) {
                    if (!isSet($this->playerpot[$i]) || $this->playerpot[$i] == 0) {
                        continue;
                    }
                    $j++;
                    if ($j == 1) {
                        $high = $score;
                        $winners[] = $i;
                        continue;
                    }
                    if ($score != $high) {
                        break;
                    }
                    else {
                        $winners[] = $i;
                    }
                }
            }

            if (count($winners) != 0) {
                $split = $this->pot / count($winners);
                foreach ($winners as $id) {
                    $player = $this->players[$id];
                    if (!isSet($this->playerpot[$player->id]) || $this->playerpot[$player->id] == 0) {
                        unset($scores[$player->id]);
                        continue;
                    }

                    $max = $this->playersInHand() * $this->playerpot[$player->id];
                    if ($wincount == 0) {
                        $player->wins++;
                    }
                    if ($max >= $split) {
                        $player->money += $split;
                        $this->playerpot[$player->id] -= $split;
                        $this->pot -= $split;
                        $handout[$player->id] += $split;
                    }
                    else {
                        $player->money += $max;
                        unset($this->playerpot[$player->id]);
                        $handout[$player->id] -= $max;
                        $this->pot -=$max;
                    }

                    if (isSet($this->playerpot[$player->id]) && $this->playerpot[$player->id] <= 0) {
                        if (isSet($scores[$player->id])) {
                            unset($scores[$player->id]);
                        }
                    }
                }
                $wincount++;
            }

            if (count($winners) == 0) {
                /*
                 * Dividing the pot isn't entirely fair therefore we randomize the player that gets
                 * the first share of the pot. this code is experimental and should be tested
                */

                $ids = $this->playersLeftArray();
                shuffle($ids);
                foreach ($ids as $id) {
                    if (!isSet($this->playerpot[$id]) || !$this->playerpot[$id]) {
                        continue;
                    }
                    $max = $this->playerpot[$id];
                    $player = $this->players[$id];
                    if ($max > 0) {
                        if ($max < $this->pot) {
                            $player->money += $max;
                            $return[$player->id] += $max;
                            $this->pot -= $max;
                        }
                        else {
                            $player->money += $this->pot;
                            $return[$player->id] += $this->pot;
                            $this->pot = 0;
                        }
                        $this->playerpot[$player->id] = 0;
                    }
                }
                break;
            }
        }
        arsort($handout);
        foreach ($handout as $playerid => $value) {
            if ($value > 0) {
                $player = $this->players[$playerid];
                $this->broadcast(sprintf("%s receives $%0.2f", parent :: playerName($player), $value));
                //$this->send($player, sprintf("You receive $%0.2f", $value, $player->money));
            }
        }
        foreach ($return as $playerid => $value) {
            if ($value > 0) {
                $player = $this->players[$playerid];
                $this->broadcast(sprintf("$%0.2f is returned to %s", $value, parent :: playerName($player)), $player);
                $this->send($player, sprintf("$%0.2f is returned to you!", $value));
            }
        }


        $left = 0;
        $pot = 0;

        foreach ($this->players as $pl) {
            if ($pl->money == 0 && !$pl->out) {
                $this->broadcast(sprintf("%s is out of money and out of the game", parent :: playerName($pl)), $pl);
                $this->broadcast(sprintf("OUT=%d", $pl->id));
                $this->send($pl, "Sorry, you are out of money and thus out of the game :(");
                $pl->out = true;
                $this->moneylog[$pl->id][] = 0;
            }
            else {
                if (!$pl->out) {
                    $left++;
                    $this->send($pl, sprintf("OM=%0.2f", $pl->money));
                    $this->send($pl, sprintf("You now have $%0.2f", $pl->money));
                    $pot += $pl->money;
                    // printf("%s is still in the game with $%0.3f\n", parent :: playerName($pl), $pl->money);
                    $this->moneylog[$pl->id][] = $pl->money;
                }
            }
        }
        if ($pot != ($this->connections * $this->playermoney)) {
            /*
             * This should never happen unless a player is deliberatly trying to fool the server
             * in which case we abort the program
            */
            printf("The pot of $0.2f doesn\'t add up with the required $0.2f\n", $pot,  ($this->connections * $this->playermoney));
            foreach ($this->players as $k => $p) {
                printf("%d. %s $%0.2f%s\n", $p->id, parent :: playerName($p), $p->money, $p->out == true ?  ' (out)' : '');
            }
            exit;
        }


        if ($left == 1) {
            // Because there's only one player left with money, we can assume that the pot went to
            // said player
            $player = $this->players[$winners[0]];
            $this->broadcast("The game was won by %s after %d hands", parent :: playerName($player), $this->game);
            $this->broadcast("Generating log file...");
            $this->generateLog($player);
        }
        else {
            // Continue with the next hand
            if ($this->game < $this->totalgames) {
                $this->newgame = true;
            }
            else {
                $this->generateLog();
                $this->newgame = false;
                $this->turn = 0;
            }
        }
    }


    /*
     * generateLog
     * --
     * Generates a logfile whenever a game has come to its end
    */

    public function generateLog($winner = false) {
        $gameid = strtotime('now');
        $template = file_get_contents('data/log.html');
        $fp = fopen(sprintf('logs/log_%d.html', $gameid), 'a');

        $htplayers = array();
        foreach ($this->players as $pl) {
            $htplayers[] = sprintf('<li>%s</li>', htmlspecialchars(parent :: playerName($pl)));

        }
        $players = implode("\n", $htplayers);
        if ($winner) {
            $data = sprintf("After %d hands played, the player <strong>%s</strong> won the total pot of <strong>$%0.2f</strong>", $this->game, parent :: playerName($winner), $winner->money);
        }
        else {
            $htplayers = array();
            foreach ($this->players as $pl) {
                $htplayers[] = sprintf('<li>%s ($%0.2f)</li>', htmlspecialchars(parent :: playerName($pl)), $pl->money);
            }
            $htplayers = implode("\n", $htplayers);
            $data = sprintf("After %d hands no winner was determined a summary of each individual player's money follows:<ol>%s</ol>", $this->game, $htplayers);
        }

        $template = str_replace("{players}", $players, $template);
        $template = str_replace("{gameid}", $gameid, $template);
        $template = str_replace("{winner}", $data, $template);
        $template = str_replace("{version}", parent :: $version, $template);
        $template = str_replace("{date}", date('d-m-Y h:i', $gameid), $template);

        $template = str_replace("{startmoney}", sprintf('%0.2f', $this->playermoney), $template);
        $template = str_replace("{bigblind}", sprintf('%0.2f', $this->bigblind), $template);
        $template = str_replace("{smallblind}", sprintf('%0.2f', $this->smallblind), $template);
        $template = str_replace("{maxgames}", $this->totalgames, $template);
        $template = str_replace("{winner}", $data, $template, $template);

        fputs($fp, $template);
        fclose($fp);

        require_once('data/jpgraph.php');
        require_once('data/jpgraph_line.php');
        $width = 900;
        $height = 300;
        $graph = new Graph($width, $height);
        $graph->img->SetMargin(40,40,40,40);
        $graph->SetShadow();

        $graph->setScale('intlin');
        $graph->title->Set('Amount of money per player, per hand');
        $graph->xaxis->title->Set('Hand');
        $graph->yaxis->title->Set('Money');
        foreach ($this->moneylog as $id => $data) {
            $p = $this->players[$id];
            $lineplot = new LinePlot($data);
            $lineplot->setweight ( $id );
            $lineplot->SetLegend(parent :: playerName($p));
            $graph->add($lineplot);
        }
        $graph->stroke(sprintf('logs/playermoney_%d.jpg', $gameid));

        // Pie graph, showing checks, folds, bets, wins, calls and raises
        require_once ("data/jpgraph_pie.php");

        // Create the Pie Graph.
        $height = ceil(count($this->players) /2) * 440;
        $graph = new PieGraph($width, $height);
        $graph->SetShadow();

        // Set A title for the plot
        $graph->title->Set("Moves per user");

        // Create plots
        $size=0.20;
        $x = array(0.25, 0.75);
        $y = 220;
        $i = 1;
        $legend = array("Check","Call","Bet","Raise","Fold");
        foreach ($this->players as $id => $player) {
            $plot = new PiePlot(array($player->checks, $player->calls, $player->bets, $player->raises, $player->folds));
            if ($player->id == 1) {
                $plot->SetLegends($legend);
            }
            $plot->SetLabelType(PIE_VALUE_ADJPERCENTAGE);
            $plot->SetSize($size);
            $plot->SetCenter($x[$i -1], $y);
            if ($i == 2) {
                $i = 0;
                $y += 420;
            }
            $i++;
            $plot->title->Set(parent :: playerName($player));
            $graph->add($plot);
        }
        $graph->stroke(sprintf('logs/playermoves_%d.jpg', $gameid));

        // Bargraph containing each player's wins
        $height = 70 * count($this->players);
        require_once ("data/jpgraph_bar.php");
        $graph = new Graph($width,$height);
        $graph->SetScale('textlin');
        $graph->SetShadow();
        $lbl = array();
        $data = array();
        foreach ($this->players as $pl) {
            $lbl[] = parent :: PlayerName($pl);
            $data[] = $pl->wins;
        }
        $top = 60;
        $bottom = 30;
        $left = 80;
        $right = 30;
        $graph->Set90AndMargin($left,$right,$top,$bottom);


        $graph->xaxis->SetTickLabels($lbl);
        $graph->xaxis->SetLabelAlign('right','center','right');
        $graph->yaxis->SetLabelAlign('center','bottom');
        $graph->title->Set('Amount of hands won by individual players');
        $bplot = new BarPlot($data);
        $bplot->SetFillGradient("navy","lightsteelblue",GRAD_HOR);

        $bplot->SetWidth(0.5);
        $bplot->SetYMin(0);
        $graph->Add($bplot);
        $graph->Stroke(sprintf('logs/playerwins_%d.jpg', $gameid));
        $this->newgame = false;

        // Remove the clients from the server
        $this->broadcast("[SERVER] Thank you for playing Texas Hold`em (LIMIT)\n[SERVER] If you want to play another game, just reconnect to the server!");
        $this->return = true;
        foreach ($this->players as $player) {
            $this->disconnect($player);
        }
        $this->return = true;
        $this->restore();
    }

    /*
     *  Restore
     * --
     * Starts a new game as if nothing has happened :-)
    */

    private function restore() {
        // Resets the game
        $this->game = 0;
        $this->turn = 0;
        $this->pot = 0;
        $this->turntimer = 0;
        $this->playercalls = array();
        $this->playerpot = array();
        $this->playerblinds = array(0,0);
        unset($this->betamount);

        $this->newgame = false;
        $this->bet = false;
        $this->moves = 0;

        $this->starter = null;

        $this->turntimer = 0;
        foreach ($this->players as $player) {
            if ($player->id) {
                $player->out = false;
                $player->fold = false;
                $player->tmpout = false;
                $player->cards = array();
                $player->wins = $player->folds = $player->bets = $player->calls = $player->checks = $player->raises = 0;
                $player->money = $this->playermoney;
                $this->moneylog[$player->id] = array($player->money);
            }
        }
        $this->broadcast("[SERVER] The game was succesfully reset.");
        $this->return = false;
    }


    /*
     * playerFold
     * is executed when a player folds or gets folded automatically by the system
    */
    private function playerFold($player) {
        $player->folds++;
        $this->broadcast(sprintf("P=FOLD"));
        $this->broadcast(sprintf("%s folds.", parent :: playerName($player)));
        $player->fold = true;

        if ($this->playersLeft() == 1) {
            // We have a winner, based on the amount of folds, determine the player that
            // didn't fold
            foreach ($this->players as $pl) {
                if (!$pl->fold && !$pl->out) {
                    $score = array($pl->id => 120);
                    $this->winner($score);
                    break;
                }
            }
            return $pl;
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
                            // Player doesn't have the money to call the bet and has to go ALL-IN
                            $this->broadcast("P=CALL");
                            $this->broadcast(sprintf("%s is forced to go ALL-IN with only $%0.2f left", parent :: playerName($player), $player->money));
                            $this->betamount=  $player->money;
                            $this->addMoney($player, $bet);
                            $this->playercalls[$player->id] = true;
                            $player->calls++;
                            if ($this->playersLeft() == 1 && $this->playersInHand() > 1) {
                                $player->tmpout = true;
                                $this->progress();
                                return;
                            }

                            $this->nextTurn();
                            return;
                        }
                        else {
                            $this->broadcast("P=CALL");
                            $this->broadcast(sprintf("%s CALLS the bet", parent :: playerName($player)));
                            $this->addMoney($player, $bet);
                            $this->playercalls[$player->id] = true;
                            $player->calls++;
                            if ($this->playersLeft() == 1 && $this->playersInHand() > 1) {
                                $player->tmpout = true;
                                $this->progress();
                                return;
                            }

                            $this->nextTurn();
                            return;
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

            case 'bet':
                if ($this->turn == $player->id) {
                    # Did some player already bet?
                    if ($this->bet) {
                        # Yes, provide a nice error message
                        $this->send($player, "Another player already made a bet!");
                        $this->send($player, "You can either CALL, RAISE or FOLD");
                    }
                    else {
                        // Player is still in the game but the only player left
                        if ($this->playersLeft() == 1 && $this->playersInHand() > 1) {
                            $this->send($player, "No other players can CALL a bet, you can only CHECK, CALL or FOLD!");
                            return;
                        }

                        # NO! place the bet
                        if ($this->moves == 1) {
                            $bet = $this->bigblind;
                            if ($player->id == $this->playerblinds[0]) {
                                $bet -= $this->smallblind;
                            }
                            if ($player->id == $this->playerblinds[1]) {
                                $bet = 0;
                            }

                            if ($player->money >= $bet && $bet != 0) {
                                $this->addMoney($player, $bet);
                                $this->playercalls[$player->id] = true;
                                $this->broadcast(sprintf("%s automatically pays %0.2f in order to pay the big blind", parent :: playerName($player), $bet));
                                $this->playercalls[$player->id] = true;
                            }

                            else if ($player->money <= $bet && $bet != 0) {
                                $this->playercalls[$player->id] = true;
                                $this->broadcast(sprintf("It is all or nothing for %s who goes all-in with %0.2f\nP=BET", parent :: playerName($player), $player->money));
                                $this->addMoney($player, $bet);
                                $this->betamount = $player->money;
                                $this->playercalls[$player->id] = true;
                                $this->bet = true;
                                $player->bets++;
                                $this->nextTurn();
                                return;
                            }
                        }

                        $bet = $this->getbet();
                        if ($player->money >= $bet) {
                            $this->bet = true;
                            $this->broadcast("P=BET");
                            $this->broadcast(sprintf("%s places a bet of $%0.2f", parent :: playerName($player), $bet));
                            $this->resetplayercalls();
                            $this->playercalls[$player->id] = true;
                            $player->bets++;
                            $this->addMoney($player, $bet);
                            $this->nextTurn();
                        }
                        else {
                            if ($player->money >= 0) {
                                $this->bet = true;
                                $this->broadcast("P=BET");
                                $this->betamount = $player->money;
                                $this->broadcast(sprintf("%s goes all in with a dazzling amount of $%0.2f", parent :: playerName($player), $player->money));
                                $this->addMoney($player, $bet);
                                $player->bets++;
                                $this->nextTurn();
                            }
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
                    if (!$this->bet) {
                        $this->send($player, "There are no previous bets! would you like to BET?");
                    }
                    else {
                        if ($player->raiseturn) {
                            $this->send($player, "You have already raised this turn and can only CALL or FOLD");
                            break;
                        }

                        if ($this->playersLeft() == 1 && $this->playersInHand() > 1) {
                            $this->send($player, "No other players can RAISE your bet, you can only CHECK, CALL or FOLD!");
                            return;
                        }


                        if ($this->moves == 1) {
                            $bet = $this->bigblind;
                            if ($player->id == $this->playerblinds[0]) {
                                $bet -= $this->smallblind;
                            }
                            if ($player->id == $this->playerblinds[1]) {
                                $bet = 0;
                            }

                            if ($player->money >= $bet && $bet != 0) {
                                $this->addMoney($player, $bet);
                                $this->playercalls[$player->id] = true;
                                $this->broadcast(sprintf("%s automatically pays %0.2f in order to pay the big blind", parent :: playerName($player), $bet));
                                $this->playercalls[$player->id] = true;
                            }

                            else if ($player->money <= $bet && $bet != 0) {
                                $this->playercalls[$player->id] = true;
                                $this->broadcast(sprintf("It is all or nothing for %s who goes all-in with %0.2f\nP=RAISE", parent :: playerName($player), $player->money));
                                $this->addMoney($player, $bet);
                                $this->betamount = $player->money;
                                $this->playercalls = array();
                                $this->playercalls[$player->id] = true;
                                $this->bet = true;
                                $player->raiseturn = true;
                                $player->raises++;

                                $this->nextTurn();
                                return;
                            }

                        }
                        $bet = $tmp = $this->getbet();

                        if ($this->betamount) {
                            unset($this->betamount);
                        }
                        $add = $this->getbet();
                        $bet += $this->getbet();
                        if ($player->money >= $bet) {
                            $this->betamount = $bet;
                            $this->broadcast(sprintf("%s CALLS the bet of %0.2f and raises with %0.2f", parent :: playerName($player), $tmp, $add));
                            $this->playercalls = array();
                            $this->playercalls[$player->id] = true;
                            $player->raiseturn = true;
                            $this->bet = true;
                            $player->raises++;
                            $this->addMoney($player, $bet);
                            $this->nextTurn();
                            return;

                        }
                        else {
                            $bet += $player->money;
                            $this->betamount = $bet;
                            $this->broadcast(sprintf("%s CALLS the bet of %0.2f and goes ALL-IN with %0.2f", parent :: playerName($player), $tmp, $add));
                            $this->playercalls = array();
                            $this->playercalls[$player->id] = true;
                            $player->raiseturn = true;
                            $this->bet = true;
                            $player->raises++;
                            $this->addMoney($player, $bet);
                            $this->nextTurn();
                            return;
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

                    if ($this->moves == 1) {
                        $bet = $this->bigblind;
                        if ($player->id == $this->playerblinds[0]) {
                            $bet -= $this->smallblind;
                        }
                        if ($player->id == $this->playerblinds[1]) {
                            $bet = 0;
                        }

                        if ($player->money >= $bet && $bet != 0) {
                            $this->addMoney($player, $bet);
                            $this->playercalls[$player->id] = true;
                            $this->broadcast(sprintf("%s automatically pays %0.2f in order to pay the big blind\nP=CHECK", parent :: playerName($player), $bet));
                            $player->checks++;
                            $this->playercalls[$player->id] = true;
                            if ($this->playersLeft() == 1 && $this->playersInHand() > 1) {
                                $player->tmpout = true;
                                $this->progress();
                                return;
                            }
                            $this->nextTurn();
                            return;
                        }

                        if ($player->money <= $bet && $bet != 0) {
                            $this->playercalls[$player->id] = true;
                            $this->broadcast(sprintf("It is all or nothing for %s who goes all-in with %0.2f\nP=CHECK", parent :: playerName($player), $player->money));
                            $player->checks++;
                            $this->addMoney($player, $bet);
                            $this->playercalls[$player->id] = true;
                            if ($this->playersLeft() == 1 && $this->playersInHand() > 1) {
                                $player->tmpout = true;
                                $this->progress();
                                return;
                            }
                            $this->nextTurn();
                            return;
                        }

                    }
                    $player->checks++;
                    $this->broadcast(sprintf("P=CHECK"));
                    $this->broadcast(sprintf("%s checks", parent :: playerName($player)));
                    $this->playercalls[$player->id] = true;
                    if ($this->playersLeft() == 1 && $this->playersInHand() > 1) {
                        $player->tmpout = true;
                        $this->progress();
                        return;
                    }

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
        if (isSet($this->betamount)) {
            return $this->betamount;
        }
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
            if (!$player->out && $player->id != $id && !$player->tmpout && !$player->fold) {
                return $player;
                break;
            }
        }
        // Get the players before
        if ($before > 0) {
            for ($i = 1;$i < $id; $i++) {
                $player = $this->players[$i];
                # if the player is in the game and NOT this player and is not temporarily out
                if (!$player->out && $player->id != $id && !$player->tmpout && !$player->fold) {
                    return $player;
                    break;
                }
            }
        }
        return false;
    }


    // playersInHand - returns the people actively participating in the current hand
    // including people that are temporarily out!
    public function playersInHand() {
        $i = 0;
        foreach ($this->players as $pl) {
            if (!$pl->out) {
                $i++;
            }
        }
        return $i;
    }

    public function resetgame() {
        // Resets a game, removing bets and pots
        $this->moves = 0;
        $this->bet = false;
        $this->betamount = null;
        $this->playersingame = $this->connections;
        $this->turntimer = 0;
        $this->currentpot = null;
        $this->gamecardstable = array();
        $this->playerpot = array();
        foreach ($this->players as $pl) {
            $this->playerpot[$pl->id] = 0;
        }
        # Remove any previous folds
        foreach ($this->players as $i => $pl) {
            $pl->fold = false;
            $pl->tmpout = false;
            $pl->cards = array();
            $pl->raiseturn = false;
        }
        $this->resetplayercalls();
        $this->pot = 0;
    }

    /*
     * resetplayercalls
     * Resets all calls / bets from each player
    */
    public function resetplayercalls() {
        foreach ($this->players as $i => $pl) {
            if ($pl->fold || $pl->out || $pl->tmpout) {
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

        $this->broadcast(sprintf("NG=%d\nPlaying hand: %d/%d (%0.2f%%)", $this->game, $this->game, $this->totalgames, ($this->game / $this->totalgames) * 100));


        $sb = $this->getNextPlayer($this->playerblinds[0]);
        $bb = $this->getNextPlayer($sb->id);

        $this->starter = $bb->id;
        $this->playerblinds = $blinds = array($sb->id, $bb->id);
        print_r($blinds);
        foreach ($blinds as $i => $blind) {
            $player = $this->players[$blind];
            $blind = $i == 0 ?  $this->smallblind : $this->bigblind;
            if ($player->money >= $blind) {
                $this->addMoney($player, $blind);
                $this->broadcast("%sB=%d", $i == 0 ? 'S' : 'B', $player->id);
                $this->broadcast(sprintf("%s has paid the %s blind", parent :: playerName($player), $i == 0 ? 'SMALL' : 'BIG'), $player);
                $this->send($player, sprintf("You paid the %s blind and have $%0.2f left", $i == 0 ? 'SMALL' : 'BIG', $player->money));
                $this->playerblinds[$i] = $player->id;
                if ($player->money == 0) {
                    $player->tmpout = true;
                }
            }

            else {
                $this->broadcast(sprintf("%s can't afford the blind of $%0.2f and is forced ALL-IN with %0.2f",  parent :: playerName($player), $blind, $player->money));
                $this->playerblinds[$i] = $player->id;
                $this->addMoney($player, $blind);
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
            if (!$player->out) {
                $this->send($player, sprintf("C=%s",  implode(', ', $player->cards)));
                $this->send($player, sprintf($message, implode(', ', $player->cards)));
            }
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
        $this->resetplayercalls();
        $this->broadcast(sprintf("M=%d", $this->moves));

        foreach ($this->players as $player) {
            $player->raiseturn = false;
            if ($player->money == 0) {
                $player->tmpout = true;
            }

        }
        printf("G=%d, M=%d: The pot contains: %0.2f\n", $this->game, $this->moves, $this->pot);

        switch ($this->moves) {
            case 1:
            // Deal the cards to the players
                $this->gamecards = $this->deck;
                shuffle($this->gamecards);
                for ($i = 1; $i <= count($this->players); $i++) {
                    $player = $this->players[$i];
                    if (!$player->out) {
                        for ($j = 1; $j <= 2; $j++) {
                            $player->cards[] = $this->gamecards[0];
                            array_shift($this->gamecards);
                        }
                    }
                }

                break;

            case 2:
            // Deals the flop cards
                for ($i = 0;$i <= 3;$i++) {
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
                $this->broadcast(sprintf("CT=%s", implode(', ', $this->gamecardstable)));

                break;

            case 3:
            case 4:
                array_shift($this->gamecards);
                $this->gamecardstable[] = $this->gamecards[0];
                $this->broadcast(sprintf("The %s-card reveals: %s", $this->moves == 3 ? 'turn' : 'river', implode(', ', $this->gamecardstable)));
                $this->broadcast(sprintf("CT=%s", implode(', ', $this->gamecardstable)));
                break;

            case 5:
            default:
                $scores = array();
                foreach ($this->players as $pl) {
                    if (!$pl->out && !$pl->fold && isSet($this->playerpot[$pl->id]) && $this->playerpot[$pl->id] > 0) {
                        $playercards = array_merge($pl->cards, $this->gamecardstable);
                        $score = $this->calcPoker($playercards, $pl);
                        $scores[$pl->id] = $score;
                    }
                }
                arsort($scores);
                $this->winner($scores);
                return true;
                break;
        }
        $this->sendplayercards();
        $this->playercalls = array();
        $this->nextTurn();
    }

    /*
     * Select the next player that is in the game and sends the turn to him/her
    */
    public function nextTurn() {
        # is there only one active player left allow him to make his/her move
        if ($this->playersLeft() == 1 && $this->playersInHand() > 1) {
            // $this->bet = false;

            foreach ($this->players as $p) {
                if (!$p->out && !$p->fold && !$p->tmpout) {
                    $this->turn = $p->id;
                    $this->broadcast("SD=1");
                    $this->broadcast(sprintf("T=%d", $p->id));
                    $this->send($p, "It is your turn.. you have 20 seconds to make a move!");
                }
            }
            return;
        }

        if ($this->playersLeft() == 0) {
            $this->progress();
            return;
        }

        # Is the turn over?
        if (array_sum($this->playercalls) == $this->playersLeft()) {
            $pl = $this->starter ;
            $pl = $this->players[$pl];
            if (!$pl->fold && !$pl->out && !$pl->tmpout) {
                $this->turn = $pl->id;
            }
            else {
                $player = $this->getNextPlayer($pl->id);
                $this->turn = $player->id;
            }
            $this->progress();
            return;
        }
        #
        $player = $this->players[$this->turn];
        $next = $this->getNextPlayer($player->id);
        if (!$next) {
            $this->progress();
            return;
        }
        $this->turn = $next->id;
        $this->turntimer = 0;
        # Tell the players that the turn belongs to $player->id
        $this->broadcast(sprintf("T=%d", $next->id));
        $this->send($next, "It is your turn.. you have 20 seconds to make a move!");
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
    public $tmpout = false;
    public $cards = array();
    public $raiseturn = false;
    // Statistical information is stored per player
    public $wins = 0;
    public $checks = 0;
    public $bets = 0;
    public $folds  = 0;
    public $calls = 0;
    public $raises = 0;
}
?>