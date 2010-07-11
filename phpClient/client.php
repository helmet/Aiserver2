<?php
/*
 * Remember that this client is just a demo-client, it utilizes little to no
 * AI and doesnot compute the score for each hand or the probability the hand
 * can win.
 */

class aiclient {
    public $name;
    public $conn;
    public $id;
    public $windows;

    # Game specific
    public $stage; // Stage of the game
    public $bet = false;
    public $selfraise = false;
    public $suddendeath;
    public $mycards;
    public $cardstable;


    // Constructor, sets up some defaults
    public function __construct() {
        $this->name = "AIdemo"; // Default nickname
        $this->windows = true; // Assume we are using windows
        if (!function_exists('fsockopen')) {
            die("I am sorry, but the PHP-sockets module should be loaded in PHP.ini");
        }
    }

    public function send($data) {
        $del = $this->windows ? "\r\n" : "\n";
        printf(">> %s%s", $data, $del);
        fputs($this->conn, $data . "\n");
    }

    public function receive($data) {
        // Read each linke seperatly
        $del = $this->windows ? "\r\n" : "\n";
        printf("<< %s%s", trim($data), $del);
        $data = explode("\n", $data);
        // Commands structures are defined as COMMAND=VALUE
        foreach ($data as $line) {
            $cmd = explode("=", $line);


            // Handle commands sent by the server
            switch ($cmd[0]) {
                case 'M':
                // New stage of the game (1=preflop, 2=flop, 3=turn, 4=river, 5=afterflop)
                    $this->stage = $cmd[1];
                    $this->bet = false;
                    $this->selfraise= false;
                    $this->suddendeath = false;
                    break;
                case 'NG':
                // New game was started, reset information stored in the client
                    $this->bet = false;
                    $this->selfraise= false;
                    break;

                case 'P':
                    $cmd = trim($cmd[1]);
                    if ($cmd == 'BET') {
                        $this->bet = true;
                    }
                    break;

                case 'SD':
                    printf("*** Sudden death can only check, call or fold\r\n");
                    $this->suddendeath = true;
                    break;
                case 'ID':
                    $this->id = $cmd[1];
                    printf("** Playing with id: %d\r\n", intval($cmd[1]));
                    break;

                case 'C':
                    $this->cardstable = explode(",", $cmd[1]);
                    break;

                case 'CT':
                    $this->mycards = explode(",", $cmd[1]);
                    break;

                case 'T':
                    $tid = $cmd[1];
                    if ($cmd[1] == $this->id) {
                        /*
                         * This space is reserved for your
                         * program logic, at this moment it will randomly choose
                         * an action for good or for worse... you have been warned :)
                        */

                        if (!$this->bet)
                        {
                            // No bets were made so it's always safe to check
                            // do so in 80% of the cases, otherwise send a BET
                            $move = rand(1,10);
                            if ($move > 8 && !$this->suddendeath) { $this->send('bet'); $this->bet = true; return; }
                            $this->send('check');
                        }

                        else
                        {
                            // There were bets in this game, we need some logic here
                            $move = rand(1,10);
                            if ($move <= 4 && $this->selfraise == true)
                            {
                                // We can't raise ourselves anymore
                                $this->send('call');
                                return;
                            }
                            if ($move <= 8 && !$this->suddendeath && !$this->selfraise)
                            {
                                $this->send('raise');
                                $this->selfraise = true;
                                return;
                            }

                            // Play it safe, fold!
                            $this->send('fold');
                        }
                    }
                    break;
            }
        }
    }

    /*
     * Connects to the server and
    */
    public function connect($server, $port) {
        printf("AIClient is trying to connect to the server at %s:%d\n", $server, intval($port));
        $fp = $this->conn = @fsockopen($server, $port);
        // Can't connect to the server
        if (!$fp) {
            die(sprintf("Couldn't connect to %s:%d", $server, intval($port)));
        }
        else {
            //self :: send(sprintf("Name %s", $this->name));
            //self :: send("This is the AIClient example written in PHP");
            while (!feof($fp)) {
                self :: receive(fgets($fp, 4096));
            }
            echo "Remote host has closed the connection...\n";
        }
    }
}

// Initialize our client
$client = new aiclient();
$client->windows = true;
$client->connect('localhost', 8000);
?>
