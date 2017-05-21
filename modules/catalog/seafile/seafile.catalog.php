<?php
/* vim:set softtabstop=4 shiftwidth=4 expandtab: */
/**
 *
 * LICENSE: GNU Affero General Public License, version 3 (AGPLv3)
 * Copyright 2001 - 2016 Ampache.org
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 *
 */

/**
 * Seafile Catalog Class
 *
 * This class handles all actual work in regards to remote Seafile catalogs.
 *
 */

use Seafile\Client\Resource\Directory;
use Seafile\Client\Resource\File;
use Seafile\Client\Resource\Library;
use Seafile\Client\Http\Client;


class Catalog_Seafile extends Catalog
{
    private $version        = '000001';
    private $type           = 'seafile';
    private $description    = 'Seafile Remote Catalog';

    public $server_uri;
    public $api_key;
    public $library_name; // TODO

    /**
     * get_description
     * This returns the description of this catalog
     */
    public function get_description()
    {
        return $this->description;
    } // get_description

    /**
     * get_version
     * This returns the current version
     */
    public function get_version()
    {
        return $this->version;
    } // get_version

    /**
     * get_type
     * This returns the current catalog type
     */
    public function get_type()
    {
        return $this->type;
    } // get_type

    /**
     * get_create_help
     * This returns hints on catalog creation
     */
    public function get_create_help()
    {
        $help = "<ul><li>" . T_("Install a Seafile server per its documentation (https://www.seafile.com/)") . "</li>" .
            "<li>" . T_("") . "</li>" .
            "<li>" . T_("If desired, create a user for Ampache and share with it the Library you want it to use. Otherwise, you can use your own credentials (not recommended).") . "</li>" .
            "<li>" . T_("Enter username and password below.") . "</li>" .
            "<li>&rArr;&nbsp;" . T_("After preparing the catalog with pressing the 'Add catalog' button,<br /> you have to 'Make it ready' on the catalog table.") . "</li></ul>";
        return $help;
    } // get_create_help

    /**
     * is_installed
     * This returns true or false if remote catalog is installed
     */
    public function is_installed()
    {
        $sql        = "SHOW TABLES LIKE 'catalog_seafile'";
        $db_results = Dba::query($sql);

        return (Dba::num_rows($db_results) > 0);
    } // is_installed

    /**
     * install
     * This function installs the remote catalog
     */
    public function install()
    {
        $sql = "CREATE TABLE `" . 'catalog_' . $this->get_type() . "` (`id` INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY , " .
            "`server_uri` VARCHAR( 255 ) COLLATE utf8_unicode_ci NOT NULL , " .
            "`api_key` VARCHAR( 100 ) COLLATE utf8_unicode_ci NOT NULL , " .
            "`library_name` VARCHAR( 255 ) COLLATE utf8_unicode_ci NOT NULL , " .
            "`catalog_id` INT( 11 ) NOT NULL" .
            ") ENGINE = MYISAM DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci";
        $db_results = Dba::query($sql);

        return true;
    }

    public function catalog_fields()
    {
        $fields['server_uri'] = array('description' => T_('Server URI'), 'type'=>'text', 'value' => 'https://seafile.example.org/');
        $fields['library_name'] = array('description' => T_('Library Name'), 'type'=>'text');

        return $fields;
    }

    public function isReady()
    {
        return (!empty($this->api_key));
    }

    public function show_ready_process()
    {
        $this->requestCredentials();
    }

    protected function requestCredentials()
    {
        echo '<br />' . T_('Enter Seafile Username and Password') . '<br />';
        echo "<form action='" . get_current_path() . "' method='post' enctype='multipart/form-data'>";
        if ($_REQUEST['action']) {
            echo "<input type='hidden' name='action' value='" . scrub_in($_REQUEST['action']) . "' />";
            echo "<input type='hidden' name='catalogs[]' value='" . $this->id . "' />";
        }
        echo "<input type='hidden' name='perform_ready' value='true' />";

        echo "Username/Email: <input type='text' name='seafileusername' /> ";
        echo "Password: <input type='password' name='seafilepassword' /> ";

        echo "<input type='submit' value='" . T_("Connect to Seafile") . "' />";
        echo "</form>";
        echo "<br />";
    }

    public function perform_ready()
    {
        $password = $_REQUEST['seafilepassword'];
        $username = $_REQUEST['seafileusername'];

        $this->requestAuthToken($username, $password);
    }

    protected function requestAuthToken($username, $password)
    {
        $data = array('username' => $username, 'password' => $password);

        // use key 'http' even if you send the request to https://...
        $options = array(
            'http' => array(
                'header'  => "Content-type: application/x-www-form-urlencoded\r\n",
                'method'  => 'POST',
                'content' => http_build_query($data)
            )
        );
        $context  = stream_context_create($options);
        $result = file_get_contents($this->server_uri. (substr($this->server_uri, -1) == '/' ? '' : '/') . 'api2/auth-token/', false, $context);

        if (!$result) {
            AmpError::add('general', T_('Error: Could not authenticate against Seafile API.'));
        }

        $token = json_decode($result);

        $this->api_key = $token->token;

        debug_event('seafile_catalog', 'Retrieved API token for user ' . $username . '.', 1);

        $sql = 'UPDATE `' . 'catalog_' . $this->get_type() . '` SET `api_key` = ? WHERE `catalog_id` = ?';
        Dba::write($sql, array($this->api_key, $this->id));
    }

    /**
     * create_type
     *
     * This creates a new catalog type entry for a catalog
     */
    public static function create_type($catalog_id, $data)
    {
        $server_uri = trim($data['server_uri']);
        $api_key    = trim($data['api_key']);
        $library_name = trim($data['library_name']);

        if (!strlen($server_uri)) {
            AmpError::add('general', T_('Error: Seafile Server URL is required.'));
            return false;
        }

        if (!strlen($library_name)) {
            AmpError::add('general', T_('Error: Seafile Server Library Name is required.'));
            return false;
        }

        $sql = 'INSERT INTO `catalog_seafile` (`server_uri`, `api_key`, `library_name`, `catalog_id`) VALUES (?, ?, ?, ?)';
        Dba::write($sql, array($server_uri, $api_key, $library_name, $catalog_id));
        return true;
    }

    /**
     * Constructor
     *
     * Catalog class constructor, pulls catalog information
     */
    public function __construct($catalog_id = null)
    {
        if ($catalog_id) {
            $this->id = intval($catalog_id);
            $info     = $this->get_info($catalog_id);

            foreach ($info as $key=>$value) {
                $this->$key = $value;
            }
        }
    }

    private $client;
    private $library;

    private function createClient()
    {
        if(!$this->isReady()) {
            AmpError::add('general', 'Seafile Catalog is not ready.');
            $this->client = null;
        }
        else {
            $client = new Client([
                'base_uri' => $this->server_uri,
                'debug' => true,
                'headers' => [
                    'Authorization' => 'Token ' . $this->api_key
                ]
            ]);

            $this->client = array(
                'Libraries' => new Library($client),
                'Directories' => new Directory($client),
                'Files' => new File($client),
                'Client' => $client
            );
        }
    }

    private function findLibrary() {
        $libraries = $this->client['Libraries']->getAll();

        $library = array_filter($libraries, function($lib) { return $lib->name == $library_name; });

        if(count($library) == 0) {
            AmpError::add('general', sprintf(T_('No media updated: could not find Seafile library called "%s"')), $library_name);
        }

        $this->library = $library[0];
    }

    public function to_virtual_path($path, $filename)
    {
        return $library->name . '|' . $path . '|' . $file;
    }

    public function from_virtual_path($file_path)
    {
        $arr = explode('|', $file_path);

        return array('path' => $arr[1], 'filename' => $arr[2]);
    }

    public function get_rel_path($file_path)
    {
        return $this->from_virtual_path($file_path);
    }

    /**
     * add_to_catalog
     * this function adds new files to an
     * existing catalog
     */
    public function add_to_catalog($options = null)
    {
        // Prevent the script from timing out
        set_time_limit(0);

        if (!defined('SSE_OUTPUT')) {
            UI::show_box_top(T_('Running Seafile Remote Update') . '. . .');
        }
        $this->add_from_library();

        if (!defined('SSE_OUTPUT')) {
            UI::show_box_bottom();
        }

        $this->update_last_add();
        return true;
    }

    /**
     * add_from_library
     *
     * Pulls the data from a remote catalog and adds any missing songs to the
     * database.
     */
    private function add_from_library()
    {
        $this->createClient();

        if($this->client != null)
        {
            $this->findLibrary();

            $count = $this->add_from_directory('/');

            UI::update_text('', sprintf(T_('Catalog Update Finished.  Total Media: [%s]'), $count));

            if ($count == 0) {
                AmpError::add('general', T_('No media updated, do you respect the patterns?'));
            }

                    return true;
        }

        return false;
    }

    /**
     * add_from_directory
     *
     * Recurses through directories and pulls out all media files
     */
    public function add_from_directory($path)
    {
        $directoryItems = $this->client['Directories']->getAll($this->library, $path);

        $count = 0;

        if ($directoryItems !== null && count($directoryItems) > 0) {
            foreach ($directoryItems as $item) {
                if ($item->type == 'dir') {
                    $count += $this->add_from_directory($path . $item->name . '/');
                } else if ($item->type == 'file') {
                    $count += $this->add_file($item, $path);
                }
            }
        }

        return $count;
    }

    public function add_file($file, $path)
    {
        $filesize = $file->size;

        if ($file->size > 0) {
            $is_audio_file = Catalog::is_audio_file($file->name);
            $is_video_file = Catalog::is_video_file($file->name);

            if(!$is_audio_file && !$is_video_file) {
                debug_event('read', $data['path'] . " ignored, unknown media file type", 5);
            }

            if ($is_audio_file && count($this->get_gather_types('music')) > 0) {
                $result = $this->insert_song($file, $path);

                if($result)
                    return 1;
            } else if ($is_video_file && count($this->get_gather_types('video')) > 0) {
                // TODO $this->insert_video()
                return 0;
            } else {
                debug_event('read', $data['path'] . " ignored, bad media type for this catalog.", 5);
            }
        } else {
            debug_event('read', $data['path'] . " ignored, 0 bytes", 5);
        }

        return 0;
    }

    /**
     * _insert_local_song
     *
     * Insert a song that isn't already in the database.
     */
    private function insert_song($file, $path)
    {
        if ($this->check_remote_song($this->to_virtual_path($path, $file->name))) {
            debug_event('seafile_catalog', 'Skipping existing song ' . $file->name, 5);
        } else {
            $results = $this->download_metadata($path, $file);
            $this->count++;
            return Song::insert($results);
        }

        return false;
    }

    private function download_metadata($path, $file)
    {
        $url = $this->client['Files']->getDownloadUrl($this->library, $file, $path);

        $tempfile  = tmpfile();

        // TODO partial download
        $this->client['Client']->request('GET', $downloadUrl, ['sink' => $tempfile]);

        $streammeta = stream_get_meta_data($tempfile);
        $meta       = $streammeta['uri'];

        $vainfo = new vainfo($meta, $this->get_gather_types('music'), '', '', '', $this->sort_pattern, $this->rename_pattern, true);
        $vainfo->forceSize($file->size);
        $vainfo->get_info();

        $key     = vainfo::get_tag_type($vainfo->tags);
        $results = vainfo::clean_tag_info($vainfo->tags, $key, $file->name);

        // Remove temp file
        if ($tempfile) {
            fclose($tempfile);
        }

        // Set the remote path
        $results['catalog'] = $this->id;

        $results['file'] = $this->to_virtual_path($path, $file->name);

        return $results;
    }

    public function verify_catalog_proc()
    {
        $results = array('total' => 0, 'updated' => 0);

        $this->createClient();

        if($this->client == null)
            return $results;

        $this->findLibrary();

        $sql        = 'SELECT `id`, `file` FROM `song` WHERE `catalog` = ?';
        $db_results = Dba::read($sql, array($this->id));
        while ($row = Dba::fetch_assoc($db_results)) {
            $results['total']++;
            debug_event('seafile-verify', 'Starting work on ' . $row['file'] . '(' . $row['id'] . ')', 5, 'ampache-catalog');
            $fileinfo = $this->from_virtual_path($row['file']);

            $metadata = $this->download_metadata($fileinfo['path'], $fileinfo['filename']);

            if ($metadata) {
                debug_event('seafile-verify', 'updating song', 5, 'ampache-catalog');
                $song = new Song($row['id']);
                self::update_song_from_tags($metadata, $song);
                if ($info['change']) {
                    $results['updated']++;
                }
            } else {
                debug_event('seafile-verify', 'removing song', 5, 'ampache-catalog');
                $dead++;
                Dba::write('DELETE FROM `song` WHERE `id` = ?', array($row['id']));
            }
        }

        $this->update_last_update();

        return $results;
    }

    /**
     * clean_catalog_proc
     *
     * Removes songs that no longer exist.
     */
    public function clean_catalog_proc()
    {
        $dead = 0;

        $this->createClient();

        if($this->client == null)
            return 0;

        $this->findLibrary();

        $sql        = 'SELECT `id`, `file` FROM `song` WHERE `catalog` = ?';
        $db_results = Dba::read($sql, array($this->id));
        while ($row = Dba::fetch_assoc($db_results)) {
            debug_event('seafile-clean', 'Starting work on ' . $row['file'] . '(' . $row['id'] . ')', 5, 'ampache-catalog');
            $file     = $this->from_virtual_path($row['file']);

            $exists = $client['Directory']->exists($this->library, $file['filename'], $file['path']);

            if ($exists) {
                debug_event('seafile-clean', 'keeping song', 5, 'ampache-catalog');
            } else {
                debug_event('seafile-clean', 'removing song', 5, 'ampache-catalog');
                $dead++;
                Dba::write('DELETE FROM `song` WHERE `id` = ?', array($row['id']));
            }
        }

        $this->update_last_clean();

        return $dead;
    }

    /**
     * check_remote_song
     *
     * checks to see if a remote song exists in the database or not
     * if it find a song it returns the UID
     */
    public function check_remote_song($file)
    {
        $sql        = 'SELECT `id` FROM `song` WHERE `file` = ?';
        $db_results = Dba::read($sql, array($file));

        if ($results = Dba::fetch_assoc($db_results)) {
            return $results['id'];
        }

        return false;
    }


    /**
     * format
     *
     * This makes the object human-readable.
     */
    public function format()
    {
        parent::format();
        $this->f_info      = 'Seafile server "' . $this->server_uri . '", library "' . $this->library_name . '"';
        $this->f_full_info = 'Seafile server "' . $this->server_uri . '", library "' . $this->library_name . '"';
    }

    public function prepare_media($media)
    {
        $this->createClient();

        if ($client != null) {
            $this->findLibrary();

            set_time_limit(0);

            // Generate browser class for sending headers
            $browser    = new Horde_Browser();
            $media_name = $media->f_artist_full . " - " . $media->title . "." . $media->type;
            $browser->downloadHeaders($media_name, $media->mime, false, $media->size);
            $file = $this->from_virtual_path($media->file);

            $output   = fopen('php://output', 'w');

            $response = $client['Files']->downloadFromDir($this->library, $file['path'] . '/' . $file['filename'],  $output);

            if ($response->getStatusCode() != 200) {
                debug_event('play', 'Unable to download file from Seafile: ' . $file, 5);
            }
            fclose($output);
        }

        return null;
    }
}
