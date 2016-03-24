<?php 
/**
 * First we clear memcached  object cache. 
 * Second we clear page cache
 * third we clear squid cache
 * TODO: clear CDN cache as an option.
 *
 */
class InvalidateVoteCacheJob extends Job {
	public function __construct( $title, $params ) {
		// Replace synchroniseThreadArticleData with an identifier for your job.
		parent::__construct( 'invalidateVoteCacheJob', $title, $params );
	}

	/**
	 * Execute the job
	 *
	 * @return bool
	 */
	public function run() {
		global $wgMemc;

		// Kill internal cache
		$wgMemc->delete( wfMemcKey( 'vote', 'count', $this->title->getArticleId() ) );
		$wgMemc->delete( wfMemcKey( 'vote', 'avg', $this->title->getArticleId() ) );

		// Purge squid
		$pageTitle = $this->title;
		if ( is_object( $pageTitle ) ) {
			$pageTitle->invalidateCache();
			$pageTitle->purgeSquid();

			// Kill parser cache
			$article = new Article( $pageTitle, /* oldid */0 );
			$parserCache = ParserCache::singleton();
			$parserKey = $parserCache->getKey( $article, User::newFromId($this->params['userid']) );
			$wgMemc->delete( $parserKey );
		}
	}
}