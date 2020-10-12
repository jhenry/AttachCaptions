<?php

class AttachCaptions extends PluginAbstract
{
	/**
	 * @var string Name of plugin
	 */
	public $name = 'AttachCaptions';

	/**
	 * @var string Description of plugin
	 */
	public $description = 'Allows users to upload and attach caption files for their videos.';

	/**
	 * @var string Name of plugin author
	 */
	public $author = 'Justin Henry';

	/**
	 * @var string URL to plugin's website
	 */
	public $url = 'https://uvm.edu/~jhenry/';

	/**
	 * @var string Current version of plugin
	 */
	public $version = '0.0.1';

	/**
	 * Performs install operations for plugin. Called when user clicks install
	 * plugin in admin panel.
	 *
	 */
	public function install()
	{
		$formats = array('vtt', 'srt');
		Settings::set('attach_captions_formats', json_encode($formats));
		Settings::set('attach_captions_default_lang', 'en');

		$db = Registry::get('db');
		if (!AttachCaptions::tableExists($db, 'video_meta')) {
			$video_query = "CREATE TABLE IF NOT EXISTS videos_meta (
				meta_id bigint(20) NOT NULL AUTO_INCREMENT PRIMARY KEY,
				video_id bigint(20) NOT NULL,
				meta_key varchar(255) NOT NULL,
				meta_value longtext NOT NULL);";

			$db->query($video_query);
		}


		if (!AttachCaptions::tableExists($db, 'files_meta')) {
			$file_query = "CREATE TABLE IF NOT EXISTS files_meta (
				meta_id bigint(20) NOT NULL AUTO_INCREMENT PRIMARY KEY,
				file_id bigint(20) NOT NULL,
				meta_key varchar(255) NOT NULL,
				meta_value longtext NOT NULL);";

			$db->query($file_query);
		}
	}

	/**
	 * Performs uninstall operations for plugin. Called when user clicks
	 * uninstall plugin in admin panel and prior to files being removed.
	 *
	 */
	public function uninstall()
	{

		$db = Registry::get('db');

		$drop_video_meta = "DROP TABLE IF EXISTS videos_meta;";

		$db->query($drop_video_meta);

		$drop_file_meta = "DROP TABLE IF EXISTS files_meta;";

		$db->query($drop_file_meta);
	}


	/**
	 * Attaches plugin methods to hooks in code base
	 */
	public function load()
	{
		Settings::set('attach_captions_default_lang', 'en');
		

		Plugin::attachEvent('app.start', array(__CLASS__, 'appendAcceptedFormats'));
		Plugin::attachEvent('theme.head', array(__CLASS__, 'addMetaTag'));
		Plugin::attachEvent('theme.head', array(__CLASS__, 'load_styles'));
		Plugin::attachEvent('videos_edit.start', array(__CLASS__, 'set_default_caption'));
		Plugin::attachEvent('upload_info.post_encode', array(__CLASS__, 'set_default_caption'));
		Plugin::attachEvent('videos_edit.start', array(__CLASS__, 'save_caption_language'));
		Plugin::attachEvent('videos_edit.start', array(__CLASS__, 'cleanup_deleted_meta'));
		Plugin::attachEvent('videos.edit.attachment.list', array(__CLASS__, 'edit_default_captions'));
		Plugin::attachFilter('theme.watch.attachment.captions', array(__CLASS__, 'show_caption_tracks'));
		Plugin::attachEvent('videos.edit.attachment.end', array(__CLASS__, 'caption_form_template'));
	}

/**
	 * Set the allowed file formats to include 
	 * 
	 */
	public static function appendAcceptedFormats()
	{
		$captionFormats = json_decode(Settings::get('attach_captions_formats'));
		$config = Registry::get('config');
		$config->acceptedCaptionFormats = $captionFormats;
		Registry::set('config', $config);
	}

	/**
	 *  Set meta tag in header to help with JS DOM building
	 */
	public static function addMetaTag()
	{
		echo '<meta name="attachcaptions" content="true" />';
	}

	/**
   * Get Video object based on uploaded video in _SESSION
   * 
   */
  public static function getUploadedVideo()
  {
    $videoMapper = new VideoMapper();
    if (isset($_SESSION['upload']->videoId)) {
      $video_id = $_SESSION['upload']->videoId;
      $video = $videoMapper->getVideoById($video_id);
      return $video;
    }
  }

	/**
	 * Add CSS stylesheet to head
	 * 
	 */
	public static function load_styles()
	{
		$config = Registry::get('config');
		$css_url = $config->baseUrl . '/cc-content/plugins/AttachCaptions/style.css';
		echo '<link href="' . $css_url . '" rel="stylesheet">';
	}

	/**
	 * Insert caption/subtitle tracks into html5 player.
	 *
	 * @param int $video_id Id of video for which we are getting captions 
	 * @return string $tracks HTML5 caption tracks for each caption
	 *
	 */
	public static function show_caption_tracks($video_id)
	{

		$fileService = new FileService();

		$video_meta = AttachCaptions::get_video_meta($video_id, 'default_caption');
		$default_caption = $video_meta->meta_value;
		$caption_tracks = AttachCaptions::get_all_captions($video_id);
		$tracks = "";

		$languages = AttachCaptions::language_list();
		foreach ($caption_tracks as $track) {
			$default = ($default_caption == $track->fileId) ? "default" : "";
			$url = $fileService->getUrl($track);
			$language = AttachCaptions::get_caption_language($track->fileId);
			$language_label = $languages[$language];
			$tracks .= '<track label="' . $language_label . '" kind="subtitles" srclang="' . $language . '" src="' . $url . '" ' . $default . '>';
		}
		return $tracks;
	}

	/**
	 * Display additional form elements in the attachment list 
	 * to allow setting the default caption, and setting the language.
	 *
	 * @param int $file_id Id of file we are editing
	 * @param int $video_id Id of the video this file is attached to 
	 * @return string $link HTML form elements for caption files.
	 *
	 */
	public static function edit_default_captions($file_id, $video_id)
	{
		$fileMapper = new FileMapper();
		$file = $fileMapper->getById($file_id);
		$link = "";
		$disabled = "";
		$tooltip = "";
		$class = "";
		$fileId = "";
		//if it's a caption file
		if ($file) {
				$fileId = $file->fileId;
		} else {
			$tooltip = ' data-toggle="tooltip" data-placement="left"  title="Cannot configure new caption until after video is saved/uploaded." tabindex="0"';
			$disabled = ' style="pointer-events: none;" disabled';
			$class = ' temp-custom-thumb';
		}
			if (AttachCaptions::is_caption_file($file)) {
				// Retrieve the default caption if set
				$video_meta = AttachCaptions::get_video_meta($video_id, 'default_caption');
				if(isset($_POST['default_caption'])){
				$posted_cap = intval($_POST['default_caption']);
				} else{
					$posted_cap = 0;
				}
				$existing_meta = ($video_meta) ? $video_meta->meta_value : null;
				if ($fileId == $existing_meta || $posted_cap == intval($fileId)) {
					$checked = " checked";
				} else {
					$checked = "";
				}
		$link .= '<div class="pt-2 custom-control custom-radio attach-captions-toggle attach-captions-control' . $class . '"' . $tooltip . '><input type="radio" id="default-caption-input-' . $fileId . '" class="custom-control-input" name="default_caption" value="' . $fileId . '"' . $disabled . $checked . '> <label class="custom-control-label default-caption" for="default-caption-input-' . $fileId . '">Make this the default caption.</label></div>';

		$language = AttachCaptions::get_caption_language($fileId);
		$languages = AttachCaptions::language_list(true);
		$link .= include 'select-language-form.php';
		echo $link;
			}
	}


	/**
	 * Print a template for JS loading of the caption/language form.
	 *
	 */
	public static function caption_form_template()
	{
			$form = "";
			$language = Settings::get('attach_captions_default_lang');
			$languages = AttachCaptions::language_list(true);
			$form = include 'caption-form-template.phtml';
			echo $form;
	}

	/**
	 * Set a default caption file
	 *
	 */
	public static function set_default_caption()
	{
		if (isset($_POST['default_caption'])) {
			$file_id = $_POST['default_caption'];
			if (isset($_GET['vid'])) {
				$videoId = $_GET['vid'];
			} else {
				$file = AttachCaptions::getUploadedVideo();
				$videoId = $file->videoId;
			}

			AttachCaptions::update_video_meta($videoId, 'default_caption', $file_id);
		}
	}

	/**
	 * Save/Create video meta entries, such as default_caption settings.
	 * 
	 * @param int $video_id Id of the video this meta belongs to 
	 * @param string $meta_key reference label for the meta item we are updating
	 * @param string $meta_value data entry being updated
	 * 
	 */
	public static function update_video_meta($video_id, $meta_key, $meta_value)
	{

		include_once "VideoMeta.php";
		$videoMeta = new VideoMeta();

		// If there's meta for this file, we want the meta id
		$existing_meta = AttachCaptions::get_video_meta($video_id, $meta_key);
		if ($existing_meta) {
			$videoMeta->meta_id = $existing_meta->meta_id;
		}


		$videoMeta->video_id = $video_id;
		$videoMeta->meta_key = $meta_key;
		$videoMeta->meta_value = $meta_value;

		include_once 'VideoMetaMapper.php';
		$videoMetaMapper = new VideoMetaMapper();

		$videoMetaMapper->save($videoMeta);
	}

	/**
	 * Clean up meta (i.e. video and file) entries when a subtitle is removed.
	 * Compares submitted form data against meta entries in the DB. 
	 * 
	 */
	public static function cleanup_deleted_meta()
	{
		$submittedAttachmentFileIds = array();

		// Get a list of attachments that were posted via the video edit form
		if (isset($_POST['attachment']) && is_array($_POST['attachment'])) {
			foreach ($_POST['attachment'] as $attachment) {
				if (!empty($attachment['file'])) {
					$submittedAttachmentFileIds[] = $attachment['file'];
				}
			}

			// Get all attachments in the DB for this video
			$video_id = $_GET['vid'];
			$existing_captions = AttachCaptions::get_all_captions($video_id);

			foreach ($existing_captions as $caption) {

				// If the caption in the DB is not included in the 
				// attachments submitted via the form, we delete it's meta records.
				if (!in_array($caption->fileId, $submittedAttachmentFileIds)) {

					// Delete all meta for this fileId 
					include_once "FileMetaMapper.php";
					$fileMetaMapper = new \FileMetaMapper();
					$file_meta = $fileMetaMapper->getMultipleByCustom(array('file_id' => $caption->fileId));
					foreach ($file_meta as $meta) {
						$fileMetaMapper->delete($meta->meta_id);
					}

					// And if the ID is listed as a default caption for a video, clean that up too
					$video_meta = AttachCaptions::get_video_meta($video_id, 'default_caption');
					if ($caption->fileId == $video_meta->meta_value) {
						include_once 'VideoMetaMapper.php';
						$videoMetaMapper = new VideoMetaMapper();
						$videoMetaMapper->delete($video_meta->meta_id);
					}
				}
			}
		}
	}

	/**
	 * Get the language associated with the caption file.
	 * 
	 * @param int $file_id Id of the file which we are pulling meta data for 
	 * @return string Value of the language entry for this meta row
	 */
	public static function get_caption_language($file_id)
	{
		$meta = AttachCaptions::get_file_meta($file_id, 'language');
		return $meta->meta_value;
	}

	/**
	 * Get language list.  Convenience function for displaying a list of languages in a form.  
	 * 
	 * @param bool $insert_default (Defaults to false) Toggle whether to insert system language at top.
	 * @return array Key/value array containing language codes and labels.
	 */
	public static function language_list($insert_default = false)
	{
		$languages = include 'language-labels.en.php';
		if ($insert_default) {
			$default = Settings::get('default_language');
			$default_label = $languages[$default] . "(System Default)";
			$defaults = array($default => $default_label, '' => '--');
			$languages = $defaults + $languages;
		}

		return $languages;
	}

	/**
	 * Get file meta entry
	 * 
	 * @param int $file_id Id of the file we are getting meta for
	 * @param int $meta_key Labele for the meta row we are retrieving
	 * @return bool false if not found 
	 */
	public static function get_file_meta($file_id, $meta_key)
	{

		include_once 'FileMetaMapper.php';
		$fileMetaMapper = new FileMetaMapper();
		$meta = $fileMetaMapper->getByCustom(array('file_id' => $file_id, 'meta_key' => $meta_key));

		return $meta;
	}

	/**
	 * Update caption meta when the attachments form is updated.
	 * 
	 * @param int $file_id Id of the file this meta belongs to 
	 * @param string $meta_key reference label for the meta item we are updating
	 * @param string $meta_value data item/entry being updated
	 * 
	 */
	public static function update_file_meta($file_id, $meta_key, $meta_value)
	{

		include_once "FileMeta.php";
		$fileMeta = new FileMeta();

		// If there's meta for this file, we want the meta id
		$existing_meta = AttachCaptions::get_file_meta($file_id, $meta_key);
		if ($existing_meta) {
			$fileMeta->meta_id = $existing_meta->meta_id;
		}


		$fileMeta->file_id = $file_id;
		$fileMeta->meta_key = $meta_key;
		$fileMeta->meta_value = $meta_value;

		include_once 'FileMetaMapper.php';
		$fileMetaMapper = new FileMetaMapper();

		$fileMetaMapper->save($fileMeta);
	}

	/**
	 * Update caption language meta from attachments form
	 * 
	 */
	public static function save_caption_language()
	{
		if (isset($_POST['caption_language'])) {
			$languages = $_POST['caption_language'];
			foreach ($languages as $file_id => $language) {
				AttachCaptions::update_file_meta($file_id, 'language', $language);
			}
		}
	}

	/**
	 * Get all attached caption files
	 * 
	 * @param int $video_id Id of the video we are querying caption attachments for
	 * @return array $captions List of attachment objects that are captions/subtitles
	 */
	public static function get_all_captions($video_id)
	{

		$attachmentMapper = new AttachmentMapper();
		$attachments = $attachmentMapper->getMultipleByCustom(array("video_id" => $video_id));
		//for each attachment, if it's a caption, put it on the stack
		$fileMapper = new FileMapper();
		$captions = array();
		foreach ($attachments as $attachment) {
			$file = $fileMapper->getById($attachment->fileId);

			if (AttachCaptions::is_caption_file($file)) {
				$captions[] = $file;
			}
		}
		return $captions;
	}

	/**
	 * Determine if the specified file is a caption file
	 *
	 * @param File $file file object 
	 */
	public static function is_caption_file($file)
	{

		//TODO: move to plugin settings
		$valid_captions = array('vtt', 'srt');

		return in_array($file->extension, $valid_captions);
	}

	/**
	 * Get video meta entry
	 * 
	 * @param int $video_id Id of the video this meta belongs to 
	 * @param string $meta_key reference label for the meta item to retrieve
	 * @return false if not found 
	 */
	public static function get_video_meta($video_id, $meta_key)
	{
		include_once 'VideoMetaMapper.php';
		$videoMetaMapper = new VideoMetaMapper();
		$meta = $videoMetaMapper->getByCustom(array('video_id' => $video_id, 'meta_key' => $meta_key));
		return $meta;
	}

	/**
	 * Check if a table exists in the current database.
	 *
	 * @param PDO $pdo PDO instance connected to a database.
	 * @param string $table Table to search for.
	 * @return bool TRUE if table exists, FALSE if no table found.
	 */
	public static function tableExists($pdo, $table)
	{

		// Try a select statement against the table
		// Run it in try/catch in case PDO is in ERRMODE_EXCEPTION.
		try {
			$result = $pdo->basicQuery("SELECT 1 FROM $table LIMIT 1");
		} catch (Exception $e) {
			// We got an exception == table not found
			return FALSE;
		}

		// Result is either boolean FALSE (no table found) or PDOStatement Object (table found)
		return $result !== FALSE;
	}
}
