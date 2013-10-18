<?php
/**
 * @package trac2github
 * @version 1.1
 * @author Vladimir Sibirov
 * @author Lukas Eder
 * @copyright (c) Vladimir Sibirov 2011
 * @license BSD
 */

// Edit configuration below

$username = 'ericvaandering';
$password = $_ENV["GHPASS"];
$project = 'dmwm';
$repo = 'testmigration2';

// All users must be valid github logins!
$users_list = array(
	'yuyi' => 'yuyiguo',
	'ewv' => 'ericvaandering',
	'giffels' => 'giffels',
//	'ewv' => 'ericvaandering',
	'dsr' => 'dan131riley'
);

$mysqlhost_trac = 'trac-svn.cern.ch';
$mysqluser_trac = 'CMSDMWM';
$mysqlpassword_trac = $_ENV["TRACPASS"];
$mysqlport_trac = '5000';
$mysqldb_trac = 'CMSDMWM';

// Do not convert milestones at this run
$skip_milestones = false;

// Do not convert labels at this run
$skip_labels = false;

// Do not convert tickets
$skip_tickets = false;
$ticket_offset = 0; // Start at this offset if limit > 0
$ticket_limit = 0; // Max tickets per run if > 0

// Do not convert comments
$skip_comments = false;
$comments_offset = 0; // Start at this offset if limit > 0
$comments_limit = 0; // Max comments per run if > 0

// Paths to milestone/ticket cache if you run it multiple times with skip/offset
$save_milestones = './trac_milestones.list';
$save_tickets = './trac_tickets.list';
$save_labels = './trac_labels.list';

// Set this to true if you want to see the JSON output sent to GitHub
$verbose = false;

// Uncomment to refresh cache
// @unlink($save_milestones);
// @unlink($save_labels);
// @unlink($save_tickets);

// DO NOT EDIT BELOW

error_reporting(E_ALL ^ E_NOTICE);
ini_set('display_errors', 1);
set_time_limit(0);

$trac_db = new PDO('mysql:host=trac-svn.cern.ch;port=5500;dbname=CMSDMWM', $mysqluser_trac, $mysqlpassword_trac);

echo "Connected to Trac\n";

$milestones = array();
if (file_exists($save_milestones)) {
	$milestones = unserialize(file_get_contents($save_milestones));
}

if (!$skip_milestones) {
	// Export all milestones
	$res = $trac_db->query("SELECT * FROM `milestone` ORDER BY `due`");
	$mnum = 1;
	$resp = github_add_milestone(array(
		'title' => 'NoneSet',
		'state' => 'closed',
		'description' => 'Dummy Milestone',
		'due_on' => "2001-01-01T01:01:01Z"
	));
	if (isset($resp['number'])) {
		// OK
		$milestones[crc32('NoneSet')] = (int) $resp['number'];
		echo "Milestone NoneSet converted to {$resp['number']}\n";
	} else {
		// Error
		$error = print_r($resp, 1);
		echo "Failed to convert milestone NoneSet: $error\n";
	}
	foreach ($res->fetchAll() as $row) {
		//$milestones[$row['name']] = ++$mnum;
		$resp = github_add_milestone(array(
			'title' => $row['name'],
			'state' => $row['completed'] == 0 ? 'open' : 'closed',
			'description' => empty($row['description']) ? 'None' : $row['description'],
			'due_on' => date('Y-m-d\TH:i:s\Z', (int) $row['due'])
		));
		if (isset($resp['number'])) {
			// OK
			$milestones[crc32($row['name'])] = (int) $resp['number'];
			echo "Milestone {$row['name']} converted to {$resp['number']}\n";
		} else {
			// Error
			$error = print_r($resp, 1);
			echo "Failed to convert milestone {$row['name']}: $error\n";
		}
	}
	// Serialize to restore in future
	file_put_contents($save_milestones, serialize($milestones));
}

$labels = array();
$labels['T'] = array();
$labels['C'] = array();
$labels['P'] = array();
$labels['R'] = array();
if (file_exists($save_labels)) {
	$labels = unserialize(file_get_contents($save_labels));
}

if (!$skip_labels) {
    // Export all "labels"
	$res = $trac_db->query("SELECT DISTINCT 'T' label_type, type       name, 'cccccc' color
	                        FROM ticket WHERE IFNULL(type, '')       <> ''
							UNION
							SELECT DISTINCT 'C' label_type, component  name, '0000aa' color
	                        FROM ticket WHERE IFNULL(component, '')  <> ''
							UNION
							SELECT DISTINCT 'P' label_type, priority   name, case when lower(priority) = 'urgent' then 'ff0000'
							                                                      when lower(priority) = 'high'   then 'ff6666'
																				  when lower(priority) = 'medium' then 'ffaaaa'
																				  when lower(priority) = 'low'    then 'ffdddd'
																				  else                                 'aa8888' end color
	                        FROM ticket WHERE IFNULL(priority, '')   <> ''
							UNION
							SELECT DISTINCT 'R' label_type, resolution name, '55ff55' color
	                        FROM ticket WHERE IFNULL(resolution, '') <> ''");

	foreach ($res->fetchAll() as $row) {
		$resp = github_add_label(array(
			'name' => $row['label_type'] . ': ' . $row['name'],
			'color' => $row['color']
		));

		if (isset($resp['url'])) {
			// OK
			$labels[$row['label_type']][crc32($row['name'])] = $resp['name'];
			echo "Label {$row['name']} converted to {$resp['name']}\n";
		} else {
			// Error
			$error = print_r($resp, 1);
			echo "Failed to convert label {$row['name']}: $error\n";
		}
	}
	// Serialize to restore in future
	file_put_contents($save_labels, serialize($labels));
}

// Try get previously fetched tickets
$tickets = array();
if (file_exists($save_tickets)) {
	$tickets = unserialize(file_get_contents($save_tickets));
}


if (!$skip_tickets) {
	// Export tickets
	$limit = $ticket_limit > 0 ? "LIMIT $ticket_offset, $ticket_limit" : '';
        $res = $trac_db->query("SELECT * FROM `ticket` ORDER BY `id` $limit");
        echo "Fetched list of tickets.\n";
	foreach ($res->fetchAll() as $row) {
                echo "Found a ticket\n";
                if ($row['component'] != "DBS") {
                  echo "Not a DBS ticket\n";
                  continue;                
                }
		if (empty($row['milestone'])) {
                  echo "No milestone set\n";
		  $row['milestone']='NoneSet';
                  //	continue;
		}
		if (empty($row['owner']) || !isset($users_list[$row['owner']])) {
			$row['owner'] = $username;
		}
		$ticketLabels = array();
		if (!empty($labels['T'][crc32($row['type'])])) {
		    $ticketLabels[] = $labels['T'][crc32($row['type'])];
		}
		if (!empty($labels['C'][crc32($row['component'])])) {
		    $ticketLabels[] = $labels['C'][crc32($row['component'])];
		}
		if (!empty($labels['P'][crc32($row['priority'])])) {
		    $ticketLabels[] = $labels['P'][crc32($row['priority'])];
		}
		if (!empty($labels['R'][crc32($row['resolution'])])) {
		    $ticketLabels[] = $labels['R'][crc32($row['resolution'])];
		}
                $new_body = translate_markup('**Original TRAC ticket '.$row['id'].' reported by '.$row['reporter']."**\n".$row['description']);

        // There is a strange issue with summaries containing percent signs...
		$resp = github_add_issue(array(
			'title' => preg_replace("/%/", '[pct]', $row['summary']),
			'body' => $new_body,
			'assignee' => isset($users_list[$row['owner']]) ? $users_list[$row['owner']] : $row['owner'],
			'milestone' => $milestones[crc32($row['milestone'])],
			'labels' => $ticketLabels
		));
		if (isset($resp['number'])) {
			// OK
			$tickets[$row['id']] = (int) $resp['number'];
			echo "Ticket #{$row['id']} converted to issue #{$resp['number']}\n";
			if ($row['status'] == 'closed') {
				// Close the issue
				$resp = github_update_issue($resp['number'], array(
					'title' => preg_replace("/%/", '[pct]', $row['summary']),
					'body' => $new_body,
					'assignee' => isset($users_list[$row['owner']]) ? $users_list[$row['owner']] : $row['owner'],
					'milestone' => $milestones[crc32($row['milestone'])],
					'labels' => $ticketLabels,
					'state' => 'closed'
				));
				if (isset($resp['number'])) {
					echo "Closed issue #{$resp['number']}\n";
				}
			}

		} else {
			// Error
			$error = print_r($resp, 1);
			echo "Failed to convert a ticket #{$row['id']}: $error\n";
		}
	}
	// Serialize to restore in future
	file_put_contents($save_tickets, serialize($tickets));
}

if (!$skip_comments) {
	// Export all comments
	$limit = $comments_limit > 0 ? "LIMIT $comments_offset, $comments_limit" : '';
	$res = $trac_db->query("SELECT * FROM `ticket_change` where `field` = 'comment' AND `newvalue` != '' ORDER BY `ticket`, `time` $limit");
	foreach ($res->fetchAll() as $row) {
                if (!$tickets[$row['ticket']]) {
                  echo "Comment for non-migrated ticket. Skipping.\n ";
                  continue;
                }
		$text = strtolower($row['author']) == strtolower($username) ? $row['newvalue'] : '**Author: ' . $row['author'] . "**\n" . $row['newvalue'];
                echo "Original ticket # ".$row['ticket'].", GH ticket # ".$tickets[$row['ticket']]."\n";
		$resp = github_add_comment($tickets[$row['ticket']], translate_markup($text));
		if (isset($resp['url'])) {
			// OK
			echo "Added comment {$resp['url']}\n";
		} else {
			// Error
			$error = print_r($resp, 1);
			echo "Failed to add a comment: $error\n";
		}
	}
}

echo "Done whatever possible, sorry if not.\n";

function github_post($url, $json, $patch = false) {
	global $username, $password;
	$ch = curl_init();
	curl_setopt($ch, CURLOPT_USERPWD, "$username:$password");
	curl_setopt($ch, CURLOPT_URL, "https://api.github.com$url");
	curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
	curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
	curl_setopt($ch, CURLOPT_HEADER, false);
	curl_setopt($ch, CURLOPT_POST, true);
	curl_setopt($ch, CURLOPT_POSTFIELDS, $json);
	curl_setopt($ch, CURLOPT_USERAGENT, "trac2github for $project, admin@example.com");
	if ($patch) {
		curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PATCH');
	}
	$ret = curl_exec($ch);
	if(!$ret) { 
        	trigger_error(curl_error($ch)); 
    	} 
	curl_close($ch);
	return $ret;
}

function github_add_milestone($data) {
	global $project, $repo, $verbose;
	if ($verbose) print_r($data);
	return json_decode(github_post("/repos/$project/$repo/milestones", json_encode($data)), true);
}

function github_add_label($data) {
	global $project, $repo, $verbose;
	if ($verbose) print_r($data);
	return json_decode(github_post("/repos/$project/$repo/labels", json_encode($data)), true);
}

function github_add_issue($data) {
	global $project, $repo, $verbose;
	if ($verbose) print_r($data);
	return json_decode(github_post("/repos/$project/$repo/issues", json_encode($data)), true);
}

function github_add_comment($issue, $body) {
        echo "Adding comment to ticket $issue\n"; 
	global $project, $repo, $verbose;
	if ($verbose) print_r($body);
	return json_decode(github_post("/repos/$project/$repo/issues/$issue/comments", json_encode(array('body' => $body))), true);
}

function github_update_issue($issue, $data) {
	global $project, $repo, $verbose;
	if ($verbose) print_r($body);
	return json_decode(github_post("/repos/$project/$repo/issues/$issue", json_encode($data), true), true);
}

function translate_markup($data) {
    // Replace code blocks with an associated language
    $data = preg_replace('/\{\{\{(\s*#!(\w+))?/m', '```$2', $data);
    $data = preg_replace('/\}\}\}/', '```', $data);

    // Avoid non-ASCII characters, as that will cause trouble with json_encode()
	$data = preg_replace('/[^(\x00-\x7F)]*/','', $data);

    // Possibly translate other markup as well?
    return $data;
}

?>

