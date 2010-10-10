<?
/* This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 * 
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 * 
 * You should have received a copy of the GNU General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 * 
 * Nexus Framework Class
 * 
 * 
 *   
 * version: 			1.03
 * last modified:		10/01/10
 * author:				adrianp
 * 
 */

abstract class Nexus {

	protected static $framework_version = '1.03';
	protected $version_reply;	

	private $sock;
	protected $raw;

	/* if possible, the client should go in the background by default
	 */
	protected $fork = true;
	
	/* used by posix_setsid() if we fork()ed
	 */
	protected $sid;
	
	/* used by start() if we fork()ed
	 */
	protected $pid;
	
	/* this variable is adjusted by start() based on the existance of pcntl_*
	 */
	private $fork_possible = false;	
	
	/* disp_msg() is allowed to print to the screen
	 * if the client forks() it will set this to false
	 */
	protected $verbose = true;	
	
	protected $client;
	protected $me;
	protected $keepnick;
	protected $host;
		
	protected $uptime;
	protected $ontime;
	
	/* if alt_nick_tries exceeds max_nick_tries the client gives up
	 */
	protected $alt_nick_tries = 0;
	protected $max_nick_tries = 5;
	protected $min_pass_len = 4;	
	
	protected $min_keepnick_retry = 30;
	
	/* the minimum allowed setting for the save_data timer
	 * (in minutes)
	 */
	protected $min_save_period = 5;		
	
	protected $chans = array();
	protected $users = array();
	protected $ident = array();
	protected $userflags;
	protected $settings = array();
	
	/* should we exit() if there is no user in the userfile? 
	 */
	protected $require_admin = true;
	
	/* manipulated by the -u switch
	 */
	private $prompt_for_adduser = false;
	
	/* in seconds, how long to wait before retrying a channel join (if +l/b/k)
	 */
	protected $retry_time = 300;
	
	/* the path to the default configuration file
	 */
	protected $default_config = 'client.conf';
	protected $status_chars = '@%+';
	
	protected $chan_defaults;
	protected $client_defaults;	
						
	protected $timers = array();
			
	/* what regex to use to test for valid crypt entry
	 * $this->encrypt will alter this based on 'encrypt' config option
	 * the default is for a 32digit md5
	 */
	private $regex_crypt = "[a-f0-9]{32}";
	
	/* this represents a valid IRC nickname
	 */
	protected $regex_nick = "^[\w_^`\\{}\[\]|-]+$";
	
	/* these are configuration values and what regex to test them against
	 * used by load_config()
	 */
	protected $config_regex = array("nick" 				=> '/[^\-\d][\^\w-{}\d\`]{1,24}/si',
									"ident" 			=> '/[^\-\d][\^\w-{}\d\`]{1,24}/si',
									"debug_raw" 		=> '/[01]/si',
									"join_delay" 		=> '/[0-9]+/si',
									"keepnick_retry" 	=> '/[0-9]+/si'
									);
							

	/* since this constructor is vital to the functionality of the client created
	 * it is 'final' and the child class will have to use the on_load() abstract method provided
	 * instead of it's own constructor
	 */
	final public function __construct() {

		/* default client configuration values
		 * these are overwritten by the config file		
		 */
		$this->client_defaults = array(	'nick' 				=> 'mynick',		
										'realname'			=> $this->version_reply." - ".$this->framework_version,
										'ident'				=> 'mynick',
										'host' 				=> '0.0.0.0',
										'cmd_char' 			=> '.',		
																						
										
										'data_dir' 			=> 'data/',
										'user_file'			=> 'client.user',
										'chan_file'			=> 'client.chan',
																	
										'servers'			=> array(),
										
										// in minutes, how often to write userfile, default is 1hr
										'save_data'			=> 60,
										
										// how many seconds to delay autojoining channels
										'join_delay'		=> 3,
										// in seconds, how often to send ison if our nick is taken
										'keepnick_retry'	=> 100,
		
										'debug_raw'			=> 0
								   );
								   
		$this->uptime = time();
		
		/* we need this for args like -u
		 */
		if (!defined("STDIN")) { define("STDIN", fopen('php://stdin','r')); }
			
	}
	
	public function load() { $this->start(); }
	
	public function start() {
	
		/* a list of required functions by the framework
		 * if any of the following don't exist, Nexus will throw a fatal error and exit
		 */
		$req = array(	"stream_socket_client" 	=> 'php5+',
						"stream_context_create" => 'php4.3+',
						"stream_set_blocking" => 'php4.3+',
						"gethostbyname" => 'php4+'
						);
						
		foreach ($req as $key => $val) {
			if (!function_exists($key)) { 
				$this->disp_msg("fatal! your php binary doesn't support: $key() [$val] - consider a recompile");
				$this->bye();
			}
		}
		
		$this->client = $this->client_defaults;

		/* for customization purposes
		 * call abstract class on_load() since child classes cannot have a constructor
		 */
		$this->on_load();
		
		$this->config = $this->default_config;
		$load_argv = $this->check_arguments($_SERVER['argv']);

		/* this has to return 1, anything else is an error
		 * and will be displayed by disp_msg() later on
		 */
		$load_conf = $this->load_config($this->config);
		
		/* we don't care if the chanfile is empty or for whatever reason it fails to load
		 * we can fall back on the configuration file for channels to join
		 */
		$load_chan = $this->load_chanfile($this->client['data_dir'].$this->client['chan_file']);
		if ($load_chan) { $this->disp_msg($load_chan); }				

		/* once we have proper values for user_file/chan_file we can prompt
		 */
		if ($this->prompt_for_adduser) { $this->user_add_prompt(); exit(); }

		if ($load_argv && $load_conf == 1) {
			
			$load_user = $this->load_userfile($this->client['data_dir'].$this->client['user_file']);
			
			if ($load_user == 1) {
				
				/* signal trapping is not critical but highly recommended
				 * Nexus will spit out a warning if the binary was not compiled with --enable-pcntl
				 * at this point we can check for fork()ing ability and set the bool accordingly
				 */
				if (function_exists("pcntl_signal")) {
					// required by pcntl as of 4.3
					declare(ticks = 1);
					// attempt to quit gracefully if forced
					pcntl_signal(SIGTERM, array($this,'bye'));
					pcntl_signal(SIGUSR1, array($this,'bye'));					
					pcntl_signal(SIGINT, array($this,'bye'));	
					pcntl_signal(SIGHUP, array($this,'bye'));
										
					if (function_exists("pcntl_fork")){ $this->fork_possible = true; }
				}
				else {
					$this->disp_msg("warning: could not setup signal traps, please compile php with '--enable-pcntl'");
					$this->disp_msg("warning: it is recommended you set the 'save_data' config option to a frequent interval");					
				}
				
				$this->me = $this->keepnick = $this->client['nick'];
				
				/* at least one server must be configured in the .conf 
				 * or Nexus will throw a fatal error and exit()
				 */
				if ($this->client['servers'][0]['ip']) {
					/* if the user did not specify -f as an argument and if pcntl_* exist
					 * attempt to fork()
					 * 
					 * failure to launch in the background will run the client in foreground but will 
					 * spit out an error
					 */
					if ($this->fork_possible && $this->fork) { 
						$p = pcntl_fork();
						if ($p > 0) {
							$this->pid = $p; 
							$this->disp_msg("launched in the background (pid: $p)"); 
							exit();	
						}
						else if ($pid == -1) { $this->disp_error("failed to launch into the background"); }
					}
					/* if Nexus fork()ed we are in the child process
					 * the client should not talk anymore
					 * 
					 * posix_setsid() will make the child a session leader
					 */
					if ($p == 0 && $this->fork) {
						if ($this->sid = posix_setsid()) { $this->verbose = false; }
						else {
							$this->disp_error("fatal! could not detach from terminal, shutting down process: ".$this->pid);
							exit();
						}
					}

					/* it is now safe to spawn the data save timer
					 */
					if ($this->client['save_data']) { $this->timer('save_data','write_all()',$this->client['save_data']*60,true); }

					ini_set("max_execution_time", "0");
					ini_set("max_input_time", "0");
					set_time_limit(0);

					$this->on_init();
					
					$this->connect();
				}
				else { 
					$this->disp_error("fatal! you must specify at least one server in the config file"); 
					exit(); 
				}
			}
			else {
				$this->disp_error($load_user);
				exit(); // exit without writing anything since nothing was loaded
			}
		}
		else {
			$this->disp_error($load_conf);
			exit(); // exit without writing anything since nothing was loaded
		}
	}
	
	/* check the arguments parameter by parameter
	 * 
	 * current switches:
	 * 
	 * -u 				- interactive, add new user to database
	 * -c	[string]	- using rcrypt() encrypt a string and return it to the screen
	 * -f				- prevent the client from fork()ing
	 * [string]			- the last unknown parameter will be taken as the config file
	 * 
	 * @param 	string	arguments passed to client at startup ([0] is the file name)
	 * @return	bool
	 */
	private function check_arguments($argv) {
		for ($x = 1; $argv[$x]; $x++) {
			switch($argv[$x]) {
				// this will exit the client
				case "-u":
					// we do this to make sure the the proper config file is loaded first
					$this->prompt_for_adduser = true;
				break;
				case "-c":
					// display rcrypt(param+1) and quit
					if ($argv[$x+1] != null) { $this->disp_msg("string '".$argv[$x+1]."' encrypted is ".$this->rcrypt($argv[$x+1])); }
					else { $this->disp_error("usage: $argv[0] -c [string]"); }
					exit();
				break;				
				case "-f":
					$this->fork = false;
				break;							
				default:
					$this->config = $argv[$x];
				break;
			}
		}
		return true;
	}	
	
	/* start useradd method in prompt mode
	 * this is invoked by the user with the switch -u
	 * if called this method will always exit() the client
	 * 
	 * @return bool
	 */
	private function user_add_prompt() {
		$this->disp_msg("username: ","usr",true);
		while (!preg_match("/[\w-\d^`{}]+/si",($u = trim(fgets(STDIN))))) { 
			$this->disp_msg("username (can't be blank...): ","usr",true); 
		}

		system("stty -echo");
		$this->disp_msg("password: ","usr",true);
		while (!$p = trim(fgets(STDIN))) { echo "\n"; $this->disp_msg("password: ","usr",true); }
		system("stty echo");
		echo "\n"; 
		
		$p = $this->encrypt($p);
								
		$this->disp_msg("hostmask: ","usr",true);
		while (!preg_match("/.+@.+/si",($h = trim(fgets(STDIN))))) { 
			$this->disp_msg("hostmask (ident@host): ","usr",true); 
		}
		
		$this->disp_msg("flags (".$this->userflags."): ","usr",true);
		while (!preg_match("/[".$this->userflags."]/si",($f = trim(fgets(STDIN))))) { 
			$this->disp_msg("flags (".$this->userflags."): ","usr",true); 
		}
			
		$userfile = $this->client['data_dir'].$this->client['user_file'];
		if (!$fp = fopen($userfile,"a")) {
			$this->disp_error("error opening user file (do you need to chmod?): ".$userfile);
			return false;
		}
		$line = $u." ".$h." ".$f." ".$p."\n";
		fputs($fp,$line,strlen($line));
		fclose($fp);
		$this->disp_msg("added user: $u (flags: $f - mask: $h)","usr");
		return true;
	}
	
	/* add a new user into memory
	 * this must be written using write_userfile() or it will be lost on exit()
	 * 
	 * @param	string	username
	 * @param	string	password (encrypted with encrypt())
	 * @param	string 	host (ident@host format)
	 * @param	string	flags
	 * @return bool
	 */
	protected function user_add($u,$p,$h,$f) {
		if (!$this->users[$u]) {
			$this->disp_msg("added user: $u (flags: $f - mask: $h)","usr");
			$this->users[$u] = array('pass' => $this->encrypt($p), 'mask' => $h, 'flags' => $f);
			return true;	
		}
		else {
			$this->disp_error("user '$u' already exists");
			return false;	
		}
	}
	
	/* delete a user from memory
	 * this must be written using write_userfile() or it will be lost on exit()
	 * 
	 * @return bool
	 */
	protected function user_del($usr) {
		if ($this->users[$usr]['pass']) { 
			$this->disp_msg("user '$usr' was deleted");
			unset($this->users[$$usr]);
			return true;
		}
		else {
			$this->disp_error("user '$usr' does not exist");
			return false;
		}
	}
	
	/* write both user and chan file
	 */
	protected function write_all() {
		$this->write_userfile();
		$this->write_chanfile();
	}
	
	/* write user file from memory to disk
	 * 
	 * @return bool
	 */
	protected function write_userfile() {
		$this->disp_msg("writing user file... ","wrt",$nonl = true);
		
		$userfile = $this->client['data_dir'].$this->client['user_file'];
		if (!$fp = @fopen($userfile,"w")) {
			$this->disp_error("error! (file: ".$userfile.")","wrt");
			return false;
		}
		$line = ""; $count = 0;
		foreach ($this->users as $key => $val) {
			$line .= $key." ".$val['mask']." ".$val['flags']." ".$val['pass']."\n";
			$count++;
		}
		fputs($fp,$line,strlen($line));
		fclose($fp);
		$this->disp_msg("done. ($count entries to ".$userfile.")","...");
		return true;		
	}
	/* write chan file from memory to disk
	 * 
	 * @return bool
	 */	
	protected function write_chanfile() {
		$this->disp_msg("writing channel file... ","wrt",$nonl = true);
		
		$chanfile = $this->client['data_dir'].$this->client['chan_file'];
		if (!$fp = @fopen($chanfile,"w")) {
			$this->disp_error("error! (file: ".$chanfile.")","wrt");
			return false;
		}
		$line = ""; $count = 0;
		foreach ($this->settings as $chan => $set) {
			$line .= $this->rcrypt($chan)."{";
			foreach ($set as $key => $val) { 
				$val = preg_replace("/[\t\s]{1,}/si","",$val);		
				$line .= $key."=".(!is_numeric($val) ? $this->rcrypt($val) : $val).";"; 
			}
			$line .= "}\n";
			$count++;
		}
		fputs($fp,$line,strlen($line));
		fclose($fp);
		$this->disp_msg("done. ($count entries to ".$chanfile.")","...");
		return true;		
	}	
	
	/* one way string encryption
	 * 
	 * @param	string	plain text to encrypt
	 * @return 	string
	 */
	public function encrypt($str) {
		//$this->regex_crypt = "[a-f0-9]{32}";
		return md5($str);
	}
	
	/* connect to irc, loop through server list if necessary
	 */
	protected function connect() {
		$ip = gethostbyname($this->client['vhost']);
		$this->disp_msg("resolved vhost ".$this->client['vhost']." -> ".$ip);
		$options = array('socket' => array('bindto' => "$ip:0"));
		$context = stream_context_create($options);
		
		$snum = 0;
		while ($this->client['servers'][$snum]['ip']) {
			$this->sock = @stream_socket_client("tcp://".$this->client['servers'][$snum]['ip'].":".$this->client['servers'][$snum]['port'], 
												$err_no, $err_str, 15, 
												STREAM_CLIENT_CONNECT,
												$context
												);
			if (!$this->sock) {
				$this->disp_error("connection to '".$this->client['servers'][$snum]['ip']."' failed");
				//$this->disp_error("$err_no - $err_str");			
				$snum = ($snum == sizeof($this->client['servers'])-1 ? 0 : $snum+1);
				sleep(5);
				continue;
			}
						
			$this->ontime = time();
		
			stream_set_blocking($this->sock, 0);
			
			if ($this->client['servers'][$snum]['pass'] != '') { $this->send("PASS ".$this->client['servers'][$snum]['pass']); } 
			
			$this->send("USER ".$this->client['ident'].' x x :'.$this->client['realname']);			
			$this->send("NICK ".$this->client['nick']);
		
			// begin reading the socket
			$this->recv();
			
			// if we exhausted the server list, start again
			$snum = ($snum == sizeof($this->client['servers'])-1 ? 0 : $snum+1);
			sleep(5);
		}
	}
	
	/* load data from the configuration file
	 * 
	 * @param 	string	config file path (absolute or relative)
	 * @return 	int		value is 1 if all succeeded
	 * @return	string	will return string if there was an error
	 */
	protected function load_config($configfile) {
		if (!$fp = fopen($configfile,'r')) { return "could not open file (do you need to chmod?): $configfile"; }
		$line = 0; $chan = "";
		while (!feof($fp)) {
			$line++; $action = ""; $tmp = "";
			$data = preg_replace("/[\t\s]{1,}/si"," ",trim(fgets($fp,1024)));
			if ($data[0] != '#' && $data != '') {
				// in case the closing brace isn't on a line by itself
				if ($data[strlen($data)-1] == '}') { $open_brace = false; }
				switch ($this->gettok($data,1)) {
					case "save_data":
						if (is_numeric($this->gettok($data,2)) && $this->gettok($data,2) > $this->min_save_period) {
							$this->client['save_data'] = ($this->gettok($data,2) * 60);
						}
						else { $error = "invalid 'savedata'"; }
					break;																						
					case "server":
						if (preg_match('/^(server)\s[a-z\d.-]+\s[\d]{2,5}.*/si',$data)) {
							$this->client['servers'][] = array(	'ip' => $this->gettok($data,2),
																'port' => $this->gettok($data,3),
																'pass' => $this->gettok($data,4)
																);											
						}
						else { $error = "invalid 'server'"; }
					break;			
					case "channel":
						if (!$open_brace) {
							$chan = $this->gettok($data,2);
							if (preg_match("/^#/si",$chan)) {
								$this->settings[$chan] = $this->chan_defaults;
								$this->settings[$chan]['static'] = true;
								$ch_count++;
								switch ($this->gettok($data,3)) {
									case "{": 
										$open_brace = true; 
									break;
									default: 
										$this->settings[$chan]['key'] = $this->gettok($data,3); 
									break;
								}
							}
							else { $error = "invalid channel container"; }
						}
						else { $error = "previous container not closed"; }
					break;
					
					case "}":
						$open_brace = false;
						$chan = "";
					break;	
					
					// custom .conf settings
					default:
						$setting = $this->gettok($data,1);
						$val = "";
						if (preg_match("/^[\w0-9\-._]+$/si",$setting))  {
							for ($x = 2; ($tmp = $this->gettok($data,$x)) != ''; $x++) { $val .= $tmp." "; }	
							$val = rtrim($val," ");	
							if ($this->config_regex[$setting] && !preg_match($this->config_regex[$setting],$val)) {
								$error = "invalid '$setting'";
							} 
							else {
								if ($open_brace && $chan) { $this->settings[$chan][$setting] = $val; }						
								else { $this->client[$setting] = $val; }
							}
						}
					break;	
				}
				if ($error) { break; }
			}
		}
		fclose($fp);
		if (!$error && $open_brace) { $error = "container not closed";}
		if ($error) { return $error. " in $configfile (line: $line)"; }
		if ($ch_count > 0) { $this->disp_msg("loaded $ch_count channels from $configfile"); }
		return 1;
	}

	/* load user file from disk
	 * 
	 * @param 	string	user file path (absolute or relative) prefixed by datadir value
	 * @return 	int		value is 1 if all succeeded
	 * @return	string	will return string if there was an error		
	 */
	private function load_userfile($file) {
		if (!$fp = @fopen($file,'r')) { return "could not open user file: $file"; }
		$line = 0; $count = 0;
		while (!feof($fp)) {
			$line++;
			$data = rtrim(fgets($fp,512));
			if ($data) { //don't test blank lines
				if (!preg_match("/[\w-\d^`{}]+/si",$this->gettok($data,1)) 			 ||
					!preg_match("/.+@.+/si",$this->gettok($data,2))					 ||
					!preg_match("/[".$this->userflags."]/si",$this->gettok($data,3)) ||
					!preg_match("/".$this->regex_crypt."/si",$this->gettok($data,4)) ) { 
						$this->disp_msg("warning: skipped invalid entry in user file ($file:$line)");
				}
				else {
					$this->users[$this->gettok($data,1)] = array( 	'mask' => $this->gettok($data,2),
																	'flags' => $this->gettok($data,3),
																	'pass' => $this->gettok($data,4)
																);
					$count++;
				}
			}
		}
		if ($count || !$this->require_admin) {
			$this->disp_msg("loaded $count users from '$file'");
			fclose($fp);
			return 1;
		}
		fclose($fp);
		return "you don't have any users, maybe you should add some with the -u switch";
	}	
	/* load chan file from disk
	 * 
	 * @param 	string	chan file path (absolute or relative) prefixed by datadir value
	 * @return 	int		value is 1 if all succeeded
	 * @return	string	will return string if there was an error		
	 */	
	private function load_chanfile($file) {
		if (!$fp = @fopen($file,'r')) { return "could not open chan file (do you need to chmod?): $file"; }
		$line = 0; $count = 0;
		while (!feof($fp)) {
			$line++;
			$data = preg_replace("/[\t\s}]{1,}/si","",rtrim(fgets($fp,512)));
			if ($data != "") {
				$ch = $this->rdecrypt($this->gettok($data,1,"{"));
				if (preg_match("/^#/si",$ch) && !isset($this->channels[$ch]['static'])) {
					$token = rtrim($this->gettok($data,2,"{"),"}");
					for ($x = 1; $set = $this->gettok($token,$x,";");$x++) {
						$key = $this->gettok($set,1,"=");
						$val = $this->gettok($set,2,"=");		
						if ($val != "") { $this->settings[$ch][$key] = (!is_numeric($val) ? $this->rdecrypt($val) : $val); }
					}
					$count++;
				}
				else { $this->disp_msg("warning: skipped invalid entry in channel file ($file:$line)"); }
			}
		}
		fclose($fp);
		return "loaded $count channels from '$file'";
	}	
	
	/* reversible encryption, mostly for safe channel writes
	 * each char is converted to its hex equivalent and separated by an underscore
	 * 
	 * for example: a client channel with { in the name 
	 * would break load_chanfile if the channel was not scrambled with rcrypt()
	 * 
	 * you can manually encrypt string in this fashion using the -c switch
	 * 
	 * @param	string	plain text to scramble
	 * @return	string	scrambled text in hex
	 */
	public function rcrypt($str) {
		$crypt = "";
		$str = preg_replace("/[\t\s]{1,}/si","",$str);
		for ($x = 0; $str[$x]; $x++) { $crypt .= (!$x ? "" : "_").dechex(ord($str[$x])); }
		return $crypt;
	}
	/* reverse a scrambled piece of text
	 * 
	 * @param	string	text scrambled with rcrypt()
	 * @return	string	plain text unscramled
	 */
	protected function rdecrypt($str) {
		$tmp = ""; $decrypt = "";
		for ($x = 1;$tmp = $this->gettok($str,$x,"_"); $x++) { $decrypt .= chr(hexdec($tmp)); }
		return $decrypt;
	}	

	/* write string to irc socket
	 * 
	 * @param	string	irc raw data to send
	 */
	protected function send($what) {
		fputs($this->sock, $what."\r\n");
	}

	/* make sure all data is written to disk from memory
	 * this method is always called instead of exit()
	 */
	protected function bye() {
		$this->write_all();
		$this->on_unload();
		exit();
	}
	
	/* return bool if the key exists in an array of keys
	 * the search is case-insensitive
	 * 
	 * @param	string	what key to search for
	 * @param	array	an array of keys
	 */	
	protected function ikey_exists($needle,$haystack) {
		foreach (array_keys($haystack) as $key => $val) {
			$this->disp_msg("checking '$needle' =~ '$key'");
			if (preg_match("/^".$needle."$/si",$key)) { return true; }
		}
		return false;
	} 
	
	/* disp_* methods display formatted text to the screen if $verbose allows it
	 * 
	 * @param	string	what text to display
	 * @param	string	what to put in between []
	 * @param	bool	put newline after each message
	 */
	public function disp_msg($what, $nfo = 'nfo',$prompt = false) {
		if ($this->verbose) { print "[$nfo] ". $what .($prompt ? "": "\n"); }
	}
		
	public function disp_error($what) {
		$this->disp_msg($what,"err");
	}
	
	/* display all raw data if it is not null and it is not a server PING request
	 * 
	 * @param	string	text to display after [raw]
	 */
	public function disp_raw($data) {
		if ($data && !preg_match("/^PING /si",$data) && $this->client['debug_raw']) { $this->disp_msg($data,"raw"); }
	}	
	
	/* based on the channels read from chanfile or config
	 * upon irc connection, spawn delayed timers to join channels
	 */
	private function join_channels() {
		$wait = $this->client['join_delay'];
		$all_chans = array_keys($this->settings);
		for ($x = 0; $chan = $all_chans[$x]; $x++) {
			$this->timer(null,'cmd_join("'.$chan.'")',$wait);
			$wait += $this->client['join_delay'];			
		}	
	}
	
	/* start timer name @param1 to execute @param2 after @param3 seconds
	 * this creates a Timer object and adds it to the timers[] array
	 * 
	 * @param	string	timer name, if null a randid will be generated
	 * @param	string	command to eval after expiry
	 * @param	int		seconds to wait before executing command
	 * @param	bool	make this timer infinite (looped)
	 */
	protected function timer($tmr = null,$command,$delay,$inf = false) {
		$name = ($tmr == null ? $this->randid() : $tmr);
		
		// if it exists, kill it
		if ($this->timers[$name]) { 
			$this->disp_msg("timer: '$name' was killed");
			unset($this->timers[$name]); 
		}
		
		$this->timers[$name] = new NexTimer($command,$delay,$inf);
		$this->disp_msg("timer: '$name' created to execute /$command/ after ".$this->timers[$name]->get_wait()."s". ($this->timers[$name]->loop ? " (looped)":""));		
	}
	
	/* kill a timer if it is running
	 * 
	 * @param	string	timer key in timers[]
	 */
	protected function kill_timer($name) {
		if ($this->timers[$name] && $this->timers[$name]->active) {
			unset($this->timers[$name]);
			$this->disp_msg("timer: '$name' was killed.");
		}
		else {
			$this->disp_msg("timer: '$name' not running.");
		}
	}
	
	/* check if a timer was created
	 * 
	 * @param	string	timer name (array key)
	 * @return bool
	 */
	public function is_timer($name) {
		return isset($this->timers[$name]);
	}
	
	/* check the timers[] array and if the timer is completed eval the command
	 * if it's looped, reset_original_time()
	 */
	private function process_timers() {
		foreach ($this->timers as $name => $obj) {
			// if it's completed, eval then unset the object;
			// keep object if it is a looped timer
			if ($this->timers[$name]->completed()) { 
				$cmd = $this->timers[$name]->get_command();
				if ($cmd != null) { eval('$this->'.str_replace('\\','\\\\',$cmd.';')); }
				$this->disp_msg("timer: '$name' expired after ".$this->timers[$name]->get_wait()."s". ($this->timers[$name]->loop ? " (looped)":""));								
				if (!$this->timers[$name]->loop) { unset($this->timers[$name]); }
				else { $this->timers[$name]->reset_original_time(); }
			}
		}
	}
	
	/* this function reads from the socket (non-blocking)
	 * it also checks the timers for expiry
	 */
	private function recv() {
		while (!feof($this->sock)) {
			@stream_select($r=array($this->sock),$w=null,$e=null,1);
			
			$this->raw = fgets($this->sock, 1024);	
			$this->raw = rtrim($this->raw);
			$this->process_timers();

			$this->parse();
		}
	}
	
	/* parse irc raw data and call pre_* event functions followed 
	 * by the abstract event functions
	 */
	private function parse() {
		$this->disp_raw($this->raw);
	
		if (preg_match("/^PING /si",$this->raw)) { 
			$this->send("PONG ".$this->gettok($this->raw,2)); 
		}
		// process raw server replies
		if (preg_match("/^:[\w.]+\s[0-9]{3}/si",$this->raw)) { 
			$this->rawnum($this->gettok($this->raw,2)); 
		}	
		
		switch ($this->gettok($this->raw,2)) {
			case "NICK":
				$nick = ltrim($this->gettok($this->raw,1,'!'),':');
				$host = $this->gettok($this->gettok($this->raw,1),2,'!');
				$newnick = ltrim($this->gettok($this->raw,3),':');
				
				$this->pre_on_nick($nick,$host,$newnick);
				$this->on_nick($nick,$host,$newnick);
			break;
			
			case "JOIN":
				$nick = ltrim($this->gettok($this->raw,1,'!'),':');
				$host = $this->gettok($this->gettok($this->raw,1),2,'!');
				$chan = ltrim($this->gettok($this->raw,3),':');
				
				$this->pre_on_join($nick,$host,$chan);
				$this->on_join($nick,$host,$chan);
			break;
			
			case "PRIVMSG":
				$not_message = $this->gettok($this->raw,1).$this->gettok($this->raw,2).$this->gettok($this->raw,3);
				// strlen +4 because of the spaces and :
				$message = substr($this->raw,strlen($not_message)+4,strlen($this->raw));
				$target = $this->gettok($this->raw,3);
				$sender = ltrim($this->gettok($this->raw,1,'!'),':');
				$sender_host = $this->gettok($this->gettok($this->raw,1),2,'!');
				if ($target[0] != '#') { 
					$this->pre_on_privmsg($sender,$sender_host,$message); 
					$this->on_privmsg($sender,$sender_host,$message); 
				}
				else { 
					$this->pre_on_pubmsg($sender,$sender_host,$target,$message);
					$this->on_pubmsg($sender,$sender_host,$target,$message); 
				}
			break;
			case "PART":
				$nick = ltrim($this->gettok($this->raw,1,'!'),':');
				$host = $this->gettok($this->gettok($this->raw,1),2,'!');
				$chan = $this->gettok($this->raw,3);
				
				$this->pre_on_part($nick,$host,$chan);
				$this->on_part($nick,$host,$chan);
			break;
			
			case "KICK":
				$msg = explode(' ',$this->raw);
				$not_message = $msg[0].$msg[1].$msg[2].$msg[3];
				// strlen +5 because of the spaces and :
				$message = substr($this->raw,strlen($not_message)+5,strlen($this->raw));
				$nick = ltrim($this->gettok($this->raw,1,'!'),':');
				$host = $this->gettok($this->gettok($this->raw,1),2,'!');
				$chan = ltrim($this->gettok($this->raw,3),':');
				$knick = $this->gettok($this->raw,4);					
				
				$this->pre_on_kick($nick,$host,$chan,$knick,$message);
				$this->on_kick($nick,$host,$chan,$knick,$message);
			break;
			
			case "MODE":
				// channel mode?
				if (preg_match("/^#.*/",$this->gettok($this->raw,3))) {
					$p = "";
					$nick = ltrim($this->gettok($this->raw,1,'!'),':');
					$host = $this->gettok($this->gettok($this->raw,1),2,'!');
					$chan = $this->gettok($this->raw,3);
					$mode = $this->gettok($this->raw,4);		
					for ($z = 5;$this->gettok($this->raw,$z);$z++) { $p .= $this->gettok($this->raw,$z)." "; }
					
					$this->parse_mode($nick,$host,$chan,$mode,$p);
				}
			break;
			case "QUIT":
				$nick = ltrim($this->gettok($this->raw,1,'!'),':');
				$host = $this->gettok($this->gettok($this->raw,1),2,'!');
				$not_reason = $nick.$host;
				// strlen +5 because of the spaces and :
				$reason = substr($this->raw,strlen($not_reason)+5,strlen($this->raw));	
	
				$this->pre_on_quit($nick,$host,$reason);			
				$this->on_quit($nick,$host,$reason);
			break;
		}			
	}
	
	/* a string of modes is passed in followed by the parameters
		this method will split them up and call the appropriate method to handle each event
		
		@param 	string	nickname performing the MODE
		@param	string	host belonging to $nick
		@param	string	the channel this is occuring in
		@param	string	string of modes being changed
		@param	string	parameters associated with the string of modes
	 */
	private function parse_mode($nick,$host,$chan,$mode,$params) {
		$step = 1;
		for ($x = 0;$mode[$x];$x++) {
			// -ooo+o 1 2 3 4
			switch ($mode[$x]) {
				case "+": 
					$ud = 1; 
				break;
				case "-": 
					$ud = 0; 
				break;
				default:
					switch ($mode[$x]) {
						case "o":
							$onick = $this->gettok($params,$step);
							if ($ud) { $this->pre_on_op($nick,$host,$chan,$onick); $this->on_op($nick,$host,$chan,$onick); }
							else { $this->pre_on_deop($nick,$host,$chan,$onick); $this->on_deop($nick,$host,$chan,$onick); }
						break;
						case "v":
							$vnick = $this->gettok($params,$step);
							if ($ud) { $this->pre_on_vo($nick,$host,$chan,$vnick); $this->on_vo($nick,$host,$chan,$vnick); }
							else { $this->pre_on_devo($nick,$host,$chan,$vnick); $this->on_devo($nick,$host,$chan,$vnick); }
						break;	
						case "h":
							$hnick = $this->gettok($params,$step);
							if ($ud) { $this->pre_on_dehop($nick,$host,$chan,$hnick); $this->on_hop($nick,$host,$chan,$hnick); }
							else { $this->pre_on_dehop($nick,$host,$chan,$hnick);$this->on_dehop($nick,$host,$chan,$hnick); }
						break;												
						case "b":
							$mask = $this->gettok($params,$step);
							if ($ud) { $this->pre_on_ban($nick,$host,$chan,$mask); $this->on_ban($nick,$host,$chan,$mask); }
							else { $this->pre_on_unban($nick,$host,$chan,$mask); $this->on_unban($nick,$host,$chan,$mask); }
						break;		
						case "k":
							$key = $this->gettok($params,$step);
							if ($ud) { $this->pre_on_key($nick,$host,$chan,$key); $this->on_key($nick,$host,$chan,$key); }
							else { $this->pre_on_unkey($nick,$host,$chan,$key); $this->on_unkey($nick,$host,$chan,$key); }
						break;	
						default:
							$par = $this->gettok($params,$step);
							if ($ud) { $this->pre_on_mode($nick,$host,$chan,$mode[$x],$par); $this->on_mode($nick,$host,$chan,$mode[$x],$par); }
							else { $this->pre_on_demode($nick,$host,$chan,$mode[$x],$par); $this->on_demode($nick,$host,$chan,$mode[$x],$par); }
						break;										
					}
					// only step ahead if the mode has a parameter
					if (preg_match("/[ovhklbIe]/",$mode[$x])) { $step++; }
				break;
			}
		}
	}			
	
	/* the client joins a channel but checks if there is a key associated with it first
	
		@param	string	channel to join
	 */
	protected function cmd_join($chan) {
		if ($chan[0] != '#') { $chan = "#".$chan; }
		$this->send("join $chan ". ($this->settings[$chan]['key'] ? $this->settings[$chan]['key'] : ""));
		$this->disp_msg("joining $chan");
	}		

	/* give channel access to a nick, this is by default o but could be v/h even b
	
		@param	string	channel to perform MODE in
		@param	string	nickname to give this mode to
		@param	string	default: o, can be any one char valid channel mode
	 */	
	protected function cmd_op($chan,$nick,$op = "o") {
		if ($chan[0] != '#') { $chan = "#".$chan; }
		if (is_array($nick)) {
			$ncount = sizeof($nick);
			$opline = "MODE $chan +".str_repeat($op,4)." ";
			for ($x = 0;($nick[$x] && $x <= 16);$x++) {
				$opline .= $nick[$x]." ";
				if (!(($x+1) % 4) && isset($nick[$x+1])) { $opline .= "\r\nMODE $chan +".str_repeat($op,4)." "; }
			}
			$this->send(rtrim($opline,"\r\n"));
		}
		else if (!$this->isop($nick,$chan)) { $this->send("mode $chan +$op $nick"); }
	}			

	/* parse any IRC raw replies based on the number
	 */
	private function rawnum($num) {
		switch ($num) {
			// start of motd, usually means we are connected
			case "001":
				$this->join_channels();
			break;
		
			// nickname in use
			case "433":
				if ($this->alt_nick_tries > $this->max_nick_tries) { 
					$this->disp_error("can't register nickname with server"); 
					$this->bye(); 
				}
				$bnick = $this->me;
				$bnick = (strlen($bnick) < 9 && !$this->alt_nick_tries ? $bnick : substr($bnick,0,strlen($bnick)-1)).$this->alt_nick_tries;
				
				$this->me = $bnick;
				$this->send("NICK ".$bnick);
				$this->disp_msg("nickname ".$this->keepnick." in use, trying: ".$bnick);
				$this->timer('keepnick','send("ison :'.$this->keepnick.'")', $this->client['keepnick_retry'], true);	
				$this->alt_nick_tries++;
			break;
			
			case "376":
					// get our host
					$this->timer('get_own_host','send("whois '.$this->me.'")',3);
			break;	
			
			// names of users on channel - /names
			case "353":
					$c = $this->gettok($this->raw,5);
					for ($x = 6; $this->gettok($this->raw,$x); $x++) {
						$n = str_replace(":","",$this->gettok($this->raw,$x));
						$strip_op = preg_replace("/^[".$this->status_chars."]/","",$n);
						$this->chans[$c][$strip_op] = array('host' 		=> '', 
															'status' 	=> (preg_match("/^[".$this->status_chars."]/",$n) ? substr($n,0,1) : '')
															);
					}
			break;	
				
			// end of /names
			case "366":
					$jchan = $this->gettok($this->raw,4);
					$ucount = sizeof($this->chans[$jchan]);
					$this->disp_msg("collected information for $ucount users in $jchan");
			break;								

			// ison reply
			case "303":
				if ($this->gettok($this->raw,4) == ":") {
					$this->send("NICK ".$this->keepnick);
				}
			break;			

			// whois first line reply
			case "311":
				if ($this->gettok($this->raw,3) == $this->me) {
					$i = $this->gettok($this->raw,5);
					$h = $this->gettok($this->raw,6);
					$this->host = "$i@$h";
					$this->disp_msg("got our host: ".$this->host);
				}
			break;		
			
			/* spawn timer to retry join every ($this->retry_time)s:
				473 - channel is +i
				475 - channel is +k
				471 - channel is +l		
				474 - we are banned					
			 */
			case "473":
				$chan = $this->gettok($this->raw,4);
				$safe = $this->safe_name($this->gettok($this->raw,4));
				if (!$this->is_timer("j_retry_$safe")) { $this->timer("j_retry_$safe",'send("JOIN '.$chan.'")',$this->retry_time,true); }
			break;		
			case "475":
				$chan = $this->gettok($this->raw,4);
				$safe = $this->safe_name($this->gettok($this->raw,4));
				if (!$this->is_timer("j_retry_$safe")) { $this->timer("j_retry_$safe",'send("JOIN '.$chan.'")',$this->retry_time,true); }
			break;		
			case "471":
				$chan = $this->gettok($this->raw,4);
				$safe = $this->safe_name($this->gettok($this->raw,4));
				if (!$this->is_timer("j_retry_$safe")) { $this->timer("j_retry_$safe",'send("JOIN '.$chan.'")',$this->retry_time,true); }
			break;	
			case "474":
				$chan = $this->gettok($this->raw,4);
				$safe = $this->safe_name($this->gettok($this->raw,4));
				if (!$this->is_timer("j_retry_$safe")) { $this->timer("j_retry_$safe",'send("JOIN '.$chan.'")',$this->retry_time,true); }
			break;														
		}
		unset($safe);
	}
	
	/* check if the user is an operator in a channel
	 * 
	 * @param	string	nickname to check
	 * @param	string	channel to check for status of nickname
	 * @param	string	the default is @ but can be any channel status indicator that prefixes /names
	 * @return	bool
	 */
	protected function isop($nick,$chan,$status = '@') {
		return $this->isin($status,$this->chans[$chan][$nick]['status']);
	}
	
	/* check if the a user has a specific flag
	 * 
	 * @param	string	flag to loop for
	 * @param	string	username to check
	 * @return	bool
	 */
	protected function has_flag($flag,$nick) {
		return $this->isin($flag,$this->ident[$nick]['flags']);
	}	
	
	/* this function will tokenize a string and return the token number specified
		by default it splits by a single space, but this could be any one char
		
		@param	string	the string to tokenize
		@param	int		the token number to return
		@param	string	the delimiter by which to tokenize the string
		
		@return	string
	 */
	protected function gettok($string,$num,$delim = " ") {
		$x = explode($delim,$string);
		return $x[$num-1];
	}	
	
	/* return a random string consisting of a-z or 0-9
	 */
	protected function randid($len = 8) {
		for ($x = 1; $x <= $len;$x++) {
			$rand_id .= (($y = rand(0,1)) ? rand(0,9) : chr(rand(65,90))); 
		}
		return strtolower($rand_id);
	}	
	
	/* strip any weird characters out of a string to be safely used in array keys and so on
		an alias for rcrypt()
		
		@param	string	string to process
		@return	string
	 */
	protected function safe_name($chan) {
		return $this->rcrypt($chan);
	}
	
	/* we don't need the position, but strpos is the fastest way to find a string
		the isin function makes things easier since we can use 'if (isin())' instead of
		'if (strpos($haystack,$needle) !== false)', so shut up
		
		@param	string	the piece of string to search for
		@param	string	where to search for this string
		@return	bool
	 */
	protected function isin($needle,$haystack) {
		return strpos($haystack,$needle) === false ? false : true;
	}
	
	/* checks to see if an 'expanded' string matches the wildcard
		for example: user@host.*.rogers.com will match user@host.toronto.rogers.com
		
		@param	string	wildcard
		@param	string	test string
		@param	bool	should we add .* around the wildcard?
		@return	string
	 */
	protected function iswm($wild,$expanded,$strict = true) {
		$wild = str_replace("\*",".*",preg_quote(stripslashes($wild))); 
		$wild = str_replace("/","\\",$wild);
		//$this->disp_msg("matching /^$wild\$/si against: $expanded");
		if (!$strict) { $wild = ".*$wild.*"; }
		return preg_match('/^'.$wild.'$/si',$expanded) ? true : false;
	}	
	
	/* return time in []d []h []m []s format
	 * 
	 * @param	int		should be unixtime returned by time()
	 * @return 	string
	 */
	public function duration($time) {
		$time = time() - $time;			
		switch ($time) {
			case ($time > 86400):
				$format = sprintf("%dd %dh", ($time / 86400), (($time / 3600) % 24));
			break;
			case ($time > 3600):
				$format = sprintf("%dh %dm", ($time / 3600), (($time / 60) % 60));
			break;
			case ($time > 60):
				$format = sprintf("%dm %ds", ($time / 60), ($time % 60));
			break;
			default:
				$format = $time ."s";
			break;
		}
		return $format;
	}
	
	/* the pre_* functions are called before their child counterparts
	 * see documentation
	 */	
	private function pre_on_nick($nick,$host,$newnick) {
		if ($nick == $this->me) { 
			// this means we get our nick, so lets stop the ison timer
			if ($newnick == $this->keepnick) { $this->kill_timer('keepnick'); }
			$this->me = $newnick;
		}
		else {
			foreach ($this->chans as &$key) {
				if (isset($key[$nick])) {
					$key[$newnick] = $key[$nick];
					unset($key[$nick]);
				}
			}
			foreach ($this->ident as &$key) {
				if (isset($key[$nick])) {
					$key[$newnick] = $key[$nick];
					unset($key[$nick]);
				}
			}			
		}
	}	
	
	// on KEY
	private function pre_on_key($nick,$host,$chan,$key) {
		$this->settings[$chan]['key'] = $key;
	}
	
	private function pre_on_unkey($nick,$host,$chan,$key) {
		unset($this->settings[$chan]['key']);
	}
	
	// on OP
	private function pre_on_op($nick,$host,$chan,$onick) {
		if (!$this->isop($onick,$chan)) { $this->chans[$chan][$onick]['status'] .= '@'; }
	}
	
	private function pre_on_deop($nick,$host,$chan,$onick) {
		$this->chans[$chan][$onick]['status'] = str_replace('@','',$this->chans[$chan][$onick]['status']);
	}

	// on VOICE
	private function pre_on_vo($nick,$host,$chan,$vnick) {
		if (!$this->isop($vnick,$chan,'+')) { $this->chans[$chan][$vnick]['status'] .= '+'; }	
	}
	
	private function pre_on_devo($nick,$host,$chan,$vnick) {
		$this->chans[$chan][$vnick]['status'] = str_replace('+','',$this->chans[$chan][$vnick]['status']);
	}

	// on HOP
	private function pre_on_hop($nick,$host,$chan,$hnick) {
		if (!$this->isop($hnick,$chan,'%')) { $this->chans[$chan][$hnick]['status'] .= '%'; }
	}
	
	private function pre_on_dehop($nick,$host,$chan,$hnick) {
		$this->chans[$chan][$hnick]['status'] = str_replace('%','',$this->chans[$chan][$hnick]['status']);	
	}

	// on BAN
	private function pre_on_ban($nick,$host,$chan,$mask) {
		//
	}
	
	private function pre_on_unban($nick,$host,$chan,$mask) {
		//
	}
	
	// on MODE
	private function pre_on_mode($nick,$host,$chan,$mode,$param) {
		//
	}
	
	private function pre_on_demode($nick,$host,$chan,$mode,$param) {
		//
	}

	private function pre_on_join($nick,$host,$chan) {
		unset($this->timers["j_retry_".$this->safe_name($chan)]);
		$this->chans[$chan][$nick] = array('host' => $host, 'status' => '');
	}

	private function pre_on_part($nick,$host,$chan) {
		if ($nick == $this->me) { unset($this->chans[$chan]); }
		else { unset($this->chans[$chan][$nick]); }
	}
	
	private function pre_on_kick($nick,$host,$chan,$knick,$reason) {
		if (!$this->chans[$chan][$nick]['host']) { $this->chans[$chan][$nick]['host'] = $host; }
		if ($knick == $this->me) { unset($this->chans[$chan]); $this->timer('k_rejoin_'.$this->safe_name($chan),'cmd_join("'.$chan.'")',5); }
		else { unset($this->chans[$chan][$knick]); }
	}
	
	private function pre_on_quit($nick,$host,$reason) {
		if (isset($this->ident[$nick])) { unset($this->ident[$nick]); }
		foreach ($this->chans as &$key) {
			if (isset($key[$nick]) && $nick != $this->me) {
				unset($key[$nick]);
			}
			else { unset($key); }
		}
	}
	
	// on PUBMSG
	private function pre_on_pubmsg($nick,$host,$chan,$text) {
		//
	}
	
	// on PRIVMSG
	private function pre_on_privmsg($nick,$host,$text) {
		if (!$this->is_timer('priv_command_throttle') &&
			($text[0] == $this->client['cmd_char']) &&
			!isset($this->ident[$nick])) {
			$cmd = substr($this->gettok($text,1),1,strlen($this->gettok($text,1)));			
			$this->timer('priv_command_throttle',null,10);
			switch ($cmd) {
				case ($cmd == "ident" || $cmd == "verify" || $cmd == "login"):
					$usr = $this->gettok($text,2);
					$pass = $this->gettok($text,3);
					if ($this->iswm($this->users[$nick]['mask'],$host)) {
						if ($this->encrypt($pass) == $this->users[$usr]['pass']) {
							$this->ident[$nick]['host'] = $host;
							$this->ident[$nick]['user'] = $usr;	
							$this->ident[$nick]['flags'] = $this->users[$usr]['flags'];							
							$this->send("PRIVMSG $nick :verified (flags: +".$this->users[$usr]['flags'].")");					
							$this->disp_msg("ident: $nick is verified as '$usr' (flags: +".$this->users[$usr]['flags'].")");
						} 
						else {
							$this->disp_msg("ident: $nick failed to verify as '$usr'");
						}
					}
				break;
			}
		}
		if (!$this->is_timer('priv_command_throttle')) { 
			if ($text == "\001VERSION\001" && 			
				$this->version_reply ) {
				$this->timer('priv_command_throttle',null,10);
				$this->send("NOTICE $nick :\001VERSION ".$this->version_reply."\001");
			}
		}
	}	
	
	/* see documentation
	 */
	abstract protected function on_nick($nick,$host,$newnick);
	abstract protected function on_key($nick,$host,$chan,$key);
	abstract protected function on_unkey($nick,$host,$chan,$key);	
	abstract protected function on_op($nick,$host,$chan,$onick);
	abstract protected function on_deop($nick,$host,$chan,$onick);
	abstract protected function on_vo($nick,$host,$chan,$vnick);
	abstract protected function on_devo($nick,$host,$chan,$vnick);
	abstract protected function on_hop($nick,$host,$chan,$hnick);
	abstract protected function on_dehop($nick,$host,$chan,$hnick);
	abstract protected function on_ban($nick,$host,$chan,$mask);
	abstract protected function on_unban($nick,$host,$chan,$mask);
	abstract protected function on_mode($nick,$host,$chan,$mode,$param); 
	abstract protected function on_demode($nick,$host,$chan,$mode,$param);
	abstract protected function on_join($nick,$host,$chan);
	abstract protected function on_part($nick,$host,$chan);		
	abstract protected function on_kick($nick,$host,$chan,$knick,$reason);
	abstract protected function on_quit($nick,$host,$reason);
	abstract protected function on_pubmsg($nick,$host,$chan,$text);
	abstract protected function on_privmsg($nick,$host,$text);
	abstract protected function on_load();
	abstract protected function on_unload();	
	abstract protected function on_init();
	

	/* clean up when the object is destroyed
	 */
	final protected function __destruct() {
		//$this->disp_msg("shutting down...");
		if ($this->sock) { fclose($this->sock); }
	}		
}

/* 	NexTimer Class
 * 	
 * 	version: 			1.0
 * 	last modified:		09/07/08
 * 	author:				blindk
 */	
class NexTimer {
	private $command;
	private $original_time;
	private $wait;
	public $loop;
	public $active;

	/* timer initialization
	 * 
	 * 	@param	string	command to execute
	 * 	@param	int		seconds to delay execution
	 * 	@param	bool	default: false, if true loop the timer
	 */
	public function __construct($cmd, $secs, $inf = false) {
		$this->command = $cmd;
		$this->wait = $secs;
		$this->loop = $inf;
		$this->original_time = time();
		$this->active = true;
	}
	
	/* used to reset the 'original_time' in case the timer is looped 
	 */
	public function reset_original_time() {
		$this->original_time = time();
	}
	
	/* return the command to be executed
	 * since Timer does not execute anything using eval(), it will pass it back to Nexus
	 * using this method
	 * 
	 * @return	string
	 */
	public function get_command() {
		return $this->command;
	}

	/* return the time left in the current timer
	 * 
	 * @return	int
	 */
	public function get_timeleft() {
		return ($this->wait - (time() - $this->original_time));
	}
	
	/* return the time this Timer is supposed to wait before expirying
	 * 
	 * @return int
	 */
	public function get_wait() {
		return $this->wait;
	}

	/* check if the timer has passed it's wait time and return bool accordingly
	 * 
	 * @return bool
	 */
	public function completed() {
		$diff = (time() - $this->original_time);
		if ($diff > $this->wait) { return true; }
		return false;
	}
	
	/* for future use?
	 */
	public function __destruct() {
		// cleanup
	}	
}

?>
