<?php
/**
 * This script lists all files in git repository with a flag telling whether there is a pull request open for it or not.
 * If there is PR open for given file, it also tells how many PRs are open & how many changes are proposed (removed &
 * deleted lines).
 */
require_once 'vendor/autoload.php';

define('PER_PAGE', 100);

$username = getenv('GITHUB_USERNAME');
$password = getenv('GITHUB_PASSWORD');
$repository = getenv('GITHUB_REPOSITORY');
if (!empty($repository)) {
    list ($gitHubOrganisation, $gitHubRepository) = explode('/', $repository);
}

if (empty($username) || empty($password) || empty($gitHubOrganisation) || empty($gitHubRepository)) {
    die(
        "No username/password/repository specified." . PHP_EOL .
        "To specify it, run these commands:" . PHP_EOL .
        "  export GITHUB_USERNAME=username" . PHP_EOL .
        "  export GITHUB_PASSWORD=password" . PHP_EOL .
        "  export GITHUB_REPOSITORY=organisation/repository" . PHP_EOL
    );
}

if (empty($argv[1]) || empty($argv[2])) {
    die(
        "Please specify local repository path and branch via command line arguments, e.g.:" . PHP_EOL .
        "  php main.php ../repository-path master > pull_request_stats.txt" . PHP_EOL
    );
}
$localRepoPath = $argv[1];
$localRepoBranch = $argv[2];

$gitFullTree = shell_exec("cd $localRepoPath && git ls-tree --full-tree --name-only -r $localRepoBranch");
$localRepoFiles = explode("\n", $gitFullTree);
// shell_exec returns line-break in the end of the output, so we need to remove last empty row
array_pop($localRepoFiles);

$client = new \Github\Client();
try {
    echo "Authenticating... ";
    $client->authenticate($username, $password);
    echo "OK." . PHP_EOL;
} catch (Exception $ex) {
    die("Oops, exception occurred: " . $ex->getMessage()) . PHP_EOL;
}

/** @var \Github\Api\PullRequest $pullRequestsRepository */
$pullRequestsRepository = $client->api('pull_request');

echo "Fetching pull requests... ";
$page = 1;
$openPullRequests = $pullRequestsRepository->all($gitHubOrganisation, $gitHubRepository, array('per_page' => PER_PAGE, 'page' => $page));
while (count($openPullRequests) == PER_PAGE) {
    $page++;
    $openPullRequestsPage = $pullRequestsRepository->all($gitHubOrganisation, $gitHubRepository, array('per_page' => PER_PAGE, 'page' => $page));
    $openPullRequests = array_merge($openPullRequests, $openPullRequestsPage);
}
echo "Got " . count($openPullRequests) . ' pull requests.' . PHP_EOL;

$modifiedFilesMap = array();

echo "Fetching files..." . PHP_EOL;
foreach ($openPullRequests as $pullRequest) {
    $number = $pullRequest['number'];
    echo " - pull request #$number... ";

    $files = $pullRequestsRepository->files($gitHubOrganisation, $gitHubRepository, $number);
    foreach ($files as $file) {
        $filename = $file['filename'];
        if (!isset($modifiedFilesMap[$filename])) {
            $modifiedFilesMap[$filename] = array('pr_count' => 0, 'changes_count' => 0);
        }
        $modifiedFilesMap[$filename]['pr_count']++;
        $modifiedFilesMap[$filename]['changes_count'] += $file['changes'];
    }
    echo "OK." . PHP_EOL;
}

echo "All done!" . PHP_EOL;
echo PHP_EOL;
echo "Statistics:" . PHP_EOL;
echo "===========" . PHP_EOL;

$filesWithPRCount = 0;
foreach ($localRepoFiles as $localRepoFile) {
    if (isset($modifiedFilesMap[$localRepoFile])) {
        $filesWithPRCount++;
        $stats = $modifiedFilesMap[$localRepoFile];
        $row = array("Y", $localRepoFile, $stats['pr_count'], $stats['changes_count']);
    } else {
        $row = array("N", $localRepoFile);
    }
    echo implode("\t", $row) . PHP_EOL;
}

echo PHP_EOL;
echo "Totals:" . PHP_EOL;
echo "=======" . PHP_EOL;
echo count($localRepoFiles) . " files in repository" . PHP_EOL;
echo $filesWithPRCount . " files modified via PRs" . PHP_EOL;
echo (count($localRepoFiles) - $filesWithPRCount) . " files with no PR" . PHP_EOL;
