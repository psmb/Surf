<?php
use TYPO3\Surf\Domain\Model\Workflow;
use TYPO3\Surf\Domain\Model\Node;
use TYPO3\Surf\Domain\Model\SimpleWorkflow;

// Distribution repository url
if(getenv("REPOSITORY_URL") == "") {
	throw new \TYPO3\Surf\Exception\InvalidConfigurationException("EnvVar REPOSITORY_URL is not set!");
} else {
	$envVars['REPOSITORY_URL'] = getenv("REPOSITORY_URL");
}

// Domain name, used in various places
if(getenv("DOMAIN") == "") {
	throw new \TYPO3\Surf\Exception\InvalidConfigurationException("EnvVar DOMAIN is not set!");
} else {
	$envVars['DOMAIN'] = getenv("DOMAIN");
}

// Ssh port of docker image
if(getenv("PORT") == "") {
	throw new \TYPO3\Surf\Exception\InvalidConfigurationException("EnvVar PORT is not set!");
} else {
	$envVars['PORT'] = getenv("PORT");
}

// Build CSS and JS of Neos. Useful after bearding changes that touch CSS or JS
$envVars['BUILD_NEOS'] = getenv("BUILD_NEOS");


$application = new \TYPO3\Surf\Application\TYPO3\Flow($envVars['DOMAIN']);
$application->setVersion('3.0');
$application->setDeploymentPath('/data/www/'.$envVars['DOMAIN'].'/surf');
$application->setOption('repositoryUrl', $envVars['REPOSITORY_URL']);
$application->setOption('composerCommandPath', '/usr/local/bin/composer');
$application->setOption('keepReleases', 10);
// Use rsync for transfer instead of composer
$application->setOption('transferMethod', 'rsync');
$application->setOption('packageMethod', 'git');
$application->setOption('updateMethod', NULL);
$application->setOption('baseUrl', 'http://' . $envVars['DOMAIN']);
$application->setOption('rsyncFlags', "--recursive --omit-dir-times --no-perms --links --delete --delete-excluded");


$workflow = new \TYPO3\Surf\Domain\Model\SimpleWorkflow();
$workflow->setEnableRollback(FALSE);

// Pull from Gerrit mirror instead of git.typo3.org (temporary fix)
$workflow->defineTask('sfi.sfi:nogit',
        'typo3.surf:localshell',
        array('command' => 'git config --global url."http://git.typo3.org".insteadOf git://git.typo3.org')
);
// Apply patches with Beard
$workflow->defineTask('sfi.sfi:beard',
        'typo3.surf:localshell',
        array('command' => 'cd {workspacePath} && git config --global user.email "dimaip@gmail.com" &&  git config --global user.name "Dmitri Pisarev (CircleCI)" && ./beard patch')
);
// Build Neos CSS and JS
$workflow->defineTask('sfi.sfi:buildneos',
        'typo3.surf:localshell',
        array('command' => 'cd {workspacePath}/Packages/Application/TYPO3.Neos/Scripts/ && sh install-dev-tools.sh && npm install -g grunt-cli && grunt build')
);
// Run build.sh
$workflow->defineTask('sfi.sfi:buildscript',
        'typo3.surf:shell',
        array('command' => 'cd {releasePath} && sh build.sh')
);
// Simple smoke test
$smokeTestOptions = array(
        'url' => 'http://next.'.$envVars['DOMAIN'],
        'remote' => FALSE,
        'port' => 80,
        'expectedStatus' => 200,
        'expectedRegexp' => '/This website is powered by/'
);
$workflow->defineTask('sfi.sfi:smoketest', 'typo3.surf:test:httptest', $smokeTestOptions);
// Clearing opcode cache. More info here: http://codinghobo.com/opcache-and-symlink-based-deployments/		
$workflow->defineTask('sfi.sfi:clearopcache',		
        'typo3.surf:shell',		
        array('command' => 'cd {currentPath}/Web && echo "<?php opcache_reset(); echo \"cache cleared\";" > cc.php && curl "http://' . $envVars['DOMAIN'] . '/cc.php" && rm cc.php && cd .. && FLOW_CONTEXT=Production ./flow flow:cache:flush --force && FLOW_CONTEXT=Production ./flow cache:warmup')		
);

$workflow->beforeStage('package', 'sfi.sfi:nogit', $application);
$workflow->beforeStage('transfer', 'sfi.sfi:beard', $application);
if ($envVars['BUILD_NEOS']) {
	$workflow->beforeStage('transfer', 'sfi.sfi:buildneos', $application);
}
$workflow->addTask('sfi.sfi:smoketest', 'test', $application);
$workflow->afterStage('switch', 'sfi.sfi:clearopcache', $application);
// Caches are cleated in the build script, and that should happen after opcache clear, or images wouldn't get rendered
$workflow->afterStage('switch', 'sfi.sfi:buildscript', $application);

$node = new \TYPO3\Surf\Domain\Model\Node($envVars['DOMAIN']);
$node->setHostname('server.psmb.ru');
$node->setOption('username', 'www');
$node->setOption('port', $envVars['PORT']);
$application->addNode($node);


/** @var \TYPO3\Surf\Domain\Model\Deployment $deployment */
$deployment->setWorkflow($workflow);
$deployment->addApplication($application);

?>
