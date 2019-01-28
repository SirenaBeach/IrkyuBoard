<?php
	require 'lib/function.php';

	pageheader("Biggest posters");

	$_GET['sort'] = filter_string($_GET['sort']);
	if ($_GET['sort'] == "posts")   { $sort = "posts";   $headertext = 'sorted by post count'; }
	elseif ($_GET['sort'] == "avg") { $sort = "average"; $headertext = 'by average post size'; }
	else                            { $sort	= "waste";   $headertext = 'by post size'; }

	// Time for an update?
	if ($sql->resultq("SELECT bigpostersupdate FROM misc") <= ctime()-(3600 * 8)) {
		$sql->query("TRUNCATE biggestposters");
		/*$sql->query(" "
		." "
		."SELECT  "
		."FROM `posts` p "
		."LEFT JOIN `users` u ON p.user = u.id "
		."LEFT JOIN `posts_text` pt on p.id = pt.pid "
		."WHERE (u.posts >= 5 OR u.posts < 0) GROUP BY p.user");*/
		$sql->query("
			INSERT INTO biggestposters (id, posts, waste)
				SELECT u.id, u.posts, SUM(LENGTH(p.text))
				FROM posts p
				LEFT JOIN users u ON p.user = u.id
				WHERE u.posts >= 5 OR u.posts < 0
				GROUP BY p.user
		");
		$sql->query("UPDATE biggestposters SET average = waste / posts");
		$sql->query("UPDATE misc SET bigpostersupdate = ".ctime());
	}
	$posters = $sql->query("
		SELECT bp.*, $userfields x, u.regdate
		FROM biggestposters bp
		LEFT JOIN users u ON bp.id = u.id
		ORDER BY $sort DESC
	");

  ?>
	<table class='table'>
		<tr><td class='tdbgc center' colspan=7><b>Biggest posters, <?=$headertext?></b></td></tr>
		<tr>
			<td class='tdbgh center' width=30>#</td>
			<td class='tdbgh center' colspan=2>Username</td>
			<td class='tdbgh center' width=200>Registered on</td>
			<td class='tdbgh center' width=130><a href="?sort=posts">Posts</a></td>
			<td class='tdbgh center' width=130><a href="?">Size</a></td>
			<td class='tdbgh center' width=130><a href="?sort=avg">Average</a></td>
		</tr>
	<?php
	
	$oldcnt = NULL;
	for($i = 1; $user = $sql->fetch($posters); ++$i) {
		//if ($i == 1) $max = $user['waste'];
		if ($user['waste'] != $oldcnt) $rank = $i;
		$oldcnt	= $user['waste'];
		//$namecolor=getnamecolor($user['sex'],$user['powerlevel']);
		$userlink = getuserlink($user); // bp.id matches u.id - no uid substitution needed
		
		if 		($user['average'] >=  750) $col	= "#8888ff";
		else if ($user['average'] >=  500) $col	= "#88ff88";
		else if ($user['average'] <=    0) $col	= "#888888";
		else if ($user['average'] <=  100) $col	= "#ff8080";
		else if ($user['average'] <=  200) $col	= "#ffff80";
		else $col = "";
		
		$avgc	= number_format(abs($user['average']), 1);
		if ($col) $avgc	= "<font color=$col>$avgc</font>";

		?>
		<tr>
			<td class='tdbg1 center'><?=$rank?></td>
			<td class='tdbg1 center' style='width: <?= $config['max-minipic-size-x'] ?>px'><?= get_minipic($user['id'], $user['minipic']) ?></td>
			<td class='tdbg2'><?=$userlink?></td>
			<td class='tdbg1 center'><?=printdate($user['regdate'])?></td>
			<td class='tdbg1 right'><?=$user['posts']?></td>
			<td class='tdbg1 right b'><?=number_format($user['waste'])?></td>
			<td class='tdbg2 right b'><?=$avgc?></td>
		</tr>
		<?php
	}

	?>
	</table>
	<span class="fonts">
		(Note: this doesn't take into account quotes, joke posts, or other things. It isn't a very good judge of actual post content, just post <i>size</i>.)
		<br>(This table is cached and updated every few hours.)
	</span>
	<?php
	
	pagefooter();