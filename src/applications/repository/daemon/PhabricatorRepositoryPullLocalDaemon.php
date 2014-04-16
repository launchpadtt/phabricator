<?php

/**
 * Run pull commands on local working copies to keep them up to date. This
 * daemon handles all repository types.
 *
 * By default, the daemon pulls **every** repository. If you want it to be
 * responsible for only some repositories, you can launch it with a list of
 * PHIDs or callsigns:
 *
 *   ./phd launch repositorypulllocal -- X Q Z
 *
 * You can also launch a daemon which is responsible for all //but// one or
 * more repositories:
 *
 *   ./phd launch repositorypulllocal -- --not A --not B
 *
 * If you have a very large number of repositories and some aren't being pulled
 * as frequently as you'd like, you can either change the pull frequency of
 * the less-important repositories to a larger number (so the daemon will skip
 * them more often) or launch one daemon for all the less-important repositories
 * and one for the more important repositories (or one for each more important
 * repository).
 *
 * @task pull   Pulling Repositories
 * @task git    Git Implementation
 * @task hg     Mercurial Implementation
 */
final class PhabricatorRepositoryPullLocalDaemon
  extends PhabricatorDaemon {


/* -(  Pulling Repositories  )----------------------------------------------- */


  /**
   * @task pull
   */
  public function run() {
    $argv = $this->getArgv();
    array_unshift($argv, __CLASS__);
    $args = new PhutilArgumentParser($argv);
    $args->parse(
      array(
        array(
          'name'      => 'no-discovery',
          'help'      => 'Pull only, without discovering commits.',
        ),
        array(
          'name'      => 'not',
          'param'     => 'repository',
          'repeat'    => true,
          'help'      => 'Do not pull __repository__.',
        ),
        array(
          'name'      => 'repositories',
          'wildcard'  => true,
          'help'      => 'Pull specific __repositories__ instead of all.',
        ),
      ));

    $no_discovery   = $args->getArg('no-discovery');
    $repo_names     = $args->getArg('repositories');
    $exclude_names  = $args->getArg('not');

    // Each repository has an individual pull frequency; after we pull it,
    // wait that long to pull it again. When we start up, try to pull everything
    // serially.
    $retry_after = array();

    $min_sleep = 15;

    while (true) {
      $repositories = $this->loadRepositories($repo_names);
      if ($exclude_names) {
        $exclude = $this->loadRepositories($exclude_names);
        $repositories = array_diff_key($repositories, $exclude);
      }

      // Shuffle the repositories, then re-key the array since shuffle()
      // discards keys. This is mostly for startup, we'll use soft priorities
      // later.
      shuffle($repositories);
      $repositories = mpull($repositories, null, 'getID');

      // If any repositories have the NEEDS_UPDATE flag set, pull them
      // as soon as possible.
      $need_update_messages = $this->loadRepositoryUpdateMessages();
      foreach ($need_update_messages as $message) {
        $retry_after[$message->getRepositoryID()] = time();
      }

      // If any repositories were deleted, remove them from the retry timer map
      // so we don't end up with a retry timer that never gets updated and
      // causes us to sleep for the minimum amount of time.
      $retry_after = array_select_keys(
        $retry_after,
        array_keys($repositories));

      // Assign soft priorities to repositories based on how frequently they
      // should pull again.
      asort($retry_after);
      $repositories = array_select_keys(
        $repositories,
        array_keys($retry_after)) + $repositories;

      foreach ($repositories as $id => $repository) {
        $after = idx($retry_after, $id, 0);
        if ($after > time()) {
          continue;
        }

        $tracked = $repository->isTracked();
        if (!$tracked) {
          continue;
        }

        $callsign = $repository->getCallsign();

        try {
          $this->log("Updating repository '{$callsign}'.");

          $bin_dir = dirname(phutil_get_library_root('phabricator')).'/bin';
          $flags = array();
          if ($no_discovery) {
            $flags[] = '--no-discovery';
          }

          list($stdout, $stderr) = execx(
            '%s/repository update %Ls -- %s',
            $bin_dir,
            $flags,
            $callsign);

          if (strlen($stderr)) {
            $stderr_msg = pht(
              'Unexpected output while updating the %s repository: %s',
              $callsign,
              $stderr);
            phlog($stderr_msg);
          }

          $sleep_for = $repository->getDetail('pull-frequency', $min_sleep);
          $retry_after[$id] = time() + $sleep_for;
        } catch (Exception $ex) {
          $retry_after[$id] = time() + $min_sleep;

          $proxy = new PhutilProxyException(
            "Error while fetching changes to the '{$callsign}' repository.",
            $ex);
          phlog($proxy);
        }

        $this->stillWorking();
      }

      if ($retry_after) {
        $sleep_until = max(min($retry_after), time() + $min_sleep);
      } else {
        $sleep_until = time() + $min_sleep;
      }

      while (($sleep_until - time()) > 0) {
        $this->sleep(1);
        if ($this->loadRepositoryUpdateMessages()) {
          break;
        }
      }
    }
  }

  private function loadRepositoryUpdateMessages() {
    $type_need_update = PhabricatorRepositoryStatusMessage::TYPE_NEEDS_UPDATE;
    return id(new PhabricatorRepositoryStatusMessage())
      ->loadAllWhere('statusType = %s', $type_need_update);
  }

  /**
   * @task pull
   */
  protected function loadRepositories(array $names) {
    $query = id(new PhabricatorRepositoryQuery())
      ->setViewer($this->getViewer());

    if ($names) {
      $query->withCallsigns($names);
    }

    $repos = $query->execute();

    if ($names) {
      $by_callsign = mpull($repos, null, 'getCallsign');
      foreach ($names as $name) {
        if (empty($by_callsign[$name])) {
          throw new Exception(
            "No repository exists with callsign '{$name}'!");
        }
      }
    }

    return $repos;
  }

}
