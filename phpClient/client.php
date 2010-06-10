<?php
class aiclient {
    public $name;
    public $conn;
    public $id;
    public $windows;

    // Constructor, sets up some defaults
    public function __construct() {
        $this->name = "AIdemo"; // Default nickname        
        $this->windows = true; // Assume we are using windows
        if (!function_exists('fsockopen')) { die("I am sorry, but the PHP-sockets module should be loaded in PHP.ini"); }
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

                case 'P':
                    // Is sent whenever another player makes a move
                    break;
                case 'ID':
                    $this->id = $cmd[1];
                    printf("** Playing with id: %d\r\n", intval($cmd[1]));
                    break;

                case 'T':
                    $tid = $cmd[1];
                    if ($cmd[1] == $this->id)
                    {
                        /*
                         * This space is reserved for your
                         * program logic, at this moment it will randomly choose
                         * an action for good or for worse... you have been warned :)
                         */
                    }
                    break;
            }
        }
    }

    /*
     * Connects to the server and
    */
    public function connect($server, $port) {
        $fp = $this->conn = fsockopen($server, $port);
        // Can't connect to the server
        if (!$fp) {
            die(sprintf("Couldn't connect to %s:%d", $server, intval($port)));
        }
        else {
            self :: send(sprintf("Name %s", $this->name));
            self :: send("This is the AIClient example written in PHP");
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
$client->name = "Test #1";
$client->connect('localhost', 8000);
?>
