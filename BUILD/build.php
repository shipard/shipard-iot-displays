#!/usr/bin/env php
<?php


function parseArgs($argv)
{
	array_shift ($argv);
	$out = array();
	foreach ($argv as $arg){
		if (substr($arg,0,2) == '--'){
			$eqPos = strpos($arg,'=');
			if ($eqPos === false){
				$key = substr($arg,2);
				$out[$key] = isset($out[$key]) ? $out[$key] : true;
			} else {
				$key = substr($arg,2,$eqPos-2);
				$out[$key] = substr($arg,$eqPos+1);
			}
		} else if (substr($arg,0,1) == '-'){
			if (substr($arg,2,1) == '='){
				$key = substr($arg,1,1);
				$out[$key] = substr($arg,3);
			} else {
				$chars = str_split(substr($arg,1));
				foreach ($chars as $char){
					$key = $char;
					$out[$key] = isset($out[$key]) ? $out[$key] : true;
				}
			}
		} else {
			$out[] = $arg;
		}
	}
	return $out;
}

/**
 * Class BuildApp
 */
class BuildApp
{
	var $arguments = NULL;
	var $projectsCfg = NULL;
	var $localCfg = NULL;

	var $buildChannel = 'stable';
	var $buildCommit = '';
	var $buildVersionId = '';
	var $fwFolderRoot = '';
	var $fwFolder = '';

	var $fwProjects =[];

	var $anyCfgError = TRUE;

	public function __construct($argv)
	{
		if ($argv)
			$this->arguments = parseArgs($argv);

		$this->projectsCfg = $this->loadCfgFile('projects.json');
		if (!$this->projectsCfg)
		{
			$this->err("File projects.json not found or has syntax error!");
			return;
		}

		$this->localCfg = $this->loadCfgFile('local-config.json');
		if (!$this->localCfg)
		{
			$this->err("File local-config.json not found or has syntax error!");
			return;
		}

		$this->buildChannel = 'stable';
		$this->buildCommit = shell_exec("git log --pretty=format:'%h' -n 1");


		if (!is_dir('logs'))
			mkdir('logs');

		$this->fwFolderRoot = 'fw/'.$this->buildChannel;
		$this->fwFolder = $this->fwFolderRoot.'/'.$this->buildVersionId;

		$this->anyCfgError = FALSE;
	}

	public function arg ($name, $defaultValue = FALSE)
	{
		if (isset ($this->arguments [$name]))
			return $this->arguments [$name];

		return $defaultValue;
	}

	public function loadCfgFile ($fileName)
	{
		if (is_file ($fileName))
		{
			$cfgString = file_get_contents ($fileName);
			if (!$cfgString)
				return FALSE;
			$cfg = json_decode ($cfgString, true);
			if (!$cfg)
				return FALSE;
			return $cfg;
		}
		return FALSE;
	}

	public function command ($idx = 0)
	{
		if (isset ($this->arguments [$idx]))
			return $this->arguments [$idx];

		return "";
	}

	public function err ($msg)
	{
		if ($msg === FALSE)
			return TRUE;

		if (is_array($msg))
		{
			if (count($msg) !== 0)
			{
				forEach ($msg as $m)
					echo ("! " . $m['text']."\n");
				return FALSE;
			}
			return TRUE;
		}

		echo ("ERROR: ".$msg."\n");
		return FALSE;
	}

	function buildProject ($displayVendorId, $projectId)
	{
		$orientations = ['0', '90', '180', '270'];
		$now = new \DateTime();

		$srcPath = '../'.$displayVendorId.'/projects/'.$projectId;

		echo "  –> ".$projectId;

		$versionCfg = $this->loadCfgFile($srcPath.'/version.json');
		$versionId = $versionCfg['version'].'-'.$this->buildCommit;
		echo ' '.$versionId.' ';

		$fwFiles = ['version' => $versionId,
			'timestamp' => $now->format('Y-m-d H:i:s'),
			'files' => []
		];

		echo "\n";

		$projectDstPath = $this->fwFolder.'/'.$displayVendorId.'/'.$projectId.'/';
		$dstPath = $projectDstPath.'/'.$versionId;
		if (!is_dir($dstPath))
			mkdir($dstPath, 0700, TRUE);

		foreach ($this->projectsCfg['displays-vendors'][$displayVendorId]['projects'][$projectId]['displays'] as $displayId)
		{
			echo "     - ".$displayId.' ';

			$coreFileName = $projectId.'-'.$displayId;
			foreach ($orientations as $ori)
			{
				$srcFileName = $srcPath.'/'.$coreFileName.'-'.$ori.'.tft';
				$dstBaseFileName = $coreFileName.'-'.$ori.'-'.$versionId.'.tft';
				$dstFileName = $dstPath.'/'.$dstBaseFileName;
				copy($srcFileName, $dstFileName);

				$checkSum = sha1_file($dstFileName);
				$fileSize = filesize($dstFileName);
				echo $ori.': '.$fileSize.'B; ';

				$fwFiles['files'][] = [
					'fileName' => $dstBaseFileName, 'size' => $fileSize, 'sha1' => $checkSum
				];
			}
			echo "\n";
		}

		$versionInfo = [
			'version' => $versionId,
			'timestamp' => $now->format('Y-m-d H:i:s'),
		];
		file_put_contents($projectDstPath.'/version.json', json_encode($versionInfo, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
		file_put_contents($projectDstPath.'/VERSION',$versionId);
		file_put_contents($dstPath.'/files.json', json_encode($fwFiles, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));

		$this->fwProjects[$projectId] = [
			'version' => $versionId,
			'timestamp' => $now->format('Y-m-d H:i:s'),
		];
	}

	function buildAll()
	{
		if ($this->anyCfgError)
			return FALSE;

		array_map ('unlink', glob ('logs/*'));
		exec ('rm -rf '.$this->fwFolderRoot);
		if (!is_dir($this->fwFolder))
			mkdir($this->fwFolder, 0700, TRUE);

		foreach ($this->projectsCfg['displays-vendors'] as $displayVendorId => $displayVendorCfg)
		{
			echo "# ".$displayVendorId."\n";
			foreach ($displayVendorCfg['projects'] as $projectId => $projectCfg)
			{
				if (!is_dir($this->fwFolder.'/'.$displayVendorId))
					mkdir($this->fwFolder.'/'.$displayVendorId, 0700, TRUE);
				$this->buildProject($displayVendorId, $projectId);
			}
			file_put_contents($this->fwFolder.'/'.$displayVendorId.'/projects.json', json_encode($this->fwProjects, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
			$this->fwProjects = [];
		}



		$doUpload = $this->arg('upload');
		if ($doUpload === TRUE)
			$this->upload();

		return TRUE;
	}

	function upload()
	{
		echo "--- UPLOAD ---\n";
		$remoteUser = $this->localCfg['remoteUser'];
		$remoteServer = $this->localCfg['remoteServer'];

		foreach ($this->projectsCfg['displays-vendors'] as $displayVendorId => $displayVendorCfg)
		{
			echo "# " . $displayVendorId . "\n";

			$remoteDir = '/var/www/webs/download.shipard.org/shipard-iot/fw/'.$displayVendorId.'/';

			foreach ($displayVendorCfg['projects'] as $projectId => $projectCfg)
			{
				$projectSrcPath = $this->fwFolder.$displayVendorId.'/'.$projectId.'/';
				$projectVersionCfg = $this->loadCfgFile($projectSrcPath.'version.json');
				$versionId = $projectVersionCfg['version'];
				$srcPath = $projectSrcPath.$versionId;

				$projectDstPathCore = $remoteDir.$this->buildChannel . '/' . $projectId.'/';
				$projectDstPath = $projectDstPathCore.$versionId;
				$uploadCmd = "ssh -l {$remoteUser} {$remoteServer} mkdir -p $projectDstPath";
				//echo $uploadCmd."\n";
				passthru($uploadCmd);

				// -- fw files
				$uploadCmd = "scp " . $srcPath . "/* {$remoteUser}@{$remoteServer}:$projectDstPath";
				echo $uploadCmd."\n";
				passthru($uploadCmd);
				// -- version info
				$uploadCmd = "scp " . $projectSrcPath . "*.json {$remoteUser}@{$remoteServer}:$projectDstPathCore";
				echo $uploadCmd."\n";
				passthru($uploadCmd);
			}


				$uploadCmd = "scp ".$this->fwFolder.$displayVendorId.'/projects.json'." {$remoteUser}@{$remoteServer}:/$remoteDir/".$this->buildChannel.'/';
				echo $uploadCmd."\n";
				passthru($uploadCmd);
		}
		echo "--- DONE ---\n";
	}

	public function run ()
	{
		switch ($this->command ())
		{
			case	'build-all':     				return $this->buildAll();
		}
		echo ("unknown or nothing param....\n");
		echo (" * build-all [--upload]\n");
		return FALSE;
	}
}

$myApp = new BuildApp ($argv);
$myApp->run ();
