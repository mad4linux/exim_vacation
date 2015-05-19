<?php

/*********
 * Set exim4 vacation plugin
 *
 * Plugin to create vacation message files in the user's folder.
 * This file will hold the vacation message in plain text.
 * Exim4 needs to be configured to include a router and a 
 * transport for vacation messages that require the message file.
 *
 * @version 01
 * @author Daniel Savi
 * @url http://www.gaess.ch
 */
 
class exim_vacation extends rcube_plugin {
	public $task = 'settings'; // only run when settings are called
	public $rcmail;
	protected $username;
	protected $hostname;
	protected $mailboxes_root;
	protected $vacation_subfolder;
	protected $message_passive;
	protected $message_active;
	protected $vacation_folder;
//	protected $vacation_recipients;
	protected $message_status;
	protected $status_text;
	protected $textarray_content;

	public function init()  {
		// store some environment paths
		$this->rcmail = rcmail::get_instance();
		$this->add_texts('localization/', true);
		//register to settings hooks
		$this->add_hook('preferences_sections_list', array($this, 'preferences_sections_list'));
		$this->add_hook('preferences_list', array($this, 'preferences_list'));
		$this->add_hook('preferences_save', array($this, 'preferences_save'));
		// get user information
		$this->username = $_SESSION['username'];
		$this->hostname = $_SESSION['imap_host'];

		// load config.inc.php and get configuration
		$this->load_config();
		$this->mailboxes_root = $this->rcmail->config->get('exim_vacation_mailboxes_root');
		$this->vacation_subfolder = $this->rcmail->config->get('exim_vacation_subfolder');
		$this->message_passive = $this->rcmail->config->get('exim_vacation_msg_passive');
		$this->message_active = $this->rcmail->config->get('exim_vacation_msg_active');
		//set global vacation variables
		$this->vacation_folder = $this->mailboxes_root . $_SESSION['username'] . "/" . $this->vacation_subfolder . "/";
//		$this->vacation_recipients = $this->rcmail->config->get('exim_vacation_recipients_db');
	}

	function preferences_sections_list($param_list)  {
		$param_list['list']['exim_vacation'] = array(
		'id' => 'exim_vacation', 'section' => $this->gettext('settings_tab'),
		); //works, checked
    	return $param_list;
	}
 
 	function preferences_list($param_list) {
		if ($param_list['section'] != 'exim_vacation') {
			return $param_list;
		}
		
		$folder_errors = $this->check_vacation_folder();
		$this->set_status_message($folder_errors);
		
		
		if (!($folder_errors == 1) && !($folder_errors == 2) ) { // only show status information if folder not present or writable
			// text frame for options
			$param_list['blocks']['main']['name'] = $this->gettext('settings');

			$field_id = 'rcmfd_enable_exim_vacation';
			$field_text = $this->gettext('send_msg');
			$input    = new html_checkbox(array(
                    'name'  => '_enable_exim_vacation',
                    'id'    => $field_id,
                    'value' => $this->message_status,
            ));
			$param_list['blocks']['main']['options']['enable_vacation'] = array( 
				'title'   => html::label($field_id, $field_text),
				'content' => $input->show());

			$field_id = 'rcmfd_exim_vacation_text';
			$field_text = $this->gettext('vacation_text_field');
			$input = new html_textarea(array(
				'name'  => '_exim_vacation_text',
				'id'    => $field_id,
				'style' => 'width: 400px; height: 100px',
				'value' => $this->textarray_content,
				));
			$param_list['blocks']['main']['options']['vacation_text'] = array( 
			'title'   => html::label($field_id, $field_text),
			'content' => $input->show());
		}
			
		// status messages
		$param_list['blocks']['status']['name'] = $this->gettext('settings_status');
		
		$field_id = 'rcmfd_status';
		$input = new html_textarea(array(
			'name'  => 'exim_status_text',
			'id'    => $field_id,
			'style' => 'width: 400px; height: 100px',
			));
		$param_list['blocks']['status']['options']['vacation_status'] = array( 
			'title'   => html::label($field_id, $this->status_text),
			'content' => '');
			
//		print_r($param_list);
		return $param_list;
 	}
 
	function preferences_save($save_params) {
		if ($save_params['section'] == 'exim_vacation') {
			if ( isset($_POST['_enable_exim_vacation']) ) {
				$this->enable_vacation_message();
			}
			else {
				$this->disable_vacation_message();
			}
		}
		
		if (isset($_POST['_exim_vacation_text']) ) {
			$this->update_vacation_message($_POST['_exim_vacation_text']);
		}
		return $save_params;
	}

	function get_vacation_text($file) {
		//print_r($_SESSION);
		// execute command
		$command = "cat " . $this->vacation_folder . $file;
		unset($vacation_msg);
		exec($command, $vacation_msg);
		return $vacation_msg;
		
	} 
	
	function check_vacation_folder() {
		$command = "ls " . $this->vacation_folder;
		exec($command, $output, $errors);
		if ($errors == 0) { // folder exists
			if (!is_writable($this->vacation_folder)) { // folder not writable
				return 2;
			}
			$command = "ls " . $this->vacation_folder . $this->message_active;
			exec($command, $output, $errors);
			if ($errors == 0) { // message is active
				if(is_writeable($this->vacation_folder . $this->message_active)) {
					return 3;
				}
				else { // active message not writeable
					return 6;
				}
			}
			$command = "ls " . $this->vacation_folder . $this->message_passive;
			exec($command, $output, $errors);
			if ($errors == 0) { // message is inactive
				if(is_writeable($this->vacation_folder . $this->message_passive)) {
					return 4;
				}
				else { // passive message not writeable
					return 7;
				}
			}
			return 5; // no existing message found
		}
		else { //folder doesn't exist
			return 1;
		}
		
		return 0;
	}
	
	function set_status_message($code) {
		switch ($code) {
			case 0:
				$this->status_text = "";
				break;
			case 1:
				$this->status_text = $this->gettext('folder_missing');
				break;
			case 2:
				$this->status_text =  $this->gettext('folder_not_writeable');
				break;
			case 3:
				$this->status_text =  $this->gettext('message_status_active');
				$this->message_status = false;
				$vacation_txt = $this->get_vacation_text($this->message_active);
				$this->textarray_content = $this->textarray2linebreaks($vacation_txt);
				break;
			case 4:
				$this->status_text =  $this->gettext('message_status_passive');
				$this->message_status = true;
				$vacation_txt = $this->get_vacation_text($this->message_passive);
				$this->textarray_content = $this->textarray2linebreaks($vacation_txt);
				break;
			case 5:
				$this->message_status = true;
				$this->status_text =  $this->gettext('message_status_not_existing');
				break;
			case 6:
				$this->status_text = $this->gettext('message_status_active') . " - " . $this->gettext('file_not_writable') . " " . $this->vacation_folder . $this->message_active;
				break;
				$this->message_status = false;
				$vacation_txt = $this->get_vacation_text($this->message_active);
				$this->textarray_content = $this->textarray2linebreaks($vacation_txt);
				break;
			case 7:
				$this->status_text = $this->gettext('message_status_passive') . " - " . $this->gettext('file_not_writable') . " " . $this->vacation_folder . $this->message_passive;
				$this->message_status = true;
				$vacation_txt = $this->get_vacation_text($this->message_passive);
				$this->textarray_content = $this->textarray2linebreaks($vacation_txt);
				break;
		}
	}
	
	function textarray2linebreaks($txtarray) {
		$alllines = '';
		foreach ($txtarray as $line) {
				$alllines .= $line."\n";
		}
		return $alllines;
	}

	function enable_vacation_message() {
		$command = "ls " . $this->vacation_folder . $this->message_passive;
		exec($command, $output, $errors);
		if ($errors == 0) { // message is inactive
			$command = 'mv ' . $this->vacation_folder . $this->message_passive . " " . $this->vacation_folder . $this->message_active;
			exec($command, $output, $errors);
			// delete old recipients db -> doesn't work, exim file permissions to restricitive
			// $command = "ls " . $this->vacation_folder . $this->vacation_recipients;
			// exec($command, $output, $errors);
			// if ($errors == 0) { // recipients file exists
			//	$command = "rm " . $this->vacation_folder . $this->vacation_recipients;
			//}
		}
	}

	function disable_vacation_message() {
		$command = "ls " . $this->vacation_folder . $this->message_active;
		exec($command, $output, $errors);
		if ($errors == 0) { // message is active
			$command = 'mv ' . $this->vacation_folder . $this->message_active . " " . $this->vacation_folder . $this->message_passive;
			exec($command, $output, $errors);
		}
	}


	function update_vacation_message($text) {
		$text = $this->process_vacation_text($text);
		$command = "ls " . $this->vacation_folder . $this->message_active;
		exec($command, $output, $errors);
		if ($errors == 0) { // message is active
			file_put_contents($this->vacation_folder . $this->message_active, $text);
			return 0;
		}
		file_put_contents($this->vacation_folder . $this->message_passive, $text);
		
		return 0;
	}

	function process_vacation_text($text) {
		$text = str_replace("#!","",stripcslashes(strip_tags($text)));
		return $text;
	}
	
}