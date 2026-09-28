<?php
/** Archive cleanup invoked by the existing Freemius uninstall callback only. */
if (!defined('ABSPATH')) { exit; }

/** Drain bounded batches in this request: there will be no future plugin requests. */
function pgc_sgb_archives_uninstall_site($deadline)
{
	$trigger = 'start';
	do {
		if (microtime(true) >= $deadline) {
			return new WP_Error('archive_cleanup_timeout');
		}
		$state = pgc_sgb_archives_cleanup_run($trigger);
		if ($state['status'] === 'complete' && !$state['purge']) { return true; }
		if (in_array($state['status'], array('error', 'waiting'), true)) {
			return new WP_Error('archive_cleanup_' . $state['status']);
		}
		$trigger = 'continue';
	} while (true);
}

/** Network deletion must also visit inactive subsites with their own uploads paths. */
function pgc_sgb_archives_uninstall()
{
	$deadline = microtime(true) + 10;
	if (!is_multisite()) { return pgc_sgb_archives_uninstall_site($deadline); }
	$offset = 0;
	do {
		if (microtime(true) >= $deadline) { return new WP_Error('archive_cleanup_timeout'); }
		$ids = get_sites(array('fields' => 'ids', 'number' => 100, 'offset' => $offset, 'orderby' => 'id', 'order' => 'ASC'));
		foreach ($ids as $id) {
			switch_to_blog($id);
			try { $result = pgc_sgb_archives_uninstall_site($deadline); }
			finally { restore_current_blog(); }
			if (is_wp_error($result)) { return $result; }
		}
		$offset += count($ids);
	} while (count($ids) === 100);
	return true;
}
