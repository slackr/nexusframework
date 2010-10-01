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
 * user identification is done by Nexus
 *  [cmd_char]verify [user] [password] must be issued to
 *  we just have to check for $this->ident[irc_nickname]
 */

require 'nexus.php';

class SampleBot extends Nexus {

	protected function on_load() {
		//
	}

	// on KEY
	protected function on_key($nick,$host,$chan,$key) {
		//
	}
	protected function on_unkey($nick,$host,$chan,$key) {
		//
	}	
	
	// on OP
	protected function on_op($nick,$host,$chan,$onick) {
		//
	}
	protected function on_deop($nick,$host,$chan,$onick) {
		//
	}	

	// on VOICE
	protected function on_vo($nick,$host,$chan,$vnick) {
		//
	}
	protected function on_devo($nick,$host,$chan,$vnick) {
		//
	}	

	// on HOP
	protected function on_hop($nick,$host,$chan,$hnick) {
		//
	}
	protected function on_dehop($nick,$host,$chan,$hnick) {
		//
	}		

	// on BAN
	protected function on_ban($nick,$host,$chan,$mask) {
		//
	}
	protected function on_unban($nick,$host,$chan,$mask) {
		//
	}	

	// on MODE
	protected function on_mode($nick,$host,$chan,$mode,$param) {
		//
	}		
	protected function on_demode($nick,$host,$chan,$mode,$param) {
		//
	}	

	protected function on_join($nick,$host,$chan) {
		//
	}
	
	protected function on_part($nick,$host,$chan) {
		//
	}	
	
	protected function on_kick($nick,$host,$chan,$knick,$reason) {
		//
	}	
	
	protected function on_nick($nick,$host,$newnick) {
		//
	}		

	protected function on_quit($nick,$host,$reason) {
		//
	}
	
	// on PUBMSG
	protected function on_pubmsg($nick,$host,$chan,$text) {
		//
	}

	// on PRIVMSG
	protected function on_privmsg($nick,$host,$text) {
		//
	}
}

$sample = new SampleBot();
$sample->start();
?>