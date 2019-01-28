<?php

// XenForo-like post ratings, except nowhere near as good
// :(

function get_ratings($all = false) {
	global $sql, $loguser;
	return $sql->fetchq("SELECT ".($all ? "*" : "id, image, title, enabled, minpower")." FROM ratings ORDER BY id ASC", PDO::FETCH_UNIQUE, mysql::FETCH_ALL | mysql::USE_CACHE);
}

// Post rating HTML (rating list & post selection)
function ratings_html($post, $ratedata = array(), $mode = MODE_POST) {
	global $ismod, $loguser;
	$list = $vote = $sneak = "";
	$tokenstr = "&auth=".generate_token(TOKEN_VOTE);
	$typestr  = "&type=".($mode == MODE_POST ? "post" : "pm");
	$canrate  = ($loguser['id'] && !$loguser['rating_locked']);
	
	// Enumerate ratings and display the list
	$ratings = get_ratings();
	foreach ($ratings as $id => $data) {
		$picture = rating_image($data);
		if (isset($ratedata[$id]['total']))
			$list .= " &nbsp; {$picture}<span class='text-rating'>&nbsp;{$data['title']}</span>&nbsp;x&nbsp;<strong>{$ratedata[$id]['total']}</strong>";
		if ($canrate && $data['enabled'] && $loguser['powerlevel'] >= $data['minpower'])
			$vote .= "<a href='postratings.php?action=rate&post={$post}&rating={$id}{$typestr}{$tokenstr}' class='icon-rating".(isset($ratedata['my'][$id]) ? " icon-rated" : " ")."'>{$picture}</a> ";
	}
	// Like user ratings, only staff (mods this time) can view the detailed list
	if ($ismod && $list)
		$sneak = " -- <a href='postratings.php?action=view&post={$post}{$typestr}'>Details</a>";
	
	return "<span class='rating-container'>{$list}<span style='float: right'>{$vote}{$sneak}</span></span>";
}

function rating_image($data) {
	return "<img src=\"{$data['image']}\" style='max-width: 16px; max-height: 16px' title=\"".htmlspecialchars($data['title'])."\" align='absmiddle'>";
}
function rating_colors($val, $pts) {
	if ($pts == 0) return $val;
	if ($pts > 0)  return "<span style='color: #0f0'>{$val}</span>";
	if ($pts < 0)  return "<span style='color: #f00'>{$val}</span>";
}

function load_ratings($searchon, $min, $ppp, $mode = MODE_POST) {
	global $sql, $loguser;
	if ($mode == MODE_PM) {
		$prefix = "pm_";
		$joinpf = "pm";
	} else {
		$prefix = "";
		$joinpf = "posts";
	}
	//--
	$ratings = $sql->query("
		SELECT a.post, a.rating, a.user
		FROM {$prefix}posts p
		INNER JOIN {$joinpf}_ratings a ON p.id = a.post
		WHERE {$searchon}
		ORDER BY p.id
		LIMIT $min,$ppp
	");
	$out = array();
	while ($x = $sql->fetch($ratings)) {
		// Keep a count of total ratings
		if (!isset($out[$x['post']][$x['rating']]))
			$out[$x['post']][$x['rating']]['total'] = 1;
		else
			$out[$x['post']][$x['rating']]['total']++;
		// Flag is the logged in user has selected that rating
		if ($x['user'] == $loguser['id'])
			$out[$x['post']]['my'][$x['rating']] = true;
	}
	return $out;
}


function rate_post($post, $rating, $mode = MODE_POST) {
	global $sql, $loguser;
	if ($mode == MODE_PM) {
		$joinpf = "pm";
		$fmode  = 1;
	} else {
		$joinpf = "posts";
		$fmode  = 0;
	}
	//--
	$data = $sql->fetchq("
		SELECT r.id, SUM(a.rating) voted
		FROM ratings r
		LEFT JOIN {$joinpf}_ratings a ON r.id = a.rating
		WHERE r.id = {$rating} AND r.enabled = 1 AND r.minpower <= {$loguser['powerlevel']}
		  AND a.user = {$loguser['id']} AND a.post = {$post}
	");
	if (!$data['id']) { // whoop de whoop the rating doesn't exist
		return false;
	} else if ($data['voted']) {
		delete_post_rating($loguser['id'], $post, $rating, $mode);
	} else {
		$sql->query("INSERT INTO {$joinpf}_ratings (user, post, rating, `date`) VALUES ({$loguser['id']}, {$post}, {$rating}, ".ctime().")");
		// User cache update
		
		$res = $sql->query("
			UPDATE ratings_cache SET total = total + 1 
			WHERE (
				 (type = 0 AND user = {$loguser['id']}) -- given
			  OR (type = 1 AND user = (SELECT user FROM posts WHERE id = {$post})) -- received
			  )
			  AND rating = {$rating}
			  AND mode = {$fmode}
		");
		if ($sql->num_rows($res) != 2) { // Row not present in the cache yet
			//die("Missing cache on insert.");
			$rateduser = $sql->resultq("SELECT user FROM posts WHERE id = {$post}");
			if (!$sql->resultq("SELECT COUNT(*) FROM ratings_cache WHERE type = 0 AND user = {$loguser['id']} AND rating = {$rating} AND mode = {$fmode}"))
				$sql->query("INSERT INTO ratings_cache (user, mode, type, rating, total) VALUES ({$loguser['id']}, {$fmode}, 0, {$rating}, 1)");
			if (!$sql->resultq("SELECT COUNT(*) FROM ratings_cache WHERE type = 1 AND user = {$rateduser} AND rating = {$rating} AND mode = {$fmode}"))
				$sql->query("INSERT INTO ratings_cache (user, mode, type, rating, total) VALUES ({$rateduser}, {$fmode}, 1, {$rating}, 1)");	
		}			
	}
	return true;
}

function delete_post_rating($user, $post, $rating, $mode = MODE_POST) {
	global $sql;
	if ($mode == MODE_PM) {
		$joinpf = "pm";
		$fmode   = 1;
	} else {
		$joinpf = "posts";
		$fmode   = 0;
	}
	//--
	$sql->query("DELETE FROM {$joinpf}_ratings WHERE user = {$user} AND post = {$post} AND rating = {$rating}");
	// User cache update
	$sql->query("
		UPDATE ratings_cache SET total = total - 1 
		WHERE (
		     (type = 0 AND user = {$user}) -- given
		  OR (type = 1 AND user = (SELECT user FROM posts WHERE id = {$post})) -- received
		  )
		  AND rating = {$rating}
		  AND mode = {$fmode}
	");
}

// Detail view for a single post/pm
function get_post_ratings($post, $mode = MODE_POST) {
	global $sql, $userfields;
	if ($mode == MODE_PM) {
		$joinpf = "pm";
	} else {
		$joinpf = "posts";
	}
	//--
	return $sql->fetchq("
		SELECT a.rating, {$userfields} uid
		FROM {$joinpf}_ratings a
		LEFT JOIN users u ON a.user = u.id
		WHERE a.post = {$post}
	", PDO::FETCH_GROUP, mysql::FETCH_ALL);
}

// Here we DO calculate the total
function get_user_post_ratings($user, $mode = MODE_POST) {
	global $sql;
	$mode = (int)($mode == MODE_PM);
	//--
	
	$ratings = $sql->query("
		SELECT type, rating, total
		FROM ratings_cache
		WHERE user = {$user} AND mode = {$mode}
	");
	$out = array(array(),array());
	while ($x = $sql->fetch($ratings)) {
		$out[$x['type']][$x['rating']] = $x['total'];
	}
	return $out;
}

// CACHE TIME!
// type 0 -> given; 1 ->received
function resync_post_ratings() {
	global $sql;
	$prefixes = array('','pm_');
	$joinpfs  = array('posts','pm');
	$sql->query("TRUNCATE ratings_cache");
	$users = $sql->getresults("SELECT id FROM users");
	for ($i = 0; $i < 1; ++$i) {
		$resync = $sql->prepare("
			INSERT INTO ratings_cache (user, mode, type, rating, total)
			SELECT IF(a.user = ?,a.user,p.user) user, {$i}, IF(a.user = ?,0,1) `key`, a.rating, COUNT(*) total
			FROM {$prefixes[$i]}posts p
			INNER JOIN {$joinpfs[$i]}_ratings a ON p.id = a.post
			WHERE p.user = ? OR a.user = ?
			GROUP BY `key`, a.rating
		"); //  --, SUM(r.points) points
		foreach ($users as $user)
			$sql->execute($resync, [$user, $user, $user, $user]);
	}
}
//resync_post_ratings();