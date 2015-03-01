<?php
use TYPO3\Surf\Domain\Model\Workflow;
use TYPO3\Surf\Domain\Model\Node;
use TYPO3\Surf\Domain\Model\SimpleWorkflow;



if(getenv("REPOSITORY_URL") == "") {
	throw new \TYPO3\Surf\Exception\InvalidConfigurationException("EnvVar REPOSITORY_URL is not set!");
} else {
	$envVars['REPOSITORY_URL'] = getenv("REPOSITORY_URL");
}

if(getenv("DOMAIN") == "") {
	throw new \TYPO3\Surf\Exception\InvalidConfigurationException("EnvVar DOMAIN is not set!");
} else {
	$envVars['DOMAIN'] = getenv("DOMAIN");
}

if(getenv("PORT") == "") {
	throw new \TYPO3\Surf\Exception\InvalidConfigurationException("EnvVar PORT is not set!");
} else {
	$envVars['PORT'] = getenv("PORT");
}


$application = new \TYPO3\Surf\Application\TYPO3\Flow($envVars['DOMAIN']);
$application->setDeploymentPath('/data/www/'.$envVars['DOMAIN'].'/surf');
$application->setOption('repositoryUrl', $envVars['REPOSITORY_URL']);
$application->setOption('composerCommandPath', '/usr/local/bin/composer');
$application->setOption('keepReleases', 10);

// Use rsync for transfer instead of composer
$application->setOption('transferMethod', 'rsync');
$application->setOption('packageMethod', 'git');
$application->setOption('updateMethod', NULL);
$application->setOption('rsyncFlags', "--recursive --omit-dir-times --no-perms --links --delete --delete-excluded --exclude '.git'");


$workflow = new \TYPO3\Surf\Domain\Model\SimpleWorkflow();

$workflow->defineTask('sfi.sfi:nogit',
        'typo3.surf:localshell',
        array('command' => 'git config --global url."http://git.typo3.org".insteadOf git://git.typo3.org')
);
$workflow->defineTask('sfi.sfi:beard',
        'typo3.surf:localshell',
        array('command' => 'cd {workspacePath} && git config --global user.email "dimaip@gmail.com" &&  git config --global user.name "Dmitri Pisarev (CircleCI)" && ./beard patch')
);
$workflow->defineTask('sfi.sfi:initialize',
        'typo3.surf:shell',
        array('command' => 'cd {releasePath} && cp Configuration/Production/Settings.yaml Configuration/Settings.yaml && FLOW_CONTEXT=Production ./flow flow:cache:flush --force && chmod g+rwx -R .')
);
$workflow->defineTask('sfi.sfi:clearopcache',
        'typo3.surf:shell',
        array('command' => 'cd {releasePath}/Web && echo "<?php opcache_reset(); ?>" > cc.php && curl "http://next.sfi.ru/cc.php" && rm cc.php')
);
$smokeTestOptions = array(
        'url' => 'http://next.'.$envVars['DOMAIN'],
        'remote' => TRUE,
        'expectedStatus' => 200,
        'expectedRegexp' => '/Page--Main/'
);
$workflow->defineTask('sfi.sfi:smoketest', 'typo3.surf:test:httptest', $smokeTestOptions);

$workflow->beforeStage('package', 'sfi.sfi:nogit', $application);
$workflow->beforeStage('transfer', 'sfi.sfi:beard', $application);
$workflow->addTask('sfi.sfi:initialize', 'migrate', $application);
$workflow->addTask('sfi.sfi:clearopcache', 'test', $application);
$workflow->addTask('sfi.sfi:smoketest', 'test', $application);
$workflow->setEnableRollback(FALSE);


$node = new \TYPO3\Surf\Domain\Model\Node($envVars['DOMAIN']);
$node->setHostname('server.psmb.ru');
$node->setOption('username', 'www');
$node->setOption('port', $envVars['PORT']);
$application->addNode($node);


/** @var \TYPO3\Surf\Domain\Model\Deployment $deployment */
$deployment->setWorkflow($workflow);
$deployment->addApplication($application);

?>
