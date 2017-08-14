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
use Seafile\Client\Type\DirectoryItem;
use Seafile\Client\Resource\File;
use Seafile\Client\Resource\Library;
use Seafile\Client\Http\Client;
use GuzzleHttp\Exception\ClientException;

class Catalog_Seafile extends Catalog
{
    private $version        = '000001';
    private $type           = 'seafile';
    private $description    = 'Seafile Remote Catalog';

    private $table_name = 'catalog_seafile';

    public $server_uri;
    public $api_key;
    public $library_name;
    public $api_call_delay;

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
            "<li>" . T_("Enter url to server (e.g. &ldquo;https://seafile.example.com&rdquo;) and library name (e.g. &ldquo;Music&rdquo;).") . "</li>" .
            "<li>" . T_("'API Call Delay' is delay inserted between repeated requests to Seafile (such as during an Add or Clean action) to accomodate Seafile's Rate Limiting. ")
                   . T_("The default is tuned towards Seafile's default rate limit settings; see ")
                   . '<a href="https://forum.syncwerk.com/t/too-many-requests-when-using-web-api-status-code-429/2330">' . T_("this forum post") . '</a>'
                   . T_(" for instuctions on changing it.") . "</li>" .
            "<li>&rArr;&nbsp;" . T_("After preparing the catalog with pressing the 'Add catalog' button,<br /> you must 'Make it ready' on the catalog table.") . "</li></ul>";

        return $help;
    } // get_create_help

    /**
     * is_installed
     * This returns true or false if remote catalog is installed
     */
    public function is_installed()
    {
        $sql        = "SHOW TABLES LIKE '{$this->table_name}'";
        $db_results = Dba::query($sql);

        return (Dba::num_rows($db_results) > 0);
    } // is_installed

    /**
     * install
     * This function installs the remote catalog
     */
    public function install()
    {
        $sql = "CREATE TABLE `{$this->table_name}` (" .
            "`id` INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY , " .
            "`server_uri` VARCHAR( 255 ) COLLATE utf8_unicode_ci NOT NULL , " .
            "`api_key` VARCHAR( 100 ) COLLATE utf8_unicode_ci NOT NULL , " .
            "`library_name` VARCHAR( 255 ) COLLATE utf8_unicode_ci NOT NULL , " .
            "`api_call_delay` INT NOT NULL , " .
            "`catalog_id` INT( 11 ) NOT NULL" .
            ") ENGINE = MYISAM DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci";
        $db_results = Dba::query($sql);

        return true;
    }

    public function catalog_fields()
    {
        $fields['server_uri']     = array('description' => T_('Server URI'), 'type' => 'text', 'value' => 'https://seafile.example.org/');
        $fields['library_name']   = array('description' => T_('Library Name'), 'type' => 'text', 'value' => 'Music');
        $fields['api_call_delay'] = array('description' => T_('API Call Delay'), 'type' => 'number', 'value' => '250');

        return $fields;
    }

    public function isReady()
    {
        return (!empty($this->api_key));
    }

    public function show_ready_process()
    {
        $this->request_credentials();
    }

    protected function request_credentials()
    {
        echo '<br />' . T_('Enter Seafile Username and Password') . '<br />';
        echo "<form action='" . get_current_path() . "' method='post' enctype='multipart/form-data'>";
        if ($_REQUEST['action']) {
            echo "<input type='hidden' name='action' value='" . scrub_in($_REQUEST['action']) . "' />";
            echo "<input type='hidden' name='catalogs[]' value='" . $this->id . "' />";
        }
        echo "<input type='hidden' name='perform_ready' value='true' />";

        echo T_("Username/Email") . ": <input type='text' name='seafileusername' required /> ";
        echo T_("Password") . ": <input type='password' name='seafilepassword' required /> ";

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
        try {
            $data = array('username' => $username, 'password' => $password);

            // use key 'http' even if you send the request to https://...
            $options = array(
                'http' => array(
                    'header' => "Content-type: application/x-www-form-urlencoded\r\n",
                    'method' => 'POST',
                    'content' => http_build_query($data)
                )
            );
            $context = stream_context_create($options);
            $result  = file_get_contents($this->server_uri . '/api2/auth-token/', false, $context);

            if (!$result) {
                AmpError::add('general', T_('Error: Could not authenticate against Seafile API.'));
                $this->request_credentials();
            } else {
                $token = json_decode($result);

                $this->api_key = $token->token;

                debug_event('seafile_catalog', 'Retrieved API token for user ' . $username . '.', 1);

                $sql = "UPDATE `{$this->table_name}` SET `api_key` = ? WHERE `catalog_id` = ?";
                Dba::write($sql, array($this->api_key, $this->id));
            }
        } catch (Exception $e) {
            AmpError::add('general', sprintf(T_('Error while authenticating against Seafile API: %s', $e->getMessage())));
            debug_event('seafile_catalog', 'Exception while Authenticating: ' . $e->getMessage(), 2);
        }
    }

    /**
     * create_type
     *
     * This creates a new catalog type entry for a catalog
     */
    public static function create_type($catalog_id, $data)
    {
        $server_uri     = rtrim(trim($data['server_uri']), '/');
        $api_key        = trim($data['api_key']);
        $library_name   = trim($data['library_name']);
        $api_call_delay = trim($data['api_call_delay']);

        if (!strlen($server_uri)) {
            AmpError::add('general', T_('Error: Seafile Server URL is required.'));

            return false;
        }

        if (!strlen($library_name)) {
            AmpError::add('general', T_('Error: Seafile Server Library Name is required.'));

            return false;
        }

        if (!is_numeric($api_call_delay)) {
            AmpError::add('general', T_('Error: API Call Delay must have a numeric value.'));

            return false;
        }

        $sql = "INSERT INTO `{$this->table_name}` (`server_uri`, `api_key`, `library_name`, `api_call_delay`, `catalog_id`) VALUES (?, ?, ?, ?, ?)";
        Dba::write($sql, array($server_uri, $api_key, $library_name, intval($api_call_delay), $catalog_id));

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

            foreach ($info as $key => $value) {
                $this->$key = $value;
            }
        }
    }

    private $client;
    private $library;

    private function create_client()
    {
        if ($this->client) {
            return;
        }

        if (!$this->isReady()) {
            AmpError::add('general', 'Seafile Catalog is not ready.');
            $this->client = null;
        } else {
            $client = new Client([
                'base_uri' => $this->server_uri,
                'debug' => false,
                'delay' => $this->api_call_delay,
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

            $this->find_library();
        }
    }

    private function throttle_check($func)
    {
        while (true) {
            try {
                return $func();
            } catch (ClientException $e) {
                if ($e->getResponse()->getStatusCode() == 429) {
                    $resp = $e->getResponse()->getBody();

                    $error = json_decode($result)->detail;

                    preg_match('(\d+) sec', $error, $matches);

                    $secs = intval($matches[1][0]);

                    debug_event('seafile-catalog', sprintf('Throttled by Seafile, waiting %d seconds.', $secs), 5);
                    sleep($secs + 1);
                } else {
                    throw $e;
                }
            }
        }
    }

    private function find_library()
    {
        $libraries = $this->throttle_check(function () {
            return $this->client['Libraries']->getAll();
        });

        $library = array_values(array_filter($libraries, function ($library) {
            return $library->name == $this->library_name;
        }));

        if (count($library) == 0) {
            AmpError::add('general', sprintf(T_('No media updated: could not find Seafile library called "%s"'), $this->library_name));
        }

        $this->library = $library[0];
    }

    public function to_virtual_path($path, $filename)
    {
        return $this->library->name . '|' . $path . '|' . $filename;
    }

    public function from_virtual_path($file_path)
    {
        $split = explode('|', $file_path);

        return array('path' => $split[1], 'filename' => $split[2]);
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
        $this->create_client();

        if ($this->client != null) {
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
    private function add_from_directory($path)
    {
        $directoryItems = $this->throttle_check(function () use ($path) {
            return $this->client['Directories']->getAll($this->library, $path);
        });

        $count = 0;

        if ($directoryItems !== null && count($directoryItems) > 0) {
            foreach ($directoryItems as $item) {
                if ($item->type == 'dir') {
                    $count += $this->add_from_directory($path . $item->name . '/');
                } elseif ($item->type == 'file') {
                    $count += $this->add_file($item, $path);
                }
            }
        }

        return $count;
    }

    private function add_file($file, $path)
    {
        $filesize = $file->size;

        if ($file->size > 0) {
            $is_audio_file = Catalog::is_audio_file($file->name);
            $is_video_file = Catalog::is_video_file($file->name);

            if (!$is_audio_file && !$is_video_file) {
                debug_event('read', $data['path'] . " ignored, unknown media file type", 5);
            }

            if ($is_audio_file && count($this->get_gather_types('music')) > 0) {
                $result = $this->insert_song($file, $path);

                if ($result) {
                    return 1;
                }
            } elseif ($is_video_file && count($this->get_gather_types('video')) > 0) {
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
            UI::update_text('', sprintf(T_('Skipping existing song "%s"'), $file->name));
        } else {
            debug_event('seafile_catalog', 'Adding song ' . $file->name, 5);
            try {
                $results = $this->download_metadata($path, $file);
                $this->count++;
                UI::update_text('', sprintf(T_('Adding song "%s"'), $file->name));

                return Song::insert($results);
            } catch (Exception $e) {
                debug_event('seafile_add', sprintf('Could not add song "%s": %s', $file->name, $e->getMessage()), 1);
                UI::update_text('', sprintf(T_('Could not add song "%s"'), $file->name));
            }
        }

        return false;
    }

    private function download_metadata($path, $file, $sort_pattern = '', $rename_pattern = '')
    {
        // Check for patterns
        if (!$sort_pattern or !$rename_pattern) {
            $sort_pattern   = $this->sort_pattern;
            $rename_pattern = $this->rename_pattern;
        }

        $url = $this->throttle_check(function () use ($file, $path) {
            return $this->client['Files']->getDownloadUrl($this->library, $file, $path);
        });

        debug_event('seafile_catalog', 'Downloading partial song ' . $file->name, 5);

        $tempfilename = sys_get_temp_dir() . DIRECTORY_SEPARATOR . $file->name;

        $tempfile = fopen($tempfilename, 'wb');

        // grab a full 2 meg in case meta has image in it or something
        $response = $this->throttle_check(function () use ($url) {
            return $this->client['Client']->request('GET', $url, ['curl' => [ CURLOPT_RANGE => '0-2097152' ]]);
        });

        fwrite($tempfile, $response->getBody());

        fclose($tempfile);

        $vainfo = new vainfo($tempfilename, $this->get_gather_types('music'), '', '', '', $sort_pattern, $rename_pattern, true);
        $vainfo->forceSize($file->size);
        $vainfo->get_info();

        $key = vainfo::get_tag_type($vainfo->tags);

        // maybe fix stat-ing-nonexistent-file bug?
        $vainfo->tags['general']['size'] = intval($file->size);

        $results = vainfo::clean_tag_info($vainfo->tags, $key, $file->name);

        // Set the remote path
        $results['catalog'] = $this->id;

        $results['file'] = $this->to_virtual_path($path, $file->name);

        return $results;
    }

    public function verify_catalog_proc()
    {
        $results = array('total' => 0, 'updated' => 0);

        $this->create_client();

        if ($this->client == null) {
            return $results;
        }

        set_time_limit(0);

        $sql        = 'SELECT `id`, `file`, `title` FROM `song` WHERE `catalog` = ?';
        $db_results = Dba::read($sql, array($this->id));
        while ($row = Dba::fetch_assoc($db_results)) {
            $results['total']++;
            debug_event('seafile-verify', 'Starting work on ' . $row['file'] . '(' . $row['id'] . ')', 5, 'ampache-catalog');
            $fileinfo = $this->from_virtual_path($row['file']);

            $file = $this->file_if_exists($fileinfo['path'], $fileinfo['filename']);

            $metadata = null;

            if ($file !== null) {
                $metadata = $this->download_metadata($fileinfo['path'], $file);
            }

            if ($metadata !== null) {
                debug_event('seafile-verify', 'updating song', 5, 'ampache-catalog');
                $song = new Song($row['id']);
                $info = self::update_song_from_tags($metadata, $song);
                if ($info['change']) {
                    UI::update_text('', sprintf(T_('Updated song "%s"'), $row['title']));
                    $results['updated']++;
                } else {
                    UI::update_text('', sprintf(T_('Song up to date: "%s"'), $row['title']));
                }
            } else {
                debug_event('seafile-verify', 'removing song', 5, 'ampache-catalog');
                UI::update_text('', sprintf(T_('Removing song "%s"'), $row['title']));
                $dead++;
                Dba::write('DELETE FROM `song` WHERE `id` = ?', array($row['id']));
            }
        }

        $this->update_last_update();

        return $results;
    }

    public function get_media_tags($media, $gather_types, $sort_pattern, $rename_pattern)
    {
        $this->create_client();

        if ($this->client == null) {
            return null;
        }

        $fileinfo = $this->from_virtual_path($media->file);

        $dircontents = $this->throttle_check(function () use ($fileinfo) {
            return $this->client['Directories']->getAll($this->library, $fileinfo['path']);
        });

        $matches = array_values(array_filter($dircontents, function ($file) use (&$fileinfo) {
            return $file->name == $fileinfo['filename'];
        }));

        $metadata = null;

        if (count($matches) > 0) {
            $metadata = $this->download_metadata($fileinfo['path'], $matches[0]);
        }

        return $metadata;
    }

    private $path_cache;

    private function file_if_exists($path, $filename)
    {
        if ($this->path_cache == null) {
            $path_cache = array();
        }

        if (array_key_exists($path, $path_cache)) {
            $directory = $path_cache[$path];
        } else {
            try {
                $directory = $this->throttle_check(function () use ($path) {
                    return $this->client['Directories']->getAll($this->library, $path);
                });
                $path_cache[$path] = $directory;
            } catch (ClientException $e) {
                if ($e->getResponse()->getStatusCode() == 404) {
                    $path_cache[$path] = false;
                    $directory         = false;
                } else {
                    throw $e;
                }
            }
        }

        if ($directory === false) {
            return null;
        }

        foreach ($directory as $file) {
            if ($file->name === $filename) {
                return $file;
            }
        }

        return null;
    }

    /**
     * clean_catalog_proc
     *
     * Removes songs that no longer exist.
     */
    public function clean_catalog_proc()
    {
        $dead = 0;

        $this->create_client();

        if ($this->client == null) {
            return 0;
        }

        set_time_limit(0);

        $sql        = 'SELECT `id`, `file` FROM `song` WHERE `catalog` = ?';
        $db_results = Dba::read($sql, array($this->id));
        while ($row = Dba::fetch_assoc($db_results)) {
            debug_event('seafile-clean', 'Starting work on ' . $row['file'] . '(' . $row['id'] . ')', 5);
            $file     = $this->from_virtual_path($row['file']);

            try {
                $exists = $this->file_if_exists($file['path'], $file['filename']) !== null;
            } catch (Exception $e) {
                UI::update_text('', sprintf(T_('Error checking song "%s": %s'), $file['filename'], $e->getMessage()));
                debug_event('seafile-clean', 'Exception: ' . $e->getMessage(), 2);

                continue;
            }

            if ($exists) {
                debug_event('seafile-clean', 'keeping song', 5);
                UI::update_text('', sprintf(T_('Keeping song "%s"'), $file['filename']));
            } else {
                UI::update_text('', sprintf(T_('Removing song "%s"'), $file['filename']));
                debug_event('seafile-clean', 'removing song', 5);
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
        $this->create_client();

        if ($this->client != null) {
            set_time_limit(0);

            $file = $this->from_virtual_path($media->file);

            $item       = new DirectoryItem();
            $item->name = basename($file['filename']);

            $url = $this->throttle_check(function () use ($item, $file) {
                return $this->client['Files']->getDownloadUrl($this->library, $item, $file['path']);
            });
            $response = $this->throttle_check(function () use ($url) {
                return $this->client['Client']->request('GET', $url, [ 'delay' => 0 ]);
            });

            if ($response->getStatusCode() != 200) {
                debug_event('play', 'Unable to download file from Seafile: ' . $file['filename'], 1);

                return null;
            }

            $output = sys_get_temp_dir() . DIRECTORY_SEPARATOR . $file['filename'];

            $fout = fopen($output, 'wb');
            fwrite($fout, $response->getBody());

            fclose($fout);

            $media->file   = $output;
            $media->f_file = $file['filename'];

            // in case this didn't get set for some reason
            if ($media->size == 0) {
                $media->size = Core::get_filesize($output);
            }
        }

        return $media;
    }
}
