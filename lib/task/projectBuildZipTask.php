<?php

class projectBuildZipTask extends sfBaseTask
{

  protected $verbose=false;
  protected $reopen_interval=200; // see http://bugs.php.net/bug.php?id=40494
  protected $count=0;
  protected $path;
  protected $excludes=Array(); // list of file to exclude

  protected function configure()
  {
    $this->namespace        = 'project';
    $this->name             = 'build-zip';
    $this->briefDescription = 'Creates a zipfile of a release';
    $this->detailedDescription = <<<EOF
The [project:build-zip|INFO] task makes a ready to go zipfile.
EOF;
    $this->addArgument('file', sfCommandArgument::REQUIRED, 'The path to the zipfile',null);
    $this->addOption('verbose', null, sfCommandOption::PARAMETER_REQUIRED, 'Enables verbose output', false);
    
  }

  protected function execute($arguments = array(), $options = array())
  {
    if(!class_exists('ZipArchive')) throw new sfException('Class ZipArchive not found');

    $this->zip=new ZipArchive();

    $this->path=$arguments['file'];
    $this->verbose=$options['verbose'];

    $this->log("Saving zip to $this->path, please wait",true);

    if(file_exists($this->path))
        throw new sfException("Zip exists. Won't overwrite.");

    $code=$this->zip->open($this->path,ZipArchive::CREATE); // this was having trouble accepting and &-ed mask
    if($code!==TRUE)
    {
      $e=$this->error2string($code);
      throw new sfException("Can't make a zip -- $code $e");
    }
    $this->root=realpath(dirname(__FILE__).'/../..');
    $this->generateExcludes($this->root);
    $this->processFiles($this->root);

    if($this->zip->close()!==TRUE)
      throw new sfException('Saving zip failed');
    $this->log("Saved zip to $this->path",true);
  }
  protected function processFiles($path)
  {
    $files=glob($path."/{,.}*",GLOB_BRACE);
    foreach($files as $file)
    {
      $is_ok=true;

      if(preg_match('#/\.\.?$#',$file)) // special directory entries
      {
        $is_ok = false;
      }
      else
      {
        $short=preg_replace("#^$this->root/#",'',$file);  
      }

      if($is_ok) foreach($this->excludes as $exclude)
      {
        if(fnmatch("$this->root/$exclude",$file))
        {
          $this->log("Ignore ". (is_dir($file)?'dir ':'file') ." $short \t\t[$exclude]");
          $is_ok=false;
          break;
        }
      }

      // for files that need to avoid exclude patterns -- only one so far
      if(!$is_ok) if(preg_match('#/.htaccess$#',$file))
      {
        $this->log("Butnot ". (is_dir($file)?'dir ':'file') ." $short");
        $is_ok=true;
      }

      if($is_ok)
      {

        if(++$this->count%$this->reopen_interval==$this->reopen_interval-1)
        {
          $this->reopen(); // to stop annoying file handle exhaustion bug
        }

        $is_dist=preg_match('#\.dist$#',$file)==1;
        if($is_dist)
        {
          $short=preg_replace('#\.dist$#','',$short);
        }

        if(is_dir($file))
        {
          $this->log("Adding dir  $short"); // double space makes it line up
          if($is_dist)
            throw new sfException("Cannot use .dist suffixed directories yet");
          $this->zip->addEmptyDir($short);
          $this->processFiles($file);
        }
        else
        {
          $this->log("Adding file $short".($is_dist?" from $short.dist":''));
          
          $entry_exists=$this->zip->locateName($short)!==FALSE;


          if($entry_exists)
          {
            if($is_dist)
            {
              $this->log("$short already exists -- replaced with $short.dist.",true);
            }
            else
            {
              throw new sfException("$short already exists in archive!");
            }
          }

          if($this->zip->addFile($file,$short)==FALSE)
            throw new sfException("Couldn't add -- probably too many open files");
        }
      }
    }
  }
  protected function generateExcludes($path)
  {
    $excludes=$this->excludes;

    $contents=file_get_contents("$path/.gitignore");
    if($contents!==FALSE)
    {
      foreach(split("\n",$contents) as $line)
      {
        if(preg_match('/^.*add2zip/',$line)) // lines below "add2zip" are removed from exlude array
          break;
        else
          $excludes[]=$line;
      }
    }
    else
    {
      $this->log('WARNING: no .gitignore; building on Windows is not such a good idea right now…',true);
    }

    $this->excludes=$excludes;
  }

  public function log($v,$force=false)
  {
    if($force||$this->verbose)
      parent::log($v);
  }

  protected function reopen()
  {
    $this->log('Reopening…');
    if($this->zip->close()!==TRUE)
        throw new sfException('Closing zip for reopen failed');
    if($code = $this->zip->open($this->path)!=TRUE)
    throw new sfException('Reopening zip for failed -- '.$this->error2string($code));
  }
  protected function error2string($code)
  {
    switch($code)
    {
        case ZIPARCHIVE::ER_EXISTS:
        $e="exists"; // should never happen since Overwrite is set
        break;
        case ZIPARCHIVE::ER_INCONS:
        $e="incos";
        break;
        case ZIPARCHIVE::ER_INVAL:
        $e="inval";
        break;
        case ZIPARCHIVE::ER_MEMORY:
        $e="mem";
        break;
        case ZIPARCHIVE::ER_NOENT:
        $e="noent";
        break;
        case ZIPARCHIVE::ER_NOZIP:
        $e="nozip";
        break;
        case ZIPARCHIVE::ER_OPEN:
        $e="open";
        break;
        case ZIPARCHIVE::ER_READ:
        $e="read";
        break;
        case ZIPARCHIVE::ER_SEEK:
        $e="seek";
        break;
        default:
        $e="unknown";
    }
    return $e;
  }
}
