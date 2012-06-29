<?php defined('SYSPATH') or die('No direct script access.');

/**
 * The Run task compares the current version of the database with the target
 * version and then executes the necessary commands to bring the database up to
 * date
 *
 * Available config options are:
 *
 * --down
 *
 *   Migrate the group(s) down
 *
 * --up
 *
 *   Migrate the group(s) up
 *
 * --to=(timestamp|+up_migrations|down_migrations)
 *
 *   Migrate to a specific timestamp, or up $up_migrations, or down $down_migrations
 *
 *   Cannot be used with --groups, must be used with --group
 *
 * --group=group
 *
 *   Specify a single group to perform migrations on
 *
 * --groups=group[,group2[,group3...]]
 *
 *   A list of groups that will be used to source migration files.  By default
 *   migrations will be loaded from all available groups.
 *
 *   Note, only --up and --down can be used with --groups
 *
 * --dry-run
 *
 *  No value taken, if this is specified then instead of executing the SQL it
 *  will be printed to the console
 *
 * --quiet
 *
 *  Suppress all unnecessary output.  If --dry-run is enabled then only dry run
 *  SQL will be output
 *
 * @author Matt Button <matthew@sigswitch.com>
 */
class Task_Migrations_Run extends Minion_Task
{
	/**
	 * A set of config options that this task accepts
	 * @var array
	 */
	protected $_options = array(
		'group'   => 'default',
		'groups'  => FALSE,
		'up'      => FALSE,
		'down'    => FALSE,
		'to'      => NULL,
		'dry-run' => FALSE,
		'quiet'   => FALSE,
	);

	protected $_errors_file = 'validation/migrations';

	/**
	 * Migrates the database to the version specified
	 *
	 * @param array Configuration to use
	 */
	protected function _execute(array $config)
	{
		$k_config = Kohana::$config->load('minion/migration');

		$groups  = Arr::get($config, 'group', Arr::get($config, 'groups', NULL));
		$target = $config['to'];

		// If these options are provided and empty set them to true.
		$dry_run = ($config['dry-run'] === NULL);;
		$quiet   = ($config['quiet'] === NULL);
		$up      = ($config['up'] === NULL);
		$down    = ($config['down'] === NULL);

		$groups  = $this->_parse_groups($groups);

		if ($target === NULL)
		{
			if ($down)
			{
				$target = FALSE;
			}
			else
			{
				$target = TRUE;
			}
		}

		// add config-dir to the kohana config path array. this is so we can
		// set apply the test configs when using the cli
		$config_dir = Arr::get($config, 'config-dir', NULL);
		if ($config_dir && file_exists(APPPATH.'config/'.$config_dir)) {
			Kohana::$config->attach(new Config_File('config/'.$config_dir));
		}

		$db        = Database::instance();
		$model     = new Model_Minion_Migration($db);

		$model->ensure_table_exists();

		$manager = new Minion_Migration_Manager($db, $model);

		$manager
			// Sync the available migrations with those in the db
			->sync_migration_files()
			->set_dry_run($dry_run);

		try
		{
			// Run migrations for specified groups & versions
			$manager->run_migration($groups, $target);
		}
		catch(Minion_Migration_Exception $e)
		{
			echo View::factory('minion/task/migrations/run/exception')
				->set('migration', $e->get_migration())
				->set('error',     $e->getMessage());

			throw $e;
		}

		$view = View::factory('minion/task/migrations/run')
			->set('dry_run', $dry_run)
			->set('quiet', $quiet)
			->set('dry_run_sql', $manager->get_dry_run_sql())
			->set('executed_migrations', $manager->get_executed_migrations())
			->set('group_versions', $model->get_group_statuses());

		return $view;
	}

	/**
	 * Parses a comma delimited set of groups and returns an array of them
	 *
	 * @param  string Comma delimited string of groups
	 * @return array  Locations
	 */
	protected function _parse_groups($group)
	{
		if (is_array($group))
			return $group;

		$group = trim($group);

		if (empty($group))
			return array();

		$groups = array();
		$group  = explode(',', trim($group, ','));

		if ( ! empty($group))
		{
			foreach ($group as $a_group)
			{
				$groups[] = trim($a_group, '/');
			}
		}

		return $groups;
	}

	/**
	 * Parses a set of target versions from user input
	 *
	 * Valid input formats for targets are:
	 *
	 *    TRUE
	 *
	 *    FALSE
	 *
	 *    {group}:(TRUE|FALSE|{migration_id})
	 *
	 * @param  string Target version(s) specified by user
	 * @return array  Versions
	 */
	protected function _parse_target_versions($versions)
	{
		if (empty($versions))
			return array();

		$targets = array();

		if ( ! is_array($versions))
		{
			$versions = explode(',', trim($versions));
		}

		foreach ($versions as $version)
		{
			$target = $this->_parse_version($version);

			if (is_array($target))
			{
				list($group, $version) = $target;

				$targets[$group] = $version;
			}
			else
			{
				$this->_default_direction = $target;
			}
		}

		return $targets;
	}

	/*
	 * Helper function for parsing target versions in user input
	 *
	 * @param  string         Input migration target
	 * @return boolean|string The parsed target
	 */
	protected function _parse_version($version)
	{
		if (is_bool($version))
			return $version;

		if ($version === 'TRUE' OR $version == 'FALSE')
			return $version === 'TRUE';

		if (strpos($version, ':') !== FALSE)
			return explode(':', $version);

		throw new Kohana_Exception('Invalid target version :version', array(':version' => $version));
	}
}
