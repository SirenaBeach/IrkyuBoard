<?php
	require 'lib/function.php';
	
	$meta['noindex'] = true;
		
	$_GET['id']         = filter_int($_GET['id']);
	$_GET['action']     = filter_string($_GET['action']);
	$_POST['action']    = filter_string($_POST['action']);

	load_thread($_GET['id']);
	check_forumban($forum['id'], $loguser['id']);
	$ismod = ismod($forum['id']);
	if ($forum_error) {
		$forum_error = "<table class='table'>{$forum_error}</table>";
	}

	if (!$loguser['id'])
		errorpage("You are not logged in.",'login.php', 'log in (then try again)');
	if ($loguser['editing_locked']) 
		errorpage("You are not allowed to edit your threads.", 'index.php', 'return to the board');
	if (!$ismod && ($loguser['id'] != $thread['user'] || $thread['closed']))
		errorpage("You are not allowed to edit this thread.", 'thread.php?id='.$_GET['id'], 'return to the thread');

	// Quickmod
	if ($ismod && substr($_GET['action'], 0, 1) == 'q') {
		check_token($_GET['auth'], TOKEN_MGET);
		$update = "";
		switch ($_GET['action']) {
			case 'qstick':   $update = 'sticky=1'; break;
			case 'qunstick': $update = 'sticky=0'; break;
			case 'qclose':   $update = 'closed=1'; break;
			case 'qunclose': $update = 'closed=0'; break;
			case 'qfeat':    feature_thread($_GET['id']); break;
			case 'qunfeat':  unfeature_thread($_GET['id']); break;
			default: return header("Location: thread.php?id={$_GET['id']}");
		}
		if ($update)
			$sql->query("UPDATE threads SET {$update} WHERE id={$_GET['id']}");
		return header("Location: thread.php?id={$_GET['id']}");
	}
	else if ($ismod && $_GET['action'] == 'trashthread') {
		pageheader(NULL, $forum['specialscheme'], $forum['specialtitle']);
		
		$message       = "Are you sure you want to trash this thread?";
		$form_link     = "editthread.php?action=trashthread&id={$_GET['id']}";
		$buttons       = array(
			0 => ["Trash Thread"],
			1 => ["Cancel", "thread.php?id={$_GET['id']}"]
		);
		
		if (confirmpage($message, $form_link, $buttons, TOKEN_SLAMMER)) {		
			$sql->beginTransaction();
			move_thread($_GET['id'], $config['trash-forum'], $thread);
			$sql->commit();
			errorpage("Thread successfully trashed.","thread.php?id={$_GET['id']}",'return to the thread');
		}
	}
	else if ($sysadmin && filter_bool($_POST['deletethread']) && $config['allow-thread-deletion']) {
		pageheader(NULL, $forum['specialscheme'], $forum['specialtitle']);	

		$message = "
			<big><b>DANGER ZONE</b></big><br>
			<br>
			Are you sure you want to permanently <b>delete</b> this thread and <b>all of its posts</b>?<br>
			<br><input type='hidden' name='deletethread' value=1>
			<input type='checkbox' class='radio' name='reallysure' id='reallysure' value=1> <label for='reallysure'>I'm sure</label>
		";
		$form_link     = "editthread.php?id={$_GET['id']}";
		$buttons       = array(
			0 => ["Delete thread"],
			1 => ["Cancel", "thread.php?id={$_GET['id']}"]
		);
		
		if (confirmpage($message, $form_link, $buttons, TOKEN_SLAMMER)) {	
			// Double-confirm the checkbox 
			if (!filter_bool($_POST['reallysure'])) {
				errorpage("You haven't confirmed the choice.", "thread.php?id={$_GET['id']}", 'the thread');
			}
			
			$sql->beginTransaction();
			
			$sql->query("DELETE FROM threads WHERE id={$_GET['id']}");
			$sql->query("DELETE FROM posts_old WHERE pid IN (SELECT id FROM posts WHERE thread = {$_GET['id']})");
			$deleted = $sql->query("DELETE FROM posts WHERE thread = {$_GET['id']}");
			$numdeletedposts = $sql->num_rows($deleted);
			
			// Update forum status
			$t1 = $sql->fetchq("SELECT lastpostdate, lastposter	FROM threads WHERE forum = {$thread['forum']} ORDER BY lastpostdate DESC LIMIT 1");
			$sql->queryp("UPDATE forums SET numposts=numposts-$numdeletedposts,numthreads=numthreads-1,lastpostdate=?,lastpostuser=? WHERE id={$thread['forum']}", array((int) $t1['lastpostdate'], (int) $t1['lastposter']));
			
			if ($config['allow-attachments']) {
				$attachids = get_thread_attachments($_GET['id']);
				if ($attachids) {
					remove_attachments($attachids);
				}
			}
			$sql->commit();
			$fname = $sql->resultq("SELECT title FROM forums WHERE id = {$thread['forum']}");			
			errorpage("Thank you, {$loguser['name']}, for deleting the thread.", "forum.php?id={$thread['forum']}", $fname);
			
		}
	}
	else {
		pageheader(NULL, $forum['specialscheme'], $forum['specialtitle']);
		
		$links = array(
			[$forum['title']    , "forum.php?id={$forum['id']}"],
			[$thread['title']   , "thread.php?id={$_GET['id']}"],
			["Edit thread"      , NULL],
		);
		$barlinks = dobreadcrumbs($links); 
		
		if (isset($_POST['submit'])) {
			check_token($_POST['auth']);
			
			$_POST['iconid'] 		= filter_int($_POST['iconid']);
			$_POST['custposticon'] 	= filter_string($_POST['custposticon']);
			
			$posticons 			= file('posticons.dat');
				
			if ($_POST['custposticon'])
				$icon = xssfilters($_POST['custposticon']);
			else if (isset($posticons[$_POST['iconid']]))
				$icon = trim($posticons[$_POST['iconid']]);
			else
				$icon = "";
			
			$_POST['subject'] = filter_string($_POST['subject']);
			if (!$_POST['subject']) 
				errorpage("Couldn't edit the thread. You haven't entered a subject.");

			if ($ismod) {
				$_POST['forummove']    = filter_int($_POST['forummove']);
				$_POST['closed']       = filter_int($_POST['closed']);
				$_POST['sticky']       = filter_int($_POST['sticky']);
				$_POST['announcement'] = filter_int($_POST['announcement']);
				$_POST['featured']     = filter_int($_POST['featured']);
			} else {
				$_POST['forummove']    = $thread['forum'];
				$_POST['closed']       = $thread['closed'];
				$_POST['sticky']       = $thread['sticky'];
				$_POST['announcement'] = $thread['announcement'];
				$_POST['featured']     = $thread['featured'];
			}
			$_POST['featarch'] = filter_int($_POST['featarch']); // Delete archive option
			
			// Here we go
			$sql->beginTransaction();
			
			$data = [
				'title'        => xssfilters($_POST['subject']),
				'description'  => xssfilters(filter_string($_POST['description'])),
				'icon'         => $icon,
				'closed'       => $_POST['closed'],
				'sticky'       => $_POST['sticky'],
				'announcement' => $_POST['announcement'],
			];
			// Unfeature thread (with optional deletion from archive)
			if ($ismod && ($thread['featured'] != $_POST['featured'] || (!$_POST['featured'] && $_POST['featarch']))) {
				$data['featured'] = $_POST['featured'];
				if ($data['featured']) {
					feature_thread($_GET['id'], false);
				} else {
					unfeature_thread($_GET['id'], false, true, $_POST['featarch']);
				}
			}
			
			$sql->queryp("UPDATE threads SET ".mysql::setplaceholders($data)." WHERE id = {$_GET['id']}", $data);
			
			if ($_POST['forummove'] != $thread['forum']) {
				move_thread($_GET['id'], $_POST['forummove'], $thread);
			}
			
			$sql->commit();
			errorpage("Thank you, {$loguser['name']}, for editing the thread.","thread.php?id={$_GET['id']}",'return to the thread');
		}
		
		$posticonlist = dothreadiconlist(NULL, $thread['icon']);
		
		$check1[$thread['closed']]='checked=1';
		$check2[$thread['sticky']]='checked=1';
		$check3[$thread['announcement']]='checked=1';
		$check4[$thread['featured']]='checked=1';
		
		
		$forummovelist = doforumlist($thread['forum'], 'forummove'); // Return a pretty forum list
		
		
		if ($sysadmin && $config['allow-thread-deletion']) {
			$delthread = " <input type=checkbox class=radio name=deletethread value=1> Delete thread";
		} else
			$delthread = "";
		
		?>
		<?= $barlinks . $forum_error ?>
		<form method='POST' action='?id=<?=$_GET['id']?>'>
		<table class='table'>
			<tr>
				<td class='tdbgh' style='width: 150px'>&nbsp;</td>
				<td class='tdbgh'>&nbsp;</td>
			</tr>
			
			<tr>
				<td class='tdbg1 center b'>Thread title:</td>
				<td class='tdbg2'>
					<input type='text' name='subject' value="<?=htmlspecialchars($thread['title'])?>" SIZE=40 MAXLENGTH=100>
				</td>
			</tr>
			<tr>
				<td class='tdbg1 center b'>Thread description:</td>
				<td class='tdbg2'>
					<input type='text' name=description value="<?=htmlspecialchars($thread['description'])?>" SIZE=100 MAXLENGTH=120>
				</td>
			</tr>
			
			<tr>
				<td class='tdbg1 center b'>Thread icon:</td>
				<td class='tdbg2'><?= $posticonlist ?></td>
			</tr>
<?php	if ($ismod) { ?>
			<tr>
				<td class='tdbg1 center' rowspan=4>&nbsp;</td>
				<td class='tdbg2'>
					<input type=radio class='radio' name=closed value=0 <?=filter_string($check1[0])?>> Open&nbsp; &nbsp;
					<input type=radio class='radio' name=closed value=1 <?=filter_string($check1[1])?>>Closed
				</td>
			</tr>
			<tr>
				<td class='tdbg2'>
					<input type=radio class='radio' name=sticky value=0 <?=filter_string($check2[0])?>> Normal&nbsp; &nbsp;
					<input type=radio class='radio' name=sticky value=1 <?=filter_string($check2[1])?>>Sticky
				</td>
			</tr>
			<tr>
				<td class='tdbg2'>
					<input type=radio class='radio' name=announcement value=0 <?=filter_string($check3[0])?>> Normal Thread&nbsp; &nbsp;
					<input type=radio class='radio' name=announcement value=1 <?=filter_string($check3[1])?>>Forum Announcement
				</td>
			</tr>
			<tr>
				<td class='tdbg2'>
					<input type=radio class='radio' name=featured value=0 <?=filter_string($check4[0])?> onclick="hideArch(0)"> Unfeatured &nbsp; &nbsp;
					<input type=radio class='radio' name=featured value=1 <?=filter_string($check4[1])?> onclick="hideArch(1)"> Featured &nbsp; &nbsp;
					 <input type="checkbox" name="featarch" id="featarch" value=1> Delete from archives
				</td>
			</tr>
			
			<tr>
				<td class='tdbg1 center b'>Forum</td>
				<td class='tdbg2'><?= $forummovelist . $delthread ?></td>
			</tr>
<?php	} ?>
			<tr>
				<td class='tdbg1'>&nbsp;</td>
				<td class='tdbg2'>
					<?= auth_tag() ?>
					<input type='submit' class='submit' name='submit' VALUE="Edit thread">
				</td>
			</tr>
		</table>
		</form>
<script type="text/javascript">
	var choice = document.getElementById('featarch');
	function hideArch(sel) {
		if (choice !== undefined) {
			if (sel) {
				choice.disabled = true;
				choice.checked = false;
			} else {
				choice.disabled = false;
			}
		}
	}
	hideArch(<?= $thread['featured'] ?>)
</script>
		
		<?= $barlinks ?>
		<?php
	}
	
	pagefooter();
	