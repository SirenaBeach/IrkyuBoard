<?php
function userfields(){return 'u.posts,u.sex,u.powerlevel,u.birthday,u.aka,u.namecolor,u.picture,u.title,u.useranks,u.location,u.lastposttime,u.lastactivity,u.ban_expire';}

function postcode($post,$set){
	global  $controls, $tlayout, $textcolor, $numdir, $numfil, $hacks, $x_hacks, $loguser;

	
	$exp     = calcexp($post['posts'],(ctime()-$post['regdate'])/86400);
	$lvl     = calclvl($exp);
	$expleft = calcexpleft($exp);

	// Not used?
	//$reinf=syndrome($post['act']);

	$sincelastpost 	= "";
	$lastactivity 	= "";
	$since = 'Since: '.printdate($post['regdate'], true);

	$postdate  =  printdate($post['date']);

	$threadlink = "";
	if(filter_string($set['threadlink'])) 
		$threadlink = ", in {$set['threadlink']}";

	/* if($post['edited']){
		$set['edited'].="<hr><font class="fonts">$post['edited']";
	}*/

	//$sidebars	= array(1, 16, 18, 19, 387);
	$noobspan = $post['noob'] ? "<span style='display: inline; position: relative; top: 0; left: 0;'><img src='images/noob/noobsticker2-".mt_rand(1,6).".png' style='position: absolute; top: -3px; left: ".floor(strlen($post['name'])*2.5)."px;' title='n00b'>" : "<span>";
	$height   = $post['deleted'] ? 0 : 220;	
	
	return 
	"<table class='table post tlayout-hydra' id='{$post['id']}'>
		<tr>
			<td class='tdbg{$set['bg']} vatop' rowspan=3 style='width: 20% !important;'>
				{$noobspan}{$set['userlink']}</span>
				<span class='fonts'>
					<br>
					<center>{$set['userpic']}</center><br>
					{$post['title']}<br>
					<br>
				</span>
			</td>
			<td class='tdbg{$set['bg']} vatop' style='height: 1px'>
				<table class='fonts' style='clear: both; width: 100%;'>
					<tr>
						<td>
							Posted on $postdate$threadlink{$post['edited']}
						</td>
						<td style='float: right;'>
							{$controls['quote']}{$controls['edit']}{$controls['ip']}
						</td>
					</tr>
				</table>
			</td>
		</tr>
		<tr>
			<td class='tbl tdbg{$set['bg']} vatop' style='overflow: visible; width: 70%;' height={$height} id='post{$post['id']}'>
				{$post['headtext']}
				{$post['text']}
				{$set['attach']}
				{$post['signtext']}
			</td>
		</tr>
		<tr><td class='tdbg{$set['bg']}'>{$set['rating']}</td></tr>
	</table>
	<br>";
/*
	if (!$set['picture']) $set['picture']	= "images/_.gif";

	if ($_GET['z']) {
		print_r($st['eq']);
	}
	*/
}
