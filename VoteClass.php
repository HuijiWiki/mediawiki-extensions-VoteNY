<?php
/**
 * Vote class - class for handling vote-related functions (counting
 * the average score of a given page, inserting/updating/removing a vote etc.)
 *
 * @file
 * @ingroup Extensions
 */
class Vote {
	public $PageID = 0;
	public $Userid = 0;
	public $Username = null;

	/**
	 * Constructor
	 *
	 * @param int $pageID Article ID number
	 */
	public function __construct( $pageID ) {
		global $wgUser;

		$this->PageID = $pageID;
		$this->Username = $wgUser->getName();
		$this->Userid = $wgUser->getID();
	}

	/**
	 * Counts all votes, fetching the data from memcached if available
	 * or from the database if memcached isn't available
	 *
	 * @return int Amount of votes
	 */
	function count() {
		global $wgMemc;

		$key = wfMemcKey( 'vote', 'count', $this->PageID );
		$data = $wgMemc->get( $key );

		// Try cache
		if ( $data ) {
			wfDebug( "Loading vote count for page {$this->PageID} from cache\n" );
			$vote_count = $data;
		} else {
			$dbr = wfGetDB( DB_SLAVE );
			$vote_count = 0;
			$res = $dbr->select(
				'Vote',
				'COUNT(*) AS votecount',
				array( 'vote_page_id' => $this->PageID ),
				__METHOD__
			);
			$row = $dbr->fetchObject( $res );
			if ( $row ) {
				$vote_count = $row->votecount;
			}
			$wgMemc->set( $key, $vote_count );
		}

		return $vote_count;
	}

	/**
	 * Gets the average score of all votes
	 *
	 * @return int Formatted average number of votes (something like 3.50)
	 */
	function getAverageVote() {
		global $wgMemc;
		$key = wfMemcKey( 'vote', 'avg', $this->PageID );
		$data = $wgMemc->get( $key );

		$voteAvg = 0;
		if ( $data ) {
			wfDebug( "Loading vote avg for page {$this->PageID} from cache\n" );
			$voteAvg = $data;
		} else {
			$dbr = wfGetDB( DB_SLAVE );
			$res = $dbr->select(
				'Vote',
				'AVG(vote_value) AS voteavg',
				array( 'vote_page_id' => $this->PageID ),
				__METHOD__
			);
			$row = $dbr->fetchObject( $res );
			if ( $row ) {
				$voteAvg = $row->voteavg;
			}
			$wgMemc->set( $key, $voteAvg );
		}

		return number_format( $voteAvg, 2 );
	}

	/**
	 * Clear caches - memcached, parser cache and Squid cache
	 */
	function clearCache() {
		$jobParams = array( 'userid' => $this->Userid );
		$job = new InvalidateVoteCacheJob( Title::newFromId($this->PageID), $jobParams );
		JobQueueGroup::singleton()->push( $job );

		// // Kill internal cache
		// $wgMemc->delete( wfMemcKey( 'vote', 'count', $this->PageID ) );
		// $wgMemc->delete( wfMemcKey( 'vote', 'avg', $this->PageID ) );

		// // Purge squid
		// $pageTitle = Title::newFromID( $this->PageID );
		// if ( is_object( $pageTitle ) ) {
		// 	$pageTitle->invalidateCache();
		// 	$pageTitle->purgeSquid();

		// 	// Kill parser cache
		// 	$article = new Article( $pageTitle, /* oldid */0 );
		// 	$parserCache = ParserCache::singleton();
		// 	$parserKey = $parserCache->getKey( $article, $wgUser );
		// 	$wgMemc->delete( $parserKey );
		// }
	}

	/**
	 * Delete the user's vote from the database, purges normal caches and
	 * updates SocialProfile's statistics, if SocialProfile is active.
	 */
	function delete() {
		$dbw = wfGetDB( DB_MASTER );
		$dbw->delete(
			'Vote',
			array(
				'vote_page_id' => $this->PageID,
				'username' => $this->Username
			),
			__METHOD__
		);

		$this->clearCache();

		// Update social statistics if SocialProfile extension is enabled
		if ( class_exists( 'UserStatsTrack' ) ) {
			$stats = new UserStatsTrack( $this->Userid, $this->Username );
			$stats->decStatField( 'vote' );
		}
	}

	/**
	 * Inserts a new vote into the Vote database table
	 *
	 * @param int $voteValue
	 */
	function insert( $voteValue ) {
		global $wgRequest;
		$dbw = wfGetDB( DB_MASTER );
		wfSuppressWarnings(); // E_STRICT whining
		$voteDate = date( 'Y-m-d H:i:s' );
		wfRestoreWarnings();
		if ( $this->UserAlreadyVoted() == false ) {
			$dbw->insert(
				'Vote',
				array(
					'username' => $this->Username,
					'vote_user_id' => $this->Userid,
					'vote_page_id' => $this->PageID,
					'vote_value' => $voteValue,
					'vote_date' => $voteDate,
					'vote_ip' => $wgRequest->getIP(),
				),
				__METHOD__
			);

			$this->clearCache();

			// Update social statistics if SocialProfile extension is enabled
			if ( class_exists( 'UserStatsTrack' ) ) {
				$stats = new UserStatsTrack( $this->Userid, $this->Username );
				$stats->incStatField( 'vote' );
			}
		}
	}

	/**
	 * Checks if a user has already voted
	 *
	 * @return bool|int False if s/he hasn't, otherwise returns the
	 *                  value of 'vote_value' column from Vote DB table
	 */
	function UserAlreadyVoted() {
		$dbr = wfGetDB( DB_SLAVE );
		$s = $dbr->selectRow(
			'Vote',
			array( 'vote_value' ),
			array(
				'vote_page_id' => $this->PageID,
				'username' => $this->Username
			),
			__METHOD__
		);
		if ( $s === false ) {
			return false;
		} else {
			return $s->vote_value;
		}
	}

	/**
	 * Displays the green voting box
	 *
	 * @return string HTML output
	 */
	function display() {
		global $wgUser;

		$voted = $this->UserAlreadyVoted();

		$make_vote_box_clickable = '';
		if ( $voted == false ) {
			$make_vote_box_clickable = ' vote-clickable';
		}

		$output = "<div class=\"vote-box{$make_vote_box_clickable}\" id=\"votebox\">";
	 	$output .= '<span id="PollVotes" class="vote-number">' . $this->count() . '</span>';
		$output .= '</div>';
		$output .= '<div id="Answer" class="vote-action">';

		if ( !$wgUser->isAllowed( 'voteny' ) ) {
			// @todo FIXME: this is horrible. If we don't have enough
			// permissions to vote, we should tell the end-user /that/,
			// not require them to log in!
			$login = SpecialPage::getTitleFor( 'Userlogin' );
			$output .= '<a class="votebutton" href="' .
				htmlspecialchars( $login->getFullURL() ) . '" rel="nofollow">' .
				wfMessage( 'voteny-link' )->plain() . '</a>';
		} else {
			if ( !wfReadOnly() ) {
				if ( $voted == false ) {
					$output .= '<a href="javascript:void(0);" class="vote-vote-link">' .
						wfMessage( 'voteny-link' )->plain() . '</a>';
				} else {
					$output .= '<a href="javascript:void(0);" class="vote-unvote-link">' .
						wfMessage( 'voteny-unvote-link' )->plain() . '</a>';
				}
			}
		}
		$output .= '</div>';

		return $output;
	}

	/**
	 * get all vote users by votePageId
	 * @param int $votePageId [vote_page_id]
	 * @return array
	 */
	static function getVoteUserByVotePageId( $votePageId ){
		$dbr = wfGetDB( DB_SLAVE );
		$res = $dbr->select(
			'vote',
			array(
				'username',
				'vote_value'
			),
			array(
				'vote_page_id' => $votePageId
			),
			__METHOD__
		);
		if ( $res ) {
			$result = array();
			foreach ($res as $key => $value) {
				$result[] = array(
					'user_name' => $value->username,
					'vote_value' => $value->vote_value
				);
			}
			return $result;
		}

	}
}

/**
 * Class for generating star rating stars.
 */
class VoteStars extends Vote {

	public $maxRating = 5;

	/**
	 * Displays voting stars
	 *
	 * @param bool $voted Has the user already voted? False by default
	 * @return string HTML output
	 */
	function display( $voted = false, $wraper = true ) {
		global $wgUser;

		$overall_rating = $this->getAverageVote();

		if ( $voted ) {
			$display_stars_rating = $voted;
		} else {
			$display_stars_rating = $this->getAverageVote();
		}

	 	$id = $this->PageID;

		// Should probably be $this->PageID or something?
		// 'cause we define $id just above as an empty string...duh
		$output = '';
		if ($wraper){
			$output .= '<div id="rating_' . $id . '" class="col-md-4 secondary">';
		}
		$output .= '<div class="rating-score">';
		$output .= '<div class="voteboxrate">' . $overall_rating . '</div>';
		$output .= '</div>';
		if ( !$wgUser->isLoggedIn() ) {
			$class = 'rating-section need-login';
		}else{
			$class = 'rating-section';
		}
		$output .= '<div class="'.$class.'">';
		$output .= $this->displayStars( $id, $display_stars_rating, $voted );
		$count = $this->count();
		if ( isset( $count ) ) {
			$output .= ' </div><span class="rating-total">' .
				wfMessage( 'voteny-votes', $count )->parse() . '</span>';
		}
		else {
			$output .= "</div>";
		}
		$already_voted = $this->UserAlreadyVoted();
		if ( $already_voted && $wgUser->isLoggedIn() ) {
			$output .= '<div class="rating-voted">' .
				wfMessage( 'voteny-gave-this', $already_voted )->parse() .$this->displayFollowingUserVotes().
			" </div>";
			// <a href=\"javascript:void(0);\" class=\"vote-remove-stars-link\" data-page-id=\"{$this->PageID}\" data-vote-id=\"{$id}\">("
			// 	. wfMessage( 'voteny-remove' )->plain() .
			// ')</a>';
			/*$output .= '<div class="rating-voted">' .
				wfMessage( 'voteny-gave-this', $already_voted )->parse() .
			' </div>';*/
		}
		$output .= '
				<div class="rating-clear">
			</div>';
		if ($wraper){
			$output .= '</div>';			
		}

		return $output;
	}

	/**
	 * Displays the actual star images, depending on the state of the user's mouse
	 *
	 * @param int $id ID of the rating (div) element
	 * @param int $rating Average rating
	 * @param int $voted
	 * @return string Generated <img> tag
	 */
	function displayStars( $id, $rating, $voted ) {
		global $wgExtensionAssetsPath;

		if ( !$rating ) {
			$rating = 0;
		}
		if ( !$voted ) {
			$voted = 0;
		}
		$output = '';

		for ( $x = $this->maxRating; $x >= 1; $x-- ) {
			if ( !$id ) {
				$action = 3;
			} else {
				$action = 5;
			}
			$classes = '';
			switch ( true ) {
				case $rating >= $x:
					if ( $voted ) {
						$classes .= 'voted';
					} else {
						$classes .= 'on';
					}
					break;
				case ( $rating > 0 && $rating < $x && $rating > ( $x - 1 ) ):
					$classes .= 'half';
					break;
				case ( $rating < $x ):
					$classes .= 'off';
					break;
			}
			$output .= "<span class=\"vote-rating-star $classes\" data-vote-the-vote=\"{$x}\"" .
				" data-page-id=\"{$this->PageID}\"" .
				" data-vote-id=\"{$id}\" data-vote-action=\"{$action}\" data-vote-rating=\"{$rating}\"" .
				" data-vote-voted=\"{$voted}\" id=\"rating_{$id}_{$x}\"";
			$output .= '></span>';
		}

		return $output;
	}

	/**
	 * Displays the average score for the current page
	 * and the total amount of votes.
	 *
	 * @return string
	 */
	function displayScore() {
		$count = $this->count();
		return wfMessage( 'voteny-community-score', '<b>' . $this->getAverageVote() . '</b>' )
		->numParams( $count )->text() .
		' (' . wfMessage( 'voteny-ratings' )->numParams( $count )->parse() . ')';
	}

	/**
	 * display following users who did this vote
	 * 
	 */
	function displayFollowingUserVotes(){
		global $wgUser;
		$votePageId = $this->PageID;
		$hjUser = HuijiUser::newFromName( $wgUser->getName() );
		$following = $hjUser->getFollowingUsers();
		$followingList = $resultList = array();
		foreach ($following as $key => $value) {
			$followingList[] = $value['user_name'];
		}
		$voteUser = Vote::getVoteUserByVotePageId( $votePageId );
		foreach ($voteUser as $key => $value) {
			if( in_array($value['user_name'], $followingList) ){
				$resultList[] = array(
					'user_name' => $value['user_name'],
					'vote_value' => $value['vote_value']
				);
			}
		}
		if ( count($resultList)>0 ) {
			$userStr = '';
			$num_count = (count($resultList)>4) ? 4 : count($resultList);
			//Linker::link( User::newFromName($following_voter[3])->getUserPage(), $following_voter[3], array(), array() )
			for ($i=0; $i < $num_count; $i++) { 
				$userStr .= '<li>'.wfMessage('vote-user')->params(Linker::link( User::newFromName($resultList[$i]['user_name'])->getUserPage(), $resultList[$i]['user_name'], array(), array() ), $resultList[$i]['vote_value'])->text().'</li>';
			}
			// if ( count($resultList)>4 ) {
			// 	$userStr .= '等';
			// }
			$output = "<br><ul class='friend-vote'>".$userStr."</ul><br>";
		}else{
			$output = '';
		}
		
		return $output;
	}


}
