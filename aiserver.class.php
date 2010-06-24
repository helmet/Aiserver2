<?php
/*
 * Class AIV2
 * This is the default engine allowing players to connect to the system
 * and providing a one channel chat time-line, games based on this class do
 * not need to worry about the socket stuff as this module handles it perfectly.
*/

class aiv2 {
    static $version = 2.25;
    public $connections;
    public $players = array();
    public $port = 8000;
    public $bindip = '127.0.0.1';
    public $maxconnections = null;
    public $names = array();
    private $socket;
    public $check;
    private $adminpassword;

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
        $this->adminpassword = 'secret'; // Please do change this :)
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
            for ($i = 1; $i <= $this->maxconnections; $i++) {
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
                    if (!isSet($this->players[$i]) && $this->connections != $this->maxconnections) {
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
                        break;
                    }
                }
                if (--$ready <= 0) {
                    continue;
                }

            }

            // Handle data and commands sent by the clients
            foreach ($this->players as $id => $player) {
                if (isSet($player->socket) && in_array($player->socket, $read)) {
                    @$data = socket_read($player->socket, 1096);
                    if ($data == null) {
                        $this->disconnect($player);
                        continue;
                    }
                    $player->buffer .= $data;
                    if ($data == "\n" || $data == "\r\n" || stristr($player->buffer, "\n") ) {
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
            $this->connections--;
            $this->broadcast(sprintf("%s has left the game", $this->playerName($player)));
            $n = $this->playerName($player);
            foreach ($this->names as $k => $name) {
                if ($name == $n) {
                    unset($this->names[$k]);
                    $this->names = array_values($this->names);
                }
            }

            unset($this->players[$player->id]);
            unset($player);

        }
    }

    /*
     * playerName
     * ---
     * Returns the players name if it was set using the name command, otherwise returns Player X
    */
    function playerName($player) {
        if (!is_object($player)) {
            return false;
        }
        if ($player->name) {
            return ucfirst($player->name);
        }
        else {
            return sprintf("Player %d", $player->id);
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
            case 'login':
                if ($player->admin) {
                    $this->send($player, "You already are an Administrator");
                    return;
                }
                $login = implode(" ", $args);
                if ($login == $this->adminpassword) {
                    $player->admin = true;
                    $this->send($player, "Good evening professor Falken, shall we play a game?");
                    $this->send($player, "You are now logged in as an Administrator");
                }
                else {
                    $this->send($player, "Password incorrect!");
                }
                break;

            case 'list':
                if ($player->admin == false) {
                    $this->send($player, "List: you are not an Administrator");
                    return;
                }
                ksort($this->players);
                $this->send($player, "Players connected:");
                foreach ($this->players as $pl) {
                    socket_getpeername($pl->socket, $ip);
                    $this->send($player, sprintf('#%d. "%s" <%s> %s', $pl->id, $this->playerName($pl), $ip, $pl->id == $player->id ? '(You)' : ''));
                }
                break;

            case 'kick':
                if ($player->admin == false) {
                    $this->send($player, "Kick: you are not an Administrator");
                    return;
                }
                if (!isSet($args[0])) {
                    $this->send($player, 'Kick: not enough parameters');
                    return;
                }

                // First check if the name exists in the player base
                $ktarget = ucfirst(strtolower(trim(implode(" ", $args))));
                $knumber = intval($ktarget);

                if (in_array($ktarget, $this->names)) {
                    for ($i = 1; $i <= count($this->players); $i++) {
                        $p = $this->players[$i];
                        $cname = $this->playerName($p);
                        if ($cname == $ktarget) {
                            if ($p->id == $player->id) {
                                $this->send($player, "You can not kick yourself from the server");
                                return;
                            }
                            $this->broadcast(sprintf("%s was kicked from the server", $this->playerName($p)), $p);
                            $this->send($p, "You were removed from the server by an Administrator");
                            $this->disconnect($p);
                        }
                    }
                    return;
                }
                // If the playerid exists and is online
                if (isSet($this->players[$knumber])) {
                    if ($knumber == $player->id) {
                        $this->send($player, "You can not kick yourself from the server");
                        return;
                    }
                    $p = $this->players[$knumber];
                    $this->broadcast(sprintf("%s was kicked from the server", $this->playerName($p)), $p);
                    $this->send($p, "You were removed from the server by an Administrator");
                    $this->disconnect($p);
                    return;
                }
                // Player not found, return an error message
                $this->send($player, "Kick: No such player or id");
                break;

            case 'quit':
            case 'exit':
                $this->disconnect($player);
                break;
            case 'name':
                $name = ucfirst(strtolower(trim(implode(" ", $args))));
                if (!$name || empty($name)) {
                    $this->send($player, "Name: not enough parameters");
                }
                else {
                    if (in_array($name, $this->names)) {
                        $this->send($player, sprintf("%s: that name is already in use", $name));
                        return false;
                    }
                    if (!empty($player->name)) {
                        # Unset the old name so other clients can use it once more
                        for ($i= 0; $i < count($this->names); $i++) {
                            if ($player->name == $this->names[$i]) {
                                unset($this->names[$i]);
                            }
                        }
                    }
                    $now = !empty($player->name) ? trim($player->name)  : "Player " . $player->id;
                    $player->name = $name;
                    $this->broadcast(sprintf("%s is now known as %s", $now, $name));
                    $this->names[] = $name;
                    $this->names = array_values($this->names);
                }
                break;
            # If the command is unknown, we will send a chatmessage
            # This might come in handy when debugging certain parameters!
            default:
                $message = ucfirst($command) . ' ' . trim(implode(" ", $args));
                if ($message != " ") { // Prevent empty messages
                    $this->broadcast(sprintf("<%s> %s",  $this->playerName($player), $message));
                }
                break;
        }
    }


    /*
     * Adds a default player to the game
    */
    public function addplayer($playerid) {
        $player = new player();
        $name = sprintf('Player %d', $playerid);
        $player->name = $name;
        $this->names[] = $name;
        $player->id = $playerid;
        $this->players[$playerid] = $player;
        return $player;
    }
}

/*
 * Basic player class holding variables not relevant to any game
*/
class player {
    public $id;
    public $name;
    public $admin = false;
    public $socket = null;
    public $buffer = null;
}
?>