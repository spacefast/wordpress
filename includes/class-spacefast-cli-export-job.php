<?php

defined( 'ABSPATH' ) || exit;

/** Adapt the free Simply Static worker without replacing its queue or task lifecycle. */
class Spacefast_CLI_Export_Job extends \Simply_Static\Archive_Creation_Job {
	public static bool $inline = false;
	private bool $task_finished = false;

	public function dispatch() {
		// start() must finish recording its generation before any task executes.
		return self::$inline ? array() : parent::dispatch();
	}

	public function run_step(): bool {
		// Another request may have advanced this export while we waited. Flush
		// only request-local cache entries, never a shared persistent cache.
		if ( function_exists( 'wp_cache_supports' ) && wp_cache_supports( 'flush_runtime' ) ) wp_cache_flush_runtime();
		$this->set_current_site_id( get_current_blog_id() );
		if ( $this->is_paused() || $this->is_cancelled() ) {
			throw new RuntimeException( 'Simply Static export was paused or canceled.' );
		}
		if ( $this->is_processing() ) return false;
		$options = \Simply_Static\Options::reinstance();
		$this->set_options( $options );
		\Simply_Static\Plugin::instance()->set_options( $options );
		if ( ! $this->is_queued() ) return false;
		// The worker persists each step and releases its own lock. Yield between
		// tasks so the caller can enforce its deadline and back off API polling.
		// Tasks such as fetch_urls also ask should_continue before every page,
		// so keep Simply Static's own time and memory limits while a task runs
		// and stop only once it returns; a blanket false would fetch no pages.
		$this->task_finished = false;
		$yield = fn( $continue ): bool => (bool) $continue && ! $this->task_finished;
		add_filter( 'wp_archive_creation_job_should_continue', $yield );
		try {
			$this->handle();
		} finally {
			remove_filter( 'wp_archive_creation_job_should_continue', $yield );
		}
		return true;
	}

	/** @param mixed $item Queued task name. */
	protected function task( $item ) {
		try {
			return parent::task( $item );
		} finally {
			$this->task_finished = true;
		}
	}
}
