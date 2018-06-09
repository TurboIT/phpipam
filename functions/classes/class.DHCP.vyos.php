<?php


/**
 * DHCP_vyos class to work with vyos DHCP
 *
 *  It will be called form class.DHCP.php wrapper vyos is selected as DHCP type
 *
 *  https://vyos.io/
 *
 *
 */
class DHCP_vyos extends Common_functions {

    /**
     * Settings to be provided to connect to vyos
     *
     * (default value: array())
     *
     * @var array
     * @access private
     */
    private $vyos_settings = array();

    /**
     * Raw config file
     *
     * (default value: "")
     *
     * @var string
     * @access public
     */
    public $config_raw = "";

    /**
     * Parsed config file
     *
     * (default value: false)
     *
     * @var array|bool
     * @access public
     */
    public $config = false;

    /**
     * Falg if ipv4 is used
     *
     * (default value: false)
     *
     * @var bool
     * @access public
     */
    public $ipv4_used = false;

    /**
     * Flag if ipv6 is used
     *
     * (default value: false)
     *
     * @var bool
     * @access public
     */
    public $ipv6_used = false;

    /**
     * Array to store DHCP subnets, parsed from config file
     *
     *  Format:
     *      $subnets[] = array (pools=>array());
     *
     * (default value: array())
     *
     * @var array
     * @access public
     */
    public $subnets4 = array();

    /**
     * Array to store DHCP subnets, parsed from config file
     *
     *  Format:
     *      $subnets[] = array (pools=>array());
     *
     * (default value: array())
     *
     * @var array
     * @access public
     */
    public $subnets6 = array();

    /**
     * set available lease database types
     *
     * (default value: array("memfile", "mysql", "postgresql"))
     *
     * @var string
     * @access public
     */
    public $lease_types = array("memfile", "mysql", "postgresql");

    /**
     * List of active leases
     *
     * (default value: array())
     *
     * @var array
     * @access public
     */
    public $leases4 = array();

    /**
     * List of active leases
     *
     * (default value: array())
     *
     * @var array
     * @access public
     */
    public $leases6 = array();

    /**
     * Available reservation methods
     *
     * (default value: array("mysql"))
     *
     * @var string
     * @access public
     */
    public $reservation_types = array("file", "mysql");

    /**
     * Definition of hosts reservations
     *
     * (default value: array())
     *
     * @var array
     * @access public
     */
    public $reservations4 = array();
    public $reservations6 = array();


    /**
     * __construct function.
     *
     * @access public
     * @param array $vyos_settings (default: array())
     * @return void
     */
    public function __construct($vyos_settings = array()) {
        // save settings

        if (is_array($vyos_settings))            { $this->vyos_settings = $vyos_settings; }
        else                                    { throw new exception ("Invalid vyos settings"); }

        // parse config
        $this->parse_config ();
        // parse and save subnets
        $this->parse_subnets ();
    }


    /**
     * This function parses config file and returns it as array.
     *
     * @access private
     * @return void
     */
    private function parse_config () {


        $ssh_session = ssh2_connect($this->vyos_settings['host']);
        ssh2_auth_password($ssh_session, $this->vyos_settings['username'], $this->vyos_settings['password']);
        $stream = ssh2_exec($ssh_session, "/bin/vbash -c 'session_env=\$(cli-shell-api getSessionEnv \$PPID)
eval \$session_env
cli-shell-api setupSession
cli-shell-api showConfig service dhcp-server
cli-shell-api teardownSession'");
        stream_set_blocking($stream, TRUE);
        $vyos_config = stream_get_contents($stream);
        fclose($stream);
        ssh2_disconnect($ssh_session);

        $descriptorspec = array(
            0 => array("pipe", "r"),  // stdin is a pipe that the child will read from
            1 => array("pipe", "w"),  // stdout is a pipe that the child will write to
            2 => array("pipe", "w") // stderr is a file to write to
        );

        $dir = dirname(__FILE__);

        $process = proc_open("/usr/bin/python $dir/parse.py", $descriptorspec, $pipes);
        fwrite($pipes[0], $vyos_config);
        fclose($pipes[0]);
        $this->config_raw = stream_get_contents($pipes[1]);
        fclose($pipes[1]);

        proc_close($process);
        $this->config = json_decode($this->config_raw, TRUE);

    }

    /**
     * Saves subnets definition to $subnets object
     *
     * @access private
     * @return void
     */
    private function parse_subnets () {
        $this->subnets4 = array();


        $shared_networks = array_filter($this->config, function($k) {
            return $k == 'shared-network-name';
        }, ARRAY_FILTER_USE_KEY);

        foreach ($shared_networks as $shared_network) {

            foreach ($shared_network as $shared_network_value) {

                $subnets = array_filter($shared_network_value, function ($k) {
                    return $k == 'subnet';
                }, ARRAY_FILTER_USE_KEY);

                foreach ($subnets as $subnet) {
                    foreach ($subnet as $subnet_address=>$subnet_value) {

                        $this->subnets4[] = array(
                            "subnet" => $subnet_address,
                            "id" => 15,
                            "pools" => array(),
                            "option-data" => array(),
                        );
                    }
                }
            }
        }

        if (sizeof($this->subnets4) > 0) {
            $this->ipv4_used = TRUE;
        }

        return;

    }



    /* @leases --------------- */

    /**
     * Saves leases to $leases object as array.
     *
     * @access public
     * @param string $type (default: "IPv4")
     * @return void
     */
    public function get_leases ($type = "IPv4") {


        $ssh_session = ssh2_connect($this->vyos_settings['host']);
        ssh2_auth_password($ssh_session, $this->vyos_settings['username'], $this->vyos_settings['password']);
        $stream = ssh2_exec($ssh_session, "#!/bin/vbash
source /opt/vyatta/etc/functions/script-template
run show dhcp server leases");
        stream_set_blocking($stream, TRUE);
        $raw_leases = stream_get_contents($stream);
        fclose($stream);
        ssh2_disconnect($ssh_session);


        foreach (explode("\n", $raw_leases) as $line) {
            if (empty(trim($line))) {
                continue;
            }
            if (strpos($line, 'IP address') === 0) {
                continue;
            }
            if (strpos($line, '----') === 0) {
                continue;
            }

            $fields = preg_split('/\s+/', $line);

            if (!empty($fields)) {


                $this->leases4[] =
                    array("address" => $fields[0],
                        "hwaddr" => $fields[1],
                        "subnet_id" => "",
                        "client_id" => "",
                        "valid_lifetime" => "",
                        "expire" => "$fields[2] $fields[3]",
                        "state" => "",
                        "hostname" => $fields[4],
                    );

            }
        }
    }


    /* @reservations --------------- */

    /**
     * Saves reservations to $reservations object as array.
     *
     *
     * @access public
     * @param string $type (default: "IPv4")
     * @return void
     */
    public function get_reservations ($type = "IPv4")
    {

        $this->reservations4 = array();

        $shared_networks = array_filter($this->config, function ($k) {
            return $k == 'shared-network-name';
        }, ARRAY_FILTER_USE_KEY);

        foreach ($shared_networks as $shared_network) {

            foreach ($shared_network as $shared_network_value) {

                $subnets = array_filter($shared_network_value, function ($k) {
                    return $k == 'subnet';
                }, ARRAY_FILTER_USE_KEY);

                foreach ($subnets as $subnet) {
                    foreach ($subnet as $subnet_address => $subnet_value) {

                        $static_mappings = array_filter($subnet_value, function ($k) {
                            return $k == 'static-mapping';
                        }, ARRAY_FILTER_USE_KEY);

                        foreach ($static_mappings as $static_mapping_table) {

                            foreach ($static_mapping_table as $hostname => $data) {
                                $this->reservations4[] =
                                    array("subnet" => $subnet_address,
                                        "ip-address" => $data['ip-address'],
                                        "hw-address" => $data['mac-address'],
                                        "hostname" => $hostname,
                                        "location" => "",
                                        "options" => array(),
                                        "classes" => array(),
                                    );
                            }

                        }

                    }
                }
            }
        }

    }



    public function read_statistics () {
    }
}

?>