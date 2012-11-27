<?php

// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * This plugin is used to access owncloud files
 *
 * @since 2.0
 * @package    repository_owncloud
 * @copyright  2010 Dongsheng Cai {@link http://dongsheng.org}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
require_once($CFG->dirroot . '/repository/lib.php');
require_once($CFG->libdir.'/webdavlib.php');

/**
 * repository_owncloud class
 *
 * @since 2.0
 * @package    repository_owncloud
 * @copyright  2009 Dongsheng Cai {@link http://dongsheng.org}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class repository_owncloud extends repository {
    private $username;
    private $password;

    public function __construct($repositoryid, $context = SYSCONTEXTID, $options = array()) {
        parent::__construct($repositoryid, $context, $options);
        // set up owncloud client
        if (empty($this->options['owncloud_server'])) {
            return;
        }
        if ($this->options['owncloud_auth'] == 'none') {
            $this->options['owncloud_auth'] = false;
        }
        if (empty($this->options['owncloud_type'])) {
            $this->webdav_type = '';
        } else {
            $this->webdav_type = 'ssl://';
        }
        if (empty($this->options['owncloud_port'])) {
            $port = '';
            if (empty($this->webdav_type)) {
                $this->webdav_port = 80;
            } else {
                $this->webdav_port = 443;
                $port = ':443';
            }
        } else {
            $this->webdav_port = $this->options['owncloud_port'];
            $port = ':' . $this->webdav_port;
        }
        //$this->username = $this->options['owncloud_username'];
        //$this->password = $this->options['owncloud_password'];
        $this->username = optional_param('owncloud_user', '', PARAM_RAW);
        $this->password = optional_param('owncloud_pass', '', PARAM_RAW);

        $this->options['owncloud_username'] = $this->username;
        $this->options['owncloud_password'] = $this->password;

        $this->webdav_host = $this->webdav_type.$this->options['owncloud_server'].$port;
        $this->dav = new webdav_client($this->options['owncloud_server'], $this->options['owncloud_username'],
                $this->options['owncloud_password'], $this->options['owncloud_auth'], $this->webdav_type);
        $this->dav->port = $this->webdav_port;
        $this->dav->debug = false;
    }
    public function check_login() {
        return !empty($this->username);
    }
    /**
     * Define a search form
     *
     * @return array
     */
    public function print_login(){
        global $CFG;

        $ret = array();
        $username = new stdClass();
        $username->type = 'text';
        $username->id   = 'owncloud_username';
        $username->name = 'owncloud_user';
        $username->label = get_string('username').': ';
        $password = new stdClass();
        $password->type = 'password';
        $password->id   = 'owncloud_password';
        $password->name = 'owncloud_pass';
        $password->label = get_string('password').': ';

        $ret['login'] = array($username, $password);
        $ret['login_btn_label'] = get_string('login');
        $ret['login_btn_action'] = 'login';
        return $ret;
    }

    public function get_file($url, $title = '') {
        global $CFG;
        $url = urldecode($url);
        $path = $this->prepare_file($title);
        $buffer = '';
        if (!$this->dav->open()) {
            return false;
        }
        $owncloudpath = rtrim('/'.ltrim($this->options['owncloud_path'], '/ '), '/ '); // without slash in the end
        $this->dav->get($owncloudpath. $url, $buffer);
        $fp = fopen($path, 'wb');
        fwrite($fp, $buffer);
        return array('path'=>$path);
    }
    public function global_search() {
        return false;
    }
    public function get_listing($path='', $page = '') {
        global $CFG, $OUTPUT;
        $list = array();
        $ret  = array();
        $ret['dynload'] = true;
        $ret['nosearch'] = true;
        $ret['nologin'] = true;
        $ret['path'] = array(array('name'=>get_string('owncloud', 'repository_owncloud'), 'path'=>''));
        $ret['list'] = array();
        if (empty($this->dav->user)) {
            $this->dav->user   = optional_param('owncloud_user', '', PARAM_RAW);
            $this->dav->pass   = optional_param('owncloud_pass', '', PARAM_RAW);
        }
        if (!$this->dav->open()) {
            return $ret;
        }
        $owncloudpath = rtrim('/'.ltrim($this->options['owncloud_path'], '/ '), '/ '); // without slash in the end
        if (empty($path) || $path =='/') {
            $path = '/';
        } else {
            $chunks = preg_split('|/|', trim($path, '/'));
            for ($i = 0; $i < count($chunks); $i++) {
                $ret['path'][] = array(
                    'name' => urldecode($chunks[$i]),
                    'path' => '/'. join('/', array_slice($chunks, 0, $i+1)). '/'
                );
            }
        }
        $dir = $this->dav->ls($owncloudpath. urldecode($path));
        if (!is_array($dir)) {
            return $ret;
        }
        $folders = array();
        $files = array();
        foreach ($dir as $v) {
            if (!empty($v['lastmodified'])) {
                $v['lastmodified'] = strtotime($v['lastmodified']);
            } else {
                $v['lastmodified'] = null;
            }

            // Extracting object title from absolute path
            $v['href'] = substr(urldecode($v['href']), strlen($owncloudpath));
            $title = substr($v['href'], strlen($path));

            if (!empty($v['resourcetype']) && $v['resourcetype'] == 'collection') {
                // a folder
                if ($path != $v['href']) {
                    $folders[strtoupper($title)] = array(
                        'title'=>rtrim($title, '/'),
                        'thumbnail'=>$OUTPUT->pix_url(file_folder_icon(90))->out(false),
                        'children'=>array(),
                        'datemodified'=>$v['lastmodified'],
                        'path'=>$v['href']
                    );
                }
            }else{
                // a file
                $size = !empty($v['getcontentlength'])? $v['getcontentlength']:'';
                $files[strtoupper($title)] = array(
                    'title'=>$title,
                    'thumbnail' => $OUTPUT->pix_url(file_extension_icon($title, 90))->out(false),
                    'size'=>$size,
                    'datemodified'=>$v['lastmodified'],
                    'source'=>$v['href']
                );
            }
        }
        ksort($files);
        ksort($folders);
        $ret['list'] = array_merge($folders, $files);
        return $ret;
    }
    public static function get_instance_option_names() {
        return array('owncloud_type', 'owncloud_server', 'owncloud_port', 'owncloud_path', 'owncloud_username', 'owncloud_password', 'owncloud_auth');
    }

    public static function instance_config_form($mform) {
        $choices = array(0 => get_string('http', 'repository_owncloud'), 1 => get_string('https', 'repository_owncloud'));
        $mform->addElement('select', 'owncloud_type', get_string('owncloud_type', 'repository_owncloud'), $choices);
        $mform->addRule('owncloud_type', get_string('required'), 'required', null, 'client');

        $mform->addElement('text', 'owncloud_server', get_string('owncloud_server', 'repository_owncloud'), array('size' => '40'));
        $mform->addRule('owncloud_server', get_string('required'), 'required', null, 'client');

        $mform->addElement('text', 'owncloud_path', get_string('owncloud_path', 'repository_owncloud'), array('size' => '40'));
        $mform->addRule('owncloud_path', get_string('required'), 'required', null, 'client');

        $choices = array();
        $choices['none'] = get_string('none');
        $choices['basic'] = get_string('owncloudbasicauth', 'repository_owncloud');
        $choices['digest'] = get_string('ownclouddigestauth', 'repository_owncloud');
        $mform->addElement('select', 'owncloud_auth', get_string('authentication', 'admin'), $choices);
        $mform->addRule('owncloud_auth', get_string('required'), 'required', null, 'client');


        $mform->addElement('text', 'owncloud_port', get_string('owncloud_port', 'repository_owncloud'), array('size' => '40'));
        $mform->addElement('text', 'owncloud_username', get_string('owncloud_username', 'repository_owncloud'), array('size' => '40'));
        $mform->addElement('text', 'owncloud_password', get_string('owncloud_password', 'repository_owncloud'), array('size' => '40'));
    }
    public function supported_returntypes() {
        return (FILE_INTERNAL | FILE_EXTERNAL);
    }
}
