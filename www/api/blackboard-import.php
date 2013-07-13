<?php

/*
	The basic workflow:

	1. Upload ZIP archive (DONE)
	2. Unzip ZIP archive and extract course name, teacher, etc. (DONE)
	3. Create (or link to existing) Canvas course
	4. Upload items via API
		a. csfiles/ contents (build a list referenced by x-id and Canvas ID for
		   future reference)
		b. walk through resources in manifest and post all attachments (build a
		   list referenced by res00000, x-id if available, filename and Canvas ID)
		c. post items and then append them to appropriate modules
		d. perhaps create a nicey-nice front page that lists all the things in the
		   manifest TOC?
	5. Open Canvas course
	6. Clean out files (when Canvas API calls complete)
*/

/* REQUIRES the PHP 5 XSL extension
   http://www.php.net/manual/en/xsl.installation.php */

define('UPLOAD_DIR', '/var/www-data/canvas/blackboard-import/'); // where we'll store uploaded files
define('WORKING_DIR', UPLOAD_DIR . 'tmp/'); // where we'll be dumping temp files (and cleaning up, too!)
$manifestName = 'imsmanifest.xml'; // name of the manifest file

define('IMPORT_TYPE', 'importType');
define('ATTR_INDENT_LEVEL', 'indentLevel');

define('CANVAS_MODULE', 'MODULE');
define('CANVAS_FILE', 'FILE');
define('CANVAS_PAGE', 'PAGE');
define('CANVAS_EXTERNAL_URL', 'EXTERNAL_URL');
define('CANVAS_MODULE_ITEM', 'MODULE_ITEM');
define('CANVAS_QUIZ', 'QUIZ');
define('CANVAS_ASSIGNMENT', 'ASSIGNMENT');
define('CANVAS_DISCUSSION', 'DISCUSSION');
define('CANVAS_SUBHEADER', 'SUBHEADER');
define('CANVAS_ANNOUNCEMENT', 'ANNOUNCEMENT');
define('DO_NOT_IMPORT', 'NO IMPORT');

define('DEBUGGING', true);

/* defines CANVAS_API_TOKEN and CANVAS_INSTANCE_URL */
require_once('.ignore.canvas-authentication.php');


/***********************************************************************
 *                                                                     *
 * Helpers                                                             *
 *                                                                     *
 ***********************************************************************/
 
require_once('Pest.php');

/**
 * A handy little helper function to print a (somewhat) friendly error
 * message and fail out when things get hairy.
 **/
function exitOnError($title, $text = '') {
	$html = "<h1>$title</h1>";
	if (is_array($text)) {
		foreach ($text as $line) {
			$html .= "\n<p>$line</p>";
		}
	} elseif (strlen($text)) {
		$html .= "\n<p>$text</p>";
	}
	$html .= '<p><a href="' . $_SERVER['PHP_SELF'] . '">Try again.</a></p>';
	displayPage($html);
	exit;
}

/**
 * Echo a page of HTML content to the browser, wrapped in some CSS niceities
 **/
function displayPage($content) {
	echo '<html>
<head>
	<link rel="stylesheet" href="script-ui.css" />
</head>
<body>
'. $content . '
</body>
</html>';
}

/**
 * Helper function to conditionally fill the log file with notes!
 **/
function debug_log($message) {
	if (DEBUGGING) {
		error_log($message);
	}
}

/**
 * Force nodes and attributes to all lower-case in a given XML document,
 * returning a SimpleXML object.
 **/
function simplexml_load_file_lowercase($fileName) {
	if (file_exists($fileName)) {
		$xmlWoNkYcAsE = simplexml_load_file($fileName);
		$xslt = new XSLTProcessor();
		$xsl = simplexml_load_file('./lowercase-transform.xsl');
		$xslt->importStylesheet($xsl);
		return (simplexml_load_string($xslt->transformToXML($xmlWoNkYcAsE)));
	} else {
		return false;
	}
}

/**
 * Clean out the working directory, make it ready for our next import
 **/
function flushDir($dir) {
	$files = glob('$dir*');
	foreach($files as $file) {
		if (is_dir($file)) {
			flushDir($file);
			rmdir($file);
		} elseif (is_file($file)) {
			unlink($file);
		}
	}
}


/***********************************************************************
 *                                                                     *
 * Import Stages                                                       *
 *                                                                     *
 ***********************************************************************/

/**
 * Handles the actual file uploading and unzipping into the working directory
 **/
function stageUpload() {
	if ($_SERVER['REQUEST_METHOD'] == 'POST') {
		if (isset($_FILES['BbExportFile'])) {
			if ($_FILES['BbExportFile']['error'] === UPLOAD_ERR_OK) {
				// ... file was succesfully uploaded, process it
				$uploadFile = UPLOAD_DIR . basename($_FILES['BbExportFile']['name']);
				move_uploaded_file($_FILES['BbExportFile']['tmp_name'], $uploadFile); // FIXME would be good to create a per-session temp directory
				$zip = new ZipArchive();
				if ($zipRsrc = $zip->open($uploadFile)) {
					$zip->extractTo(WORKING_DIR);
					$zip->close();
					return true;
				} else exitOnError('Unzipping Failed', 'The file you uploaded could not be unzipped.');
			} else exitOnError('Upload Error', array('There was an error with your file upload:', 'Error ' . $_FILES['BbExportFile']['error'] . ': See the <a href="http://www.php.net/manual/en/features.file-upload.errors.php">PHP Documentation</a> for more information.'));
		} else exitOnError('No File Uploaded');
	}
	return false;
}

/**
 * Parse a module and update XML with notes for import
 **/
function parseContentArea($contentArea, $manifest, $courseId, $indent = -1) {
	foreach ($contentArea->item as $item) {
		if ($indent >= 0) {
			$item->addAttribute(ATTR_INDENT_LEVEL, $indent);
		}
		if (isset($item->item)) { // if there are subitems, this must be a subheader or module
			if ($indent < 0) {
				$item->addAttribute(IMPORT_TYPE, CANVAS_MODULE);
			} else {
				$item->addAttribute(IMPORT_TYPE, CANVAS_SUBHEADER);
			}
			// TODO: Pass along Module ID so that things get linked appropriately
			// TODO: Include a breadcrumb trail for the title of pages (so subheaders are included in the name)
			parseContentArea($item, $manifest, $courseId, $indent + 1);
		} else { /* we're going to have to dig deeper into the res00000.dat file */
			if ($item->attributes()) {
				$resFile = WORKING_DIR . $item->attributes()->identifierref . '.dat';
				if (file_exists($resFile)) {
					$res = simplexml_load_file_lowercase($resFile);
					if ($res->contenthandler && $res->contenthandler->attributes()) {
						$contentHandler = $res->contenthandler->attributes()->value;
						switch ($contentHandler) {
							case 'resource/x-bb-assignment': {
								$item->addAttribute(IMPORT_TYPE, CANVAS_ASSIGNMENT);
								break;
							}
							
							case 'resource/x-bb-courselink': {
								$item->addAttribute(IMPORT_TYPE, CANVAS_MODULE_ITEM);
								break;
							}
							
							case 'resource/x-bb-externallink': {
								if (strlen((string) $res->BODY->TEXT)) {
									$item->addAttribute(IMPORT_TYPE, CANVAS_PAGE);
								} else {
									$item->addAttribute(IMPORT_TYPE, CANVAS_EXTERNAL_URL);
								}
								break;
							}
							
							case 'resource/x-bb-asmt-survey-link': {
								$item->addAttribute(IMPORT_TYPE, CANVAS_QUIZ);
								break;
							}
							
							case 'resource/x-bb-folder':
							case 'resource/x-bb-lesson': {
								if ($indent < 0) {
									$item->addAttribute(IMPORT_TYPE, CANVAS_MODULE);
								} else {
									$item->addAttribute(IMPORT_TYPE, CANVAS_SUBHEADER);
								}
								break;
							}
							
							case 'resource/x-bb-vclink': {
								$item->addAttribute(IMPORT_TYPE, CANVAS_CONFERENCE);
								break;
							}
							
							case 'resource/x-bb-file': {
								if (strlen((string) $res->BODY->TEXT)) {
									$item->addAttribute(IMPORT_TYPE, CANVAS_PAGE);
								} else {
									$item->addAttribute(IMPORT_TYPE, CANVAS_FILE);
								}
								break;
							}
							
							case 'resource/x-bb-document': {
								$item->addAttribute(IMPORT_TYPE, CANVAS_PAGE);
								createCanvasPage($item, $res, $manifest, $courseId);
								break;
							}
						}
					} else {
						$item->addAttribute(IMPORT_TYPE, DO_NOT_IMPORT);		
					}
				} else {
					$itemAttributes = $item->attributes();
					$resFile = (string) $itemAttributes['identifierref'];
					exitOnError('Missing Resource File', "A resource file ($resFile) containing details about one of your items is missing.");
				}
			}
		}
	}
}

/**
 * Parse the XML of the manifest file and prepare a preview for the user before
 * committing to the actual import into Canvas
 **/
function parseManifest($manifestName, $courseId) {
	$manifestFile = WORKING_DIR . $manifestName;
	if (file_exists($manifestFile)) {
		$manifest = simplexml_load_file_lowercase($manifestFile);
		parseContentArea($manifest->organizations->organization, $manifest, $courseId);
		
		$html = '<pre>';
		$html .= print_r($manifest, true);
		$html .= '</pre>';
		displayPage($html);
		
	} else exitOnError('Missing Manifest', "The manifest file ($manifestName) that should have been included in your Blackboard Exportfile cannot be found.");
}

/**
 * Pull the Course ID out of the URL
 * TODO: (Currently returning our dummy course on the test instance)
 **/
function parseCourseUrl($courseUrl) {
	/* do we have a URL or are we creating a new course? */
	if (strlen($courseUrl)) {
		// TODO: ...
	}
	
	// TODO: if $courseURL is a valid course ID ...
	
	return "1126";
}

/***********************************************************************
 *                                                                     *
 * Blackboard (Bb) Functions                                           *
 *                                                                     *
 ***********************************************************************/

/**
 * Is this XML item a Bb application?
 **/
function isBbApplication($item) {
	return preg_match('|COURSE_DEFAULT\..*\.APPLICATION\.label|', $item->title);
}

/**
 * Clean up the Bb XML's body text HTML
 **/
function BbHTMLtoCanvasHTML($text) {
	$html = str_replace(array('&lt;', '&gt;'), array('<', '>'), $text);
	// FIXME: replace Bb Content Collection links of the form src='@X@EmbeddedFile.requestUrlStub@X@bbcswebdav/xid-26980_1'
	// FIXME: replace Bb Course Content Collection links of the form src='@X@EmbeddedFile.requestUrlStub@X@@@E48B325B306BE98D9AEF6FE35C97DD19/courses/1/CS-31-2-Orange-Battis/content/_3499_1/embedded/logo_house.GIF' (probably not gonna work!)
	// FIXME: replace attached files of the form src='@X@EmbeddedFile.location@X@logoscript.gif' (knowing that the actual filename may need to be fixed)
	/* proposed process for each of these fixes
	
			1. Spider the appropriate csfiles or res00000 directories to identify the file by size/x-id
			2. If the file has not already been uploaded, block the conversion process while it is uploaded
			3. Mark the relevant XML as uploaded (writing back to disk, including Canvas URLs)
			4. Replace with Canvas URLs
			5. Unblock and return cleaned HTML
			
	 Oy. */
	return $html;
}


/***********************************************************************
 *                                                                     *
 * Canvas Content Builders                                             *
 *                                                                     *
 ***********************************************************************/

/**
 * Create Canvas Page
 **/
function createCanvasPage($item, $res, $manifest, $courseId) {
	if ($titleNode = $res->xpath('/content/title')) {
		$titleAttributes = $titleNode[0]->attributes();
		$title = (string) $titleAttributes['value'];
		
		if ($textNode = $res->xpath('/content/body/text[1]')) {
			$text = BbHTMLtoCanvasHTML((string) $textNode[0]);
			
			$pest = new Pest(CANVAS_INSTANCE_URL);
			$pest->post("/courses/$courseId/pages",
				array(
					'wiki_page[title]' => $title,
					'wiki_page[body]' => "<h2>$title</h2>\n$text", // Canvas filters out <h1>
					'wiki_page[published]' => 'true'
				),
				array (
					'Authorization' => 'Bearer ' . CANVAS_API_TOKEN
				)		
			);
		} else {
			$itemAttributes = $item->attributes();
			$resFile = $itemAttributes['identifierref'];
			exitOnError('Text Not Found', "A resource file that we needed ($resFile) was missing a <text> tag that we were looking for to create a page in Canvas.");
		}
	} else {
		$itemAttributes = $item->attributes();
		$resFile = $itemAttributes['identifierref'];
		exitOnError('Title Not Found', "A resource file that we needed ($resFile) was missing a <title> tag that we were looking for to create a page in Canvas.");
	}
}


/***********************************************************************
 *                                                                     *
 * The main program... whee!                                           *
 *                                                                     *
 ***********************************************************************/

/* are we uploading a file? */
if (isset($_FILES['BbExportFile'])) {
	if (stageUpload()) {
		$courseId = parseCourseUrl($_REQUEST["courseUrl"]);
		$manifest = parseManifest($manifestName, $courseId);
		flushDir(WORKING_DIR);
	}
} else {
	/* well, it appears that nothing has happened yet, so let's just start with
	   a basic file upload form, as an aperitif to the main event... */
	displayPage('<form enctype="multipart/form-data" action="' . $_SERVER['PHP_SELF'] . '" method="POST">
	<div>
		<label for="courseUrl">Canvas Course URL (blank for new course)<label>
		<input id="courseUrl" name="courseUrl" type="text" size="100" value="https://stmarksschool.test.instructure.com/courses/1127" />
	</div>
	<div>
		<input type="hidden" name="MAX_FILE_SIZE" value="262144000" />
		<label for="BbExportFile">Blackboard ExportFile (250MB or 26214400 Bytes max)</label>
		<input id="BbExportFile" name="BbExportFile" type="file" />
	</div>
	<input type="submit" value="Go" onsubmit="if (this.getAttribute(\'submitted\')) return false; this.setAttribute(\'submitted\',\'true\');" />
</form>');
	exit;
}

?>