<?php

declare(strict_types=1);

// 参考 https://github.com/danharrin/monorepo-split-github-action/blob/ac9845270ef47266435b4f124b133a323619e738/src/ConfigFactory.php#L56

exec('git config --global user.name mlzgldr');
exec('git config --global user.email mlzgldr@163.com');

$cloneDirectory = sys_get_temp_dir() . '/monorepo_split/clone_directory';
$buildDirectory = sys_get_temp_dir() . '/monorepo_split/build_directory';
$accessToken = $_SERVER['GITHUB_TOKEN'];
$baseDir = getcwd();
note("Current directory: $baseDir");

$config = [
    ['packages/demo-react-native', 'mlzgldr/demo-react-native'],
    ['packages/demo-react-vite', 'mlzgldr/demo-react-vite'],
    ['packages/demo-taro-react', 'mlzgldr/demo-taro-react'],
];

foreach ($config as $item) {
    $localDirectory = $item[0];
    $remoteDirectory = $item[1];
    $branch = $item[2] ?? 'main';
    chdir($baseDir);
    exec("rm -rf $cloneDirectory");
    exec("rm -rf $buildDirectory");

    $hostRepositoryOrganizationName = "github.com/$remoteDirectory";

    // info
    $clonedRepository = 'https://' . $hostRepositoryOrganizationName;
    $cloningMessage = sprintf('Cloning "%s" repository to "%s" directory', $clonedRepository, $cloneDirectory);
    note($cloningMessage);

    $commandLine = 'git clone -- https://' . $accessToken . '@' . $hostRepositoryOrganizationName . ' ' . $cloneDirectory;
    exec_with_note($commandLine);

    chdir($cloneDirectory);

    exec_with_output_print('git fetch');

    note(sprintf('Trying to checkout %s branch', $branch));

    // if the given branch doesn't exist it returns empty string
    $branchSwitchedSuccessfully = exec_with_note(sprintf('git checkout %s', $branch)) !== '';

    // if the branch doesn't exist we creat it and push to origin
    // otherwise we just checkout to the given branch
    if (!$branchSwitchedSuccessfully) {
        note(sprintf('Creating branch "%s" as it doesn\'t exist', $branch));

        exec_with_output_print(sprintf('git checkout -b %s', $branch));
        exec_with_output_print(sprintf('git push --quiet origin %s', $branch));
    }

    chdir($baseDir);

    note('Cleaning destination repository of old files');
    // We're only interested in the .git directory, move it to $TARGET_DIR and use it from now on
    mkdir($buildDirectory . '/.git', 0777, true);

    $copyGitDirectoryCommandLine = sprintf('cp -r %s %s', $cloneDirectory . '/.git', $buildDirectory);
    exec($copyGitDirectoryCommandLine, $outputLines, $exitCode);

    if ($exitCode === 1) {
        die('Command failed');
    }

    // cleanup old unused data to avoid pushing them
    exec('rm -rf ' . $cloneDirectory);
    // exec('rm -rf .git');

    // copy the package directory including all hidden files to the clone dir
    // make sure the source dir ends with `/.` so that all contents are copied (including .github etc)
    $copyMessage = sprintf('Copying contents to git repo of "%s" branch', $_SERVER['GITHUB_SHA']);
    note($copyMessage);
    $commandLine = sprintf('cp -ra %s %s', $localDirectory . '/.', $buildDirectory);
    exec_with_note($commandLine);

    note('Files that will be pushed');
    list_directory_files($buildDirectory);


    // WARNING! this function happen before we change directory
    // if we do this in split repository, the original hash is missing there and it will fail
    $commitMessage = createCommitMessage($_SERVER['GITHUB_SHA']);


    $formerWorkingDirectory = getcwd();
    chdir($buildDirectory);

    $restoreChdirMessage = sprintf('Changing directory from "%s" to "%s"', $formerWorkingDirectory, $buildDirectory);
    note($restoreChdirMessage);


    // avoids doing the git commit failing if there are no changes to be commit, see https://stackoverflow.com/a/8123841/1348344
    exec_with_output_print('git status');

    // "status --porcelain" retrieves all modified files, no matter if they are newly created or not,
    // when "diff-index --quiet HEAD" only checks files that were already present in the project.
    exec('git status --porcelain', $changedFiles);

    // $changedFiles is an array that contains the list of modified files, and is empty if there are no changes.

    if ($changedFiles) {
        note('Adding git commit');

        exec_with_output_print('git add .');

        $message = sprintf('Pushing git commit with "%s" message to "%s"', $commitMessage, $branch);
        note($message);

        exec("git commit --message '{$commitMessage}'");
        exec('git push --quiet origin ' . $branch);
    } else {
        note('No files to change');
    }

    // restore original directory to avoid nesting WTFs
    chdir($formerWorkingDirectory);
    $chdirMessage = sprintf('Changing directory from "%s" to "%s"', $buildDirectory, $formerWorkingDirectory);
    note($chdirMessage);
}

function createCommitMessage(string $commitSha): string
{
    exec("git show -s --format=%B {$commitSha}", $outputLines);
    return $outputLines[0] ?? '';
}

function note(string $message): void
{
    echo PHP_EOL . "\033[0;33m[NOTE] " . $message . "\033[0m" . PHP_EOL;
}

function error(string $message): void
{
    echo PHP_EOL . "\033[0;31m[ERROR] " . $message . "\033[0m" . PHP_EOL;
}


function list_directory_files(string $directory): void
{
    exec_with_output_print('ls -la ' . $directory);
}


/********************* helper functions *********************/

function exec_with_note(string $commandLine): void
{
    note('Running: ' . $commandLine);
    exec($commandLine);
}


function exec_with_output_print(string $commandLine): void
{
    exec($commandLine, $outputLines);
    echo implode(PHP_EOL, $outputLines);
}