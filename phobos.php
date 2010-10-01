#!/usr/local/bin/php -q
<?

/* This file is part of Nexus IRC Framework.
 * 
 * Phobos is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 * 
 * Phobos is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 * You should have received a copy of the GNU General Public License
 * along with Phobos.  If not, see <http://www.gnu.org/licenses/>.
 * 
 * 
 * 
 * user flags:
 * 	+a 	- admin, commands: +/-chan, join, part, kick, write
 * 	+o 	- global op
 * 	+v	- global voice
 * 	+h	- global halfop (if supported obviously...)
 *  +c	- can execute commands in the channel (such as: uptime, ontime)
 *  +j	- can make the client add a new channel to *.chan file
 *  +p	- user is protected, immune to flood protection
 * 
 * user identification is done by Nexus
 *  [cmd_char]verify [user] [password] must be issued to
 *  we just have to check for $this->ident[irc_nickname]
 */

require 'nexus.php';

class Phobos extends Nexus {

	protected function on_load() {
		/* channel settings hierarchy:
		 * config file -> chanfile -> $chan_defaults
		 */
		$this->chan_defaults = array( 	'autoop' 			=> 1,	
									 	'autovoice' 		=> 1,	
									 	'autohop' 			=> 1,											
										'key'				=> '',
										
										'pubflood'			=> 0,
										'pubflood_lines'	=> 6,
										'pubflood_secs'		=> 4,
										'pubflood_action'	=> 'k',	
										'pubflood_unban'	=> 300,										
										
										'massdeop'			=> 1,
										'massdeop_lines'	=> 4,
										'massdeop_secs'		=> 2,
										'massdeop_action'	=> 'k',	
										// attempt to reop victims
										'massdeop_reop'		=> 1,
										//in seconds, how long to wait before removing ban if action='kb'	
										'massdeop_unban'	=> 300,																					
										
										'joinflood'			=> 1,	
										'joinflood_lines'	=> 10,
										'joinflood_secs'	=> 3,
										// mode to set upon join flood
										'joinflood_action'	=> '+im',
										// in seconds, how long to wait before removing action modes
										'joinflood_expire'	=> 300,	
										
										'kickflood'			=> 1,	
										'kickflood_lines'	=> 5,
										'kickflood_secs'	=> 3,
										// mode to set upon join flood
										'kickflood_action'	=> 'k',
										// in seconds, how long to wait before removing ban if action='kb'
										'kickflood_unban'	=> 300,		
										
										// immunity
									 	'ignoreops' 		=> 1,
									 	'ignorevoices' 		=> 0,
									 	'ignorehops' 		=> 0								
									);
		
		/* this regular expressions array is passed to preg_match
		 * to check our config options for validity
		 * 
		 * these are added to Nexus' config restrictions
		 * note the +=
		 */
		$this->config_regex += array(	"joinflood" 	=> "/[01]/s",
										"pubflood"		=> "/[01]/s",
										"massdeop"		=> "/[01]/s",
										"kickflood"		=> "/[01]/s",
										"ignoreops"		=> "/[01]/s",
										"ignorevoices"	=> "/[01]/s",
										"ignorehops"	=> "/[01]/s",
										"seen"			=> "/[01]/s",
										"cmd_char"		=> '/[^a-z0-9]/'					
										);
										
		$this->massdeop_victims = array();
		$this->seen_list = array();
		
		// to make sure the bot does not flood out with too many attempts to regain ops on the same channel
		$this->regain_ops_completed = array();
		
		$this->userflags = 'aohvcjpn';
		$this->default_config = 'phobos.conf';
		$this->version_reply = 'phobos 1.0 (nexus)';

	}
	
	protected function on_init() {
		$load_seen = $this->load_seenfile($this->client['data_dir'].$this->client['seen_file']);
		if ($load_seen) { $this->disp_msg($load_seen); }			

		/* spawn timer to save seen file
		 */
		if ($this->client['save_data']) { $this->timer('save_seen_file','write_seenfile()',$this->client['save_data']*60,true); }
	}
	
	/* trying to save the notify file before unloading the program
	 */
	protected function on_unload() {
		$this->write_seenfile();
	}

	/* write seen file from memory to disk
	 * 
	 * @return bool
	 */
	protected function write_seenfile() {
		if ($this->client['seen'] == 1) {
			$this->disp_msg("writing seen file... ","wrt",$nonl = true);
			
			$seenfile = $this->client['data_dir'].$this->client['seen_file'];
			if (!$fp = @fopen($seenfile,"w")) {
				$this->disp_error("error! (file: ".$seenfile.")","wrt");
				return false;
			}
			$line = ""; $count = 0; $skipped = 0;
			foreach ($this->seen_list as $key => $val) {
				if ((time() - $this->gettok($data,2)) > 13515200) {
					$line .= $key." ".$val['time']." ".$val['action']."\n";
					$count++;
				}
				else { $skipped++; }
			}
			fputs($fp,$line,strlen($line));
			fclose($fp);
			$this->disp_msg("done. ($count entries to ".$seenfile.", $skipped skipped)","...");
		}
		return true;		
	}
	
	/* load user file from disk
	 * 
	 * @param 	string	seen file path (absolute or relative) prefixed by datadir value
	 * @return 	int		value is 1 if all succeeded
	 * @return	string	will return string if there was an error		
	 */
	private function load_seenfile($file) {
		if (!$fp = @fopen($file,'r')) { return "could not open seen file: $file"; }
		$line = 0; $count = 0; $skipped = 0;
		while (!feof($fp)) {
			$line++;
			$data = rtrim(fgets($fp,512));
			if ($data) { //don't test blank lines
				if (!preg_match("/^.+!.+@.+$]+/si",$this->gettok($data,1)) 			 ||
					!preg_match("/^[0-9]$/si",$this->gettok($data,2))) { 
						$this->disp_msg("warning: skipped invalid record in seen file ($file:$line)");
						$skipped++;
				}
				else if ((time() - $this->gettok($data,2)) > 13515200) {
					$this->disp_msg("warning: skipped really old record (+6mon) in seen file ($file:$line)");
					$skipped++;
				}
				else {
					$tmp_splitseen = explode(" ",3);
					$this->seen_file[$tmp_splitseen[0]] = array( 	'time' => $tmp_splitseen[1],
																	'action' => $tmp_splitseen[2]
																);
					$count++;
				}
			}
		}
		$this->disp_msg("loaded $count seen records from '$file' ($skipped skipped)");
		fclose($fp);
		return 1;
	}	

	// on KEY
	protected function on_key($nick,$host,$chan,$key) {
	}
	protected function on_unkey($nick,$host,$chan,$key) {
	}	
	
	// on OP
	protected function on_op($nick,$host,$chan,$onick) {
		//$this->disp_msg("mode: $nick sets mode $chan +o $onick");
	}
	protected function on_deop($nick,$host,$chan,$onick) {
		if ($this->settings[$chan]['massdeop'] == 1 &&
			!$this->has_flag('p',$nick) && 
			$this->me != $nick && 
			$this->me != $onick &&
			$this->isop($this->me,$chan)) {
				$this->massdeop_flooders[$chan][$nick][count]++;
				$timer_name = $this->rcrypt($chan.$nick."mdeop");
				if ($this->settings[$chan]['massdeop_reop'] && sizeof($this->massdeop_victims[$chan])-1 <= 16) {
					$this->massdeop_victims[$chan][] = $onick; 
				}
				
				if ($this->massdeop_flooders[$chan][$nick][count] == $this->settings[$chan]['massdeop_lines']) {
					switch ($this->settings[$chan]['massdeop_action']) {
						case "-o": $cmd = "mode $chan -o $nick"; break;
						case "kb": 
							$uhost = "*!*".$this->gettok($host,2,'@');
							$cmd = "kick $chan $nick :deop abuse\r\nmode $chan +b $uhost";
						break;
						default: $cmd = "kick $chan $nick :deop abuse"; break;
					}
					$this->send($cmd);
					if (sizeof($this->massdeop_victims[$chan]) > 0) {
						$this->timer(null,'cmd_op("'.$chan.'",$this->massdeop_victims["'.$chan.'"]);unset($this->massdeop_victims["'.$chan.'"])',$this->settings[$chan]['massdeop_secs']);
					}
					unset($cmd,$uhost);
				}
				else if (!$this->is_timer($timer_name)) {
					$this->timer($timer_name,"massdeop_flooders['$chan']['$nick'][count] = 0",$this->settings[$chan]['massdeop_secs']);
				}
		}
	}	

	// on VOICE
	protected function on_vo($nick,$host,$chan,$vnick) {
		//$this->disp_msg("mode: $nick sets mode $chan +v $vnick");
	}
	protected function on_devo($nick,$host,$chan,$vnick) {
		//$this->disp_msg("mode: $nick sets mode $chan -v $vnick");
	}	

	// on HOP
	protected function on_hop($nick,$host,$chan,$hnick) {
		//$this->disp_msg("mode: $nick sets mode $chan +h $hnick");
	}
	protected function on_dehop($nick,$host,$chan,$hnick) {
		//$this->disp_msg("mode: $nick sets mode $chan -h $hnick");
	}		

	// on BAN
	protected function on_ban($nick,$host,$chan,$mask) {
		//$this->disp_msg("mode: $nick sets mode $chan +b $mask");
		if ($this->iswm($mask,$this->me."!".$this->host) && $this->isop($this->me,$chan)) { 
			$this->send("mode $chan -ob $nick $mask");
		}
	}
	protected function on_unban($nick,$host,$chan,$mask) {
		//
	}	

	// on MODE
	protected function on_mode($nick,$host,$chan,$mode,$param) {
		//$this->disp_msg("mode: $nick sets mode $chan +$mode $param");
	}		
	protected function on_demode($nick,$host,$chan,$mode,$param) {
		//
	}	

	protected function on_join($nick,$host,$chan) {
		if ($this->isop($this->me,$chan)) {

			if ($this->settings[$chan]['joinflood'] == 1 &&
				!$this->has_flag('p',$nick) && 
				$this->me != $nick) {
					$this->join_flooders[$chan][count]++;
					$timer_name = $this->rcrypt($chan."jflood");			
					if ($this->join_flooders[$chan][count] == $this->settings[$chan]['joinflood_lines']) {
						$this->timer(null,"MODE $chan ".$this->reverse_modes($this->settings[$chan]['joinflood_action']),$this->settings[$chan]['joinflood_expire']);
						$this->send("MODE $chan ".$this->settings[$chan]['joinflood_action']);
					}
					else if (!$this->is_timer($timer_name)) {
						$this->timer($timer_name,"join_flooders['$chan'][count] = 0",$this->settings[$chan]['joinflood_secs']);
					}
			}
			
			if ($this->settings[$chan]['autoop'] && $this->has_flag('o',$nick)) {
				$this->timer(null,'cmd_op("'.$chan.'","'.$nick.'")',rand(2,5));
			}
			else if ($this->settings[$chan]['autohop'] && $this->has_flag('h',$nick)) {
				$this->timer(null,'cmd_op("'.$chan.'","'.$nick.'","h")',rand(2,5));
			}			
			else if ($this->settings[$chan]['autovoice'] && $this->has_flag('v',$nick)) {
				$this->timer(null,'cmd_op("'.$chan.'","'.$nick.'","v")',rand(2,5));
			}
		}
		if ($this->me != $nick) {
			$this->seen_update_record("$nick!$host","joining $chan");		
		}
	}
	
	protected function on_part($nick,$host,$chan) {
		if ($this->me != $nick) {
			if (sizeof($this->chans[$chan]) == 1 && !$this->isop($this->me,$chan)) { $this->send("part $chan\r\njoin $chan"); }
			$this->seen_update_record("$nick!$host","leaving $chan");			
		}	
	}	
	
	protected function on_kick($nick,$host,$chan,$knick,$reason) {
		if ($this->settings[$chan]['kickflood'] == 1 &&
			!$this->has_flag('p',$nick) && 
			$this->me != $nick &&
			$this->me != $knick &&
			$this->isop($this->me,$chan)) {
				$this->kick_flooders[$chan][$nick][count]++;
				$timer_name = $this->rcrypt($chan.$nick."kflood");			
				if ($this->kick_flooders[$chan][$nick][count] == $this->settings[$chan]['kickflood_lines']) {
					switch ($this->settings[$chan]['kickflood_action']) {
						case "-o": $cmd = "mode $chan -o $nick"; break;
						case "kb": 
							$uhost = "*!*".$this->gettok($host,2,'@');
							$cmd = "kick $chan $nick :mass kick\r\nmode $chan +b $uhost";
						break;
						default: $cmd = "kick $chan $nick :mass kick"; break;
					}
					$this->send($cmd);
					if ($uhost && $this->settings[$chan]['kickflood_unban'] > 0) {
						$this->timer(null,'send("MODE '.$chan.' -b '.$uhost.'")',$this->settings[$chan]['kickflood_unban']);
					}
					unset($cmd,$uhost);
				}
				else if (!$this->is_timer($timer_name)) {
					$this->timer($timer_name,"kick_flooders['$chan']['$nick'][count] = 0",$this->settings[$chan]['kickflood_secs']);
				}
		}
		if ($this->me != $nick && $this->me != $knick) {
			if (sizeof($this->chans[$chan]) == 1 && !$this->regain_ops_completed[$chan] && !$this->isop($this->me,$chan)) { 
				$this->send("part $chan\r\njoin $chan"); 
				$this->regain_ops_completed[$chan] = true;
			}
		}				
		if ($this->me != $nick) {
			$this->seen_update_record("$nick!$host","getting kicked from $chan by $nick ($reason)");
		}
	}	
	
	protected function on_nick($nick,$host,$newnick) {
		if ($this->me != $nick) {
			$this->seen_update_record("$nick!$host","changing nick to $newnick");
		}
	}		

	protected function on_quit($nick,$host,$reason) {
		foreach ($this->chans as $key => $val) {
			if ($this->me != $nick) {
				if (sizeof($this->chans[$key]) == 1 && !$this->isop($this->me,$key)) { $this->send("part $key\r\njoin $key"); }
				$this->seen_update_record("$nick!$host","quitting the server");
			}
		}	
	}
	
	// on PUBMSG
	protected function on_pubmsg($nick,$host,$chan,$text) {
		if (($this->isop($nick,$chan) && $this->settings[$chan]['ignoreops']) ||
			($this->isop($nick,$chan,'+') && $this->settings[$chan]['ignorevoices']) ||
			($this->isop($nick,$chan,'%') && $this->settings[$chan]['ignorehops'])) { 
			$noflood = true; 
		}
		
		if ($this->settings[$chan]['pubflood'] == 1 &&
			!$this->has_flag('p',$nick) && 
			$this->me != $nick && 
			$this->isop($this->me,$chan) &&
			!$noflood) {
				
			$this->pub_flooders[$chan][$nick][count]++;
			$timer_name = $this->rcrypt($chan.$nick."pubflood");	
			if ($this->pub_flooders[$chan][$nick][count] == $this->settings[$chan]['pubflood_lines']) {
				switch ($this->settings[$chan]['pubflood_action']) {
					case "kb": 
						$uhost = "*!*".$this->gettok($host,2,'@');		
						$cmd = "kick $chan $nick :flood\r\nmode $chan +b $uhost"; 	
					break;
					default: $cmd = "kick $chan $nick :flood"; break;
				}
				$this->send($cmd);
				if ($uhost && $this->settings[$chan]['pubflood_unban'] > 0) {
					$this->timer(null,'send("MODE '.$chan.' -b '.$uhost.'")',$this->settings[$chan]['pubflood_unban']);
				}
				unset($cmd,$uhost);
			}
			else if (!$this->is_timer($timer_name)) {
				$this->timer($timer_name,"pub_flooders['$chan']['$nick'][count] = 0",$this->settings[$chan]['pubflood_secs']);
			}
		}		
		if ($text[0] == $this->client['cmd_char'] && 
			isset($this->ident[$nick]) &&
			$this->has_flag('c',$nick) &&
			!$this->is_timer('pub_command_throttle')) {
				
			$cmd = substr($this->gettok($text,1),1,strlen($this->gettok($text,1))-1);
			
			$this->timer('pub_command_throttle',null,3);
			
			switch ($cmd) {
				case "uptime":
					$this->send("PRIVMSG $chan :up ".$this->duration($this->uptime));					
				break;
				case "ontime":			
					$this->send("PRIVMSG $chan :online ".$this->duration($this->ontime));					
				break;	
				case ($cmd == "up" || $cmd == "op"):
					$ovh = array('o' => '@','v' => '+','h' => '%');
					if ($this->has_flag('v',$nick)) { $mode = 'v'; }
					if ($this->has_flag('h',$nick)) { $mode = 'h'; }
					if ($this->has_flag('o',$nick)) { $mode = 'o'; }
					if ($mode && 
						!$this->isop($nick,$chan,$ovh[$mode]) &&
						$this->isop($this->me,$chan)) { 
						
						$this->cmd_op($chan,$nick,$mode);
						unset($mode);
					}	
				break;	
				case ($cmd == "kick" || $cmd == "k"):
					if ($this->isop($this->me,$chan)) {
						for ($x = 3; $this->gettok($text,$x);$x++) { $reason .= $this->gettok($text,$x)." "; }
						foreach ($this->chans[$chan] as $key => $val) {
							if (($this->iswm($this->gettok($text,2),$key)	||
								$this->iswm($this->gettok($text,2),$val['host'])) &&
								$this->me != $key) {
								$this->send("KICK $chan ".$key." :$reason");
							}
						}
						unset($mode,$reason);
					}	
				break;					
				case ($cmd == "write" || $cmd == "wrt"):
					if ($this->has_flag('a',$nick)) {
						if (!$this->is_timer('pub_wrt_throttle')) {
							$what = $this->gettok($text,2); $wrt = "";
							switch ($what) {
								case ($what == "c" || $what == "chan"):
									$this->write_chanfile(); 
									$wrt = "chan";
								break; 
								case ($what == "u" || $what == "user"):
									$this->write_userfile(); 
									$wrt = "user";
								break;
								case ($what == "s" || $what == "seen"):
									$this->write_seenfile(); 
									$wrt = "seen";
								break;								
								case ($what == "*" || $what == "all"):
									$this->write_all();
									$this->write_seenfile();
									$wrt = "*";
								break; 			
							}
							if ($what) { 
								$this->send("PRIVMSG $chan :writing $wrt file...");
								$this->timer('pub_wrt_throttle',null,60); 
							}
						}
						else { 
							$this->send("PRIVMSG $chan :please wait ".
								$this->timers['pub_wrt_throttle']->get_timeleft()
								."s before issuing another write command"); 
						}
					}
				break;	
			}
		}
		if ($text[0] == $this->client['cmd_char'] &&
			!$this->is_timer('pub_command_throttle')) {
				
			$cmd = substr($this->gettok($text,1),1,strlen($this->gettok($text,1))-1);
			
			$this->timer('pub_command_throttle',null,3);
			
			switch ($cmd) {
				case ($cmd == "seen" && $this->client['seen']):
					$seen_found = false; 
					$tmp_usernotified = false;
					$tmp_seenwho = $this->gettok($text,2);
					foreach ($this->chans as $key => $val) {
						if ($this->chans[$key][$tmp_seenwho]) {
							if (!isset($this->chans[$chan][$tmp_seenwho])) {
								$this->send("PRIVMSG $tmp_seenwho :hey, $nick!$host is looking for you on $chan"); 
								$tmp_usernotified = true;
							}
							$this->send("PRIVMSG $chan :$tmp_seenwho is on $key".($tmp_usernotified ? " (user was notified)":"")); 
							$seen_found = true;
							break;
						}
					}					
					if (!$seen_found) {
						foreach ($this->seen_list as $key => $val) {
							if ($this->iswm($tmp_seenwho,$key)) {
								$this->send("PRIVMSG $chan :last seen $key ".$this->duration($this->seen_list[$key]['time'])." ago ".$this->seen_list[$key]['action']);
								break;
							}
						}
					}
				break;
			}
		}
		unset($noflood);
	}

	// on PRIVMSG
	protected function on_privmsg($nick,$host,$text) {
		// user is sending us a command
		if ($text[0] == $this->client['cmd_char'] &&
			isset($this->ident[$nick])) {			
			$cmd = substr($this->gettok($text,1),1,strlen($this->gettok($text,1)));
			switch ($cmd) {				
				case "+chan":
					if ($this->has_flag('j',$nick)) {
						$ch = $this->gettok($text,2);
						$ky = $this->gettok($text,3);
						if ($ch[0] == '#') {
							if (!isset($this->settings[$ch])) {
								$this->settings[$ch] = $this->chan_defaults;
								if ($ky) { $this->settings[$ch]['key'] = $ky; }
								if (!isset($this->chans[$ch])) { $this->cmd_join($ch); }
							}
							else { $this->send("PRIVMSG $nick :channel already exists '$ch'"); }
						}
						else { $this->send("PRIVMSG $nick :invalid channel '$ch'"); }
					}
				break;	
				case "join":
					if ($this->has_flag('j',$nick)) {
						$ch = $this->gettok($text,2);
						if ($ch[0] == '#') { 
								if (!isset($this->chans[$ch])) { $this->cmd_join($ch); } 
								else { $this->send("PRIVMSG $nick :i'm already on '$ch'"); }
						}
						else { $this->send("PRIVMSG $nick :invalid channel '$ch'"); }
					}
				break;	
				case "-chan":
					if ($this->has_flag('j',$nick)) {
						$ch = $this->gettok($text,2);
						if ($ch[0] == '#') {
							if (!$this->settings[$ch]['static']) {
								if (isset($this->settings[$ch])) {
									unset($this->settings[$ch]);
									if (isset($this->chans[$ch])) { $this->send("PART $ch :-chan"); }
									$this->send("PRIVMSG $nick :'$ch' was removed from memory");
								}
								else { $this->send("PRIVMSG $nick :'$ch' is not in memory"); }
							}
							else { $this->send("PRIVMSG $nick :cannot remove static channel '$ch'"); }
						}
						else { $this->send("PRIVMSG $nick :invalid channel '$ch'"); }
					}
				break;	
				case "part":
					if ($this->has_flag('j',$nick)) {
						$ch = $this->gettok($text,2);
						if ($ch[0] == '#') { 
								if (isset($this->chans[$ch])) { $this->send("PART $ch"); }
								else { $this->send("PRIVMSG $nick :i'm not on '$ch'"); }
						}
						else { $this->send("PRIVMSG $nick :invalid channel '$ch'"); }
					}
				break;						
			}	
		}
	}

	protected function reverse_modes($modes) {
		$plus = (($modes[0] != '-' && $modes[0] != '+') ? "+" : "");
		for ($x = 0; $modes[$x]; $x++) { 
			if ($modes[$x] == '+') { $modes[$x] = '-'; }
			else if ($modes[$x] == '-') { $modes[$x] = '+'; } 
		}
		return $plus.$modes;
	}
	
	protected function remove_var(&$var) {
		$var = null;
	}
	
	protected function seen_update_record($item,$action) {
		$this->seen_list[$item]['time'] = time();
		$this->seen_list[$item]['action'] = $action;
		$this->disp_msg("added seen record: $item $action");
	}
}

$phobos = new Phobos();
$phobos->start();
?>