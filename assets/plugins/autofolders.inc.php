<?php


/* -------------------------------------------
v 0.3
-------------------------------------------
CONTENTS OF THE PLUGIN CODE:

$asset_path = $modx->config['base_path'] . 'assets/plugins/autofolders/autofolders.inc.php';
include($asset_path);

--------------------------------------------
EVENTS:

[X]  OnDocFormSave 

--------------------------------------------
PLUGIN CONFIGURATION:

&template=Template ID(s) to target;text;4 &new_page_template=Template (ID) for newly created index pages;text;4 &parent=Parent document;text;2 &date_field=Date field;text;pub_date &folder_structure=Folder structure;list;y,y/m,y/m/d;y/m &year_format=Year format in aliases;list;4 digit,2 digit;4 digit &month_format=Month format in aliases;list;Number (no leading 0),Number (leading 0),Text (Full name),Text (Short name);Number (no leading 0) &day_format=Day format in aliases;list;Number (no leading 0),Number (leading 0);Number (no leading 0) &year_format_title=Year format in titles;list;4 digit,2 digit;4 digit &month_format_title=Month format in titles;list;Number (no leading 0),Number (leading 0),Text (Full name),Text (Short name);Text (Full name) &day_format_title=Day format in titles;list;Number (no leading 0),Number (leading 0);Number (no leading 0)

--------------------------------------------*/



// Helper function
if (!function_exists('getFormattedDate') ) {
	function getFormattedDate($dt, $part, $format) {
		
		global $e;
	
		// Part should be y, m or d
		// format should be the format from the config dropdown
		
		switch ($part) {
		
			case 'y':
			
				switch ($format) {
					case '4 digit':
					case 'menuindex':
						return strftime("%Y", $dt);
					break;
					case '2 digit':
						return strftime("%y", $dt);
					break;		
					default:
						$modx->logEvent(5, 1, "An unrecognised year format was used ($year_format)", $e->activePlugin);
						return false;
					break;
				}
			
			break;
			
			
			case 'm':
			
				switch ($format) {
					case 'Number (leading 0)':
						return strftime("%m", $dt);
					break;	
					case 'Number (no leading 0)':
					case 'menuindex':
						return intval(strftime("%m", $dt));
					break;	
					case 'Text (Full name)':
						return strtolower(strftime("%B", $dt));
					break;
					case 'Text (Short name)':
						return strtolower(strftime("%b", $dt));
					break;
					default:
						$modx->logEvent(5, 1, "An unrecognised month format was used ($month_format)", $e->activePlugin);
						return false;
					break;	
				}
			
			break;
			
			
			
			case 'd':
			
				switch ($format) {
					case 'Number (leading 0)':
						return strftime("%d", $dt);
					break;	
					case 'Number (no leading 0)':
					case 'menuindex':
						return trim(strftime("%e", $dt));
					break;
					default:
						$modx->logEvent(5, 1, "An unrecognised day format was used ($day_format)", $e->activePlugin);
						return false;
					break;	
				}
			
			break;
			
			
			default:
				return false;
			break;
				
			
		}
	}
}



date_default_timezone_set('UTC');


// Check the current event
global $e;
$e = &$modx->Event;

// Is the document we are creating using a template we have been asked to target?
$tpls = explode(',', $template);
$tpls = array_map("trim", $tpls);

if (!(isset($_POST['template']) && in_array($_POST['template'], $tpls))) {
	return false;
}



// What's the date?
$the_date = '';

// These are ModX's built in date fields. These are really easy to spot
$modx_builtin_dates = array('pub_date', 'unpub_date');

// If it's one of these, we now know our date / time value
if (in_array($date_field, $modx_builtin_dates)) {
	$the_date = $_POST[$date_field];
} else {
	// If it's a TV, it takes a bit more work to find it out
	$tv = $modx->db->select('id', $modx->getFullTableName('site_tmplvars'), "name='$date_field'");
	if ($modx->db->getRecordCount( $tv ) > 0) { 
		$tv_row = $modx->db->getRow( $tv );
		$tv_field_name = 'tv'.$tv_row['id'];
		$the_date = $_POST[$tv_field_name];
	} else {
		$modx->logEvent(5, 2, "Unable to use $date_field as a date field - not a ModX built in date field, and couldn't find a TV with this name", $e->activePlugin);	
	}
}

// Parse the date string
$dt = strtotime($the_date);


// If there is no date value found yet, give up
if ($dt === false || $dt === -1) { // If date can't be parsed, it returns false (PHP5.1) or -1 (<PHP5.1)
	$modx->logEvent(5, 2, "Could not parse a valid date from the date field ($date_field)", $e->activePlugin);
	return;
}

// What are the formats specified?
$aliases['y'] = getFormattedDate($dt,  'y', $year_format);
$aliases['m'] = getFormattedDate($dt,  'm', $month_format);
$aliases['d'] = getFormattedDate($dt,  'd', $day_format);
$titles['y'] = getFormattedDate($dt,  'y', $year_format_title);
$titles['m'] = getFormattedDate($dt,  'm', $month_format_title);
$titles['d'] = getFormattedDate($dt,  'd', $day_format_title);


// Explode the folder format
$folders = explode('/', $folder_structure);

// Where do we start looking for folders?
$last_parent = $parent;


// Go through each of the folder structure items...
foreach ($folders as $i=>$f) {
	
	//... and check if the required folder exists
	$theFolderExists = false;
	
	// Get all the child folders
	$this_folder_children = $modx->getAllChildren($last_parent);
	
	// Go through the children, and see if any of them have the alias we want
	foreach ($this_folder_children as $child) {
		if ($child['alias'] == $aliases[$f]) {
			$theFolderExists = true;
			$last_parent = $child['id'];
		}
	}
	
	// If we haven't found the folder, create it
	if (!$theFolderExists) {
		
		// Make sure the parent folder is a container
		$modx->db->update("isfolder = 1", $modx->getFullTableName('site_content'), "id = $last_parent" );
		
		// Duplicate this doc
		$sql = $modx->db->select("*", $modx->getFullTableName('site_content'), "id = $last_parent" );
		$newdoc = $modx->db->getRow($sql);
		
		unset($newdoc['id']); // Don't resupply the primary key
		
		// Change the parent and update alises, titles, created on, edited on date
		$newdoc['parent'] = $last_parent; // Give it the correct parent
		$newdoc['template'] = $new_page_template; // The template specified in config
		$newdoc['createdon'] = mktime();
		$newdoc['editedon'] = mktime();
		$newdoc['content'] = '';
		$newdoc['type'] = 'document';
		$newdoc['createdby'] = $modx->getLoginUserID();
		$newdoc['editedby'] = $modx->getLoginUserID();
		
		// Generate a title
		switch ($f) {
			case 'y':
				$new_title = $titles['y'];
			break;	
			case 'm':
				$new_title = $titles['m'] . ' ' . $titles['y'];
			break;	
			case 'd':
				$new_title = $titles['d'] . ' ' . $titles['m'] . ' ' . $titles['y'];
			break;	
			default:
				$new_title = '';
			break;
		}
		
		$new_title = ucfirst($new_title);
		
		$newdoc['pagetitle'] = $new_title;
		$newdoc['longtitle'] = $new_title;
		$newdoc['menutitle'] = $new_title;
		
		$newdoc['alias'] = $aliases[$f];
				
		$newdoc['menuindex'] = getFormattedDate($dt,  $f, 'menuindex');
		
		// Insert the new page
		$new_page = $modx->db->insert($newdoc, $modx->getFullTableName('site_content'));
		
		// If it's inserted correctly, remember the new page as the last parent	
		$src_id = $last_parent;
		$last_parent = ($new_page !== false) ? $new_page : $last_parent;
		
		// Also duplicate any manager permissions
		$doc_group_table = $modx->getFullTableName('document_groups');
		$permissions = $modx->db->select("*", $doc_group_table, "document = $src_id");		
		if( $new_page !== false && $modx->db->getRecordCount( $permissions ) >= 1 ) {
			while( $row = $modx->db->getRow( $permissions ) ) {
				$dup_perms = array( 'document' => $new_page, 'document_group' => $row['document_group'] );
				$modx->db->insert( $dup_perms, $doc_group_table);
			}
		}
		
	
	}
	
	
}


// Change the parent of the newly added/edited page

// Work out the menu index by grabbing the last part of the folder structure, and baseing it off that
switch ($folders[count($folders)-1]) {
	case 'y':
		$menuindex = $aliases['m'];
	break;	
	case 'm':
		$menuindex = $aliases['d'];
	break;	
	case 'd':
		$menuindex = $aliases['d'] . strftime("%H%M", $dt);
	break;	
}

// Update the page in the d/b
$modx->db->update("parent = $last_parent, menuindex = $menuindex", $modx->getFullTableName('site_content'), "id = $id" );






?>